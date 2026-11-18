<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/**
 * Joining by in-game confirmation.
 *
 * 1. The member's companion creates an Ed25519 key pair; only the public key
 *    reaches the game (addon file Claim.lua) and is sent on the guild addon
 *    channel. Blizzard's server sets the sender name; it cannot be forged.
 * 2. A bank manager's addon checks the sender against the real guild roster and
 *    keeps {public key -> character}. The manager's companion uploads it with
 *    the manager's bridge token; only officers, bank managers and admins count.
 * 3. The member's companion proves the private key by signing a fresh server
 *    nonce together with this site's origin and receives its own bridge token
 *    and, for a new account, a one-time link to set e-mail and password.
 */
final class Claims {
    private const KEY='/^[a-f0-9]{64}$/D';
    public const PLACEHOLDER_DOMAIN='@claim.invalid';

    public function __construct(private readonly PDO $db) {}

    /** Manager upload: BanklordDB.claims = { [publicKey] = {character, guild, guildRealm, seen} }. */
    public function record(int $guild, int $actor, string $savedVars, array $record): int {
        $r=$this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'");
        $r->execute([$guild,$actor]);
        if (!in_array($r->fetchColumn(),['officer','bank_manager','admin'],true)) return 0;
        try { $data=(new SavedVariablesParser($savedVars))->parse(); } catch (\Throwable $e) { return 0; }
        $claims=$data['claims']??[];
        if (!is_array($claims)) return 0;
        $ins=$this->db->prepare('INSERT IGNORE INTO member_claims (guild_id,public_key,char_name,char_realm,approved_by) VALUES (?,?,?,?,?)');
        $n=0;
        foreach (array_slice($claims,0,50,true) as $key=>$row) {
            if (!is_string($key) || !preg_match(self::KEY,$key) || !is_array($row)) continue;
            $seen=$row['seen']??0;
            if (!is_int($seen) || $seen<time()-14*86400 || $seen>time()+86400) continue;
            $tag=[(string)($row['guild']??''),(string)($row['guildRealm']??'')];
            if ($tag[0]==='' || !IngameOrders::forGuild($tag,$record)) continue;
            [$name,$realm]=array_pad(explode('-',(string)($row['character']??''),2),2,'');
            if (!Community::characterAllowed($name) || mb_strlen($realm)<2 || mb_strlen($realm)>100) continue;
            $ins->execute([$guild,$key,$name,$realm,$actor]);$n+=$ins->rowCount();
        }
        return $n;
    }

    /** Fresh nonce for a key plus the guilds that already confirmed it. */
    public function challenge(string $key): array {
        if (!preg_match(self::KEY,$key)) throw new DomainError('INVALID_KEY');
        $s=$this->db->prepare('SELECT c.guild_id,g.name FROM member_claims c JOIN guilds g ON g.id=c.guild_id WHERE c.public_key=? ORDER BY c.id');
        $s->execute([$key]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['nonce'=>null,'guilds'=>[]];
        $nonce=bin2hex(random_bytes(32));
        $this->db->prepare('UPDATE member_claims SET nonce=?,nonce_expires=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE public_key=?')->execute([$nonce,$key]);
        return ['nonce'=>$nonce,'guilds'=>array_map(fn($r)=>['guild_id'=>(int)$r['guild_id'],'name'=>(string)$r['name']],$rows)];
    }

    public static function message(string $origin, string $nonce): string { return "banklord-claim|$origin|$nonce"; }

    /**
     * Signature over (this site's origin, current nonce) proves the private key.
     * @return list<array{guild_id:int,guild:string,token:string,welcome:?string}>
     */
    public function redeem(string $key, string $signature, string $origin): array {
        if (!preg_match(self::KEY,$key) || !preg_match('/^[a-f0-9]{128}$/D',$signature)) throw new DomainError('INVALID_CLAIM');
        $s=$this->db->prepare('SELECT nonce FROM member_claims WHERE public_key=? AND nonce IS NOT NULL AND nonce_expires>UTC_TIMESTAMP() LIMIT 1');
        $s->execute([$key]);$nonce=$s->fetchColumn();
        if (!is_string($nonce) || !sodium_crypto_sign_verify_detached(hex2bin($signature),self::message($origin,$nonce),hex2bin($key))) {
            throw new DomainError('CLAIM_SIGNATURE_INVALID');
        }
        $this->db->beginTransaction();
        try {
            // One nonce, one redemption.
            $this->db->prepare('UPDATE member_claims SET nonce=NULL,nonce_expires=NULL WHERE public_key=?')->execute([$key]);
            $s=$this->db->prepare('SELECT c.*,g.name AS guild_name FROM member_claims c JOIN guilds g ON g.id=c.guild_id WHERE c.public_key=? FOR UPDATE');
            $s->execute([$key]);$out=[];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) $out[]=$this->grant($c,$origin);
            $this->db->commit();
            return $out;
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    private function grant(array $c, string $origin): array {
        $guild=(int)$c['guild_id'];$id=(int)$c['id'];
        $user=$c['user_id']!==null?(int)$c['user_id']:null;
        if ($user===null) {
            $f=$this->db->prepare('SELECT user_id FROM characters WHERE guild_id=? AND name=? AND realm=?');
            $f->execute([$guild,$c['char_name'],$c['char_realm']]);$found=$f->fetchColumn();
            $user=$found!==false?(int)$found:null;
        }
        if ($user===null) {
            $this->db->prepare('INSERT INTO users (email,password_hash,display_name) VALUES (?,?,?)')
                ->execute(['claim-'.$id.'-'.bin2hex(random_bytes(6)).self::PLACEHOLDER_DOMAIN,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$c['char_name']]);
            $user=(int)$this->db->lastInsertId();
        }
        $this->db->prepare("INSERT IGNORE INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,'member')")->execute([$guild,$user]);
        $m=$this->db->prepare('SELECT status FROM guild_memberships WHERE guild_id=? AND user_id=?');$m->execute([$guild,$user]);
        if ($m->fetchColumn()!=='active') throw new DomainError('MEMBERSHIP_DISABLED');
        // The manager saw this character in the real guild roster.
        $this->db->prepare("INSERT INTO characters (guild_id,user_id,name,realm,verification_status) VALUES (?,?,?,?,'verified')
            ON DUPLICATE KEY UPDATE verification_status=IF(user_id=VALUES(user_id),'verified',verification_status)")->execute([$guild,$user,$c['char_name'],$c['char_realm']]);
        $this->db->prepare("UPDATE bridge_tokens SET revoked_at=UTC_TIMESTAMP() WHERE guild_id=? AND created_by=? AND label='Companion (Spiel)' AND revoked_at IS NULL")->execute([$guild,$user]);
        $token='bl1_'.bin2hex(random_bytes(32));
        $this->db->prepare("INSERT INTO bridge_tokens (guild_id,created_by,label,token_hash,scope,expires_at) VALUES (?,?,'Companion (Spiel)',?,'member.orders',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 DAY))")
            ->execute([$guild,$user,hash('sha256',$token)]);
        $welcome=null;
        $e=$this->db->prepare('SELECT email FROM users WHERE id=?');$e->execute([$user]);
        if (str_ends_with((string)$e->fetchColumn(),self::PLACEHOLDER_DOMAIN)) {
            $plain=bin2hex(random_bytes(32));
            $this->db->prepare('UPDATE member_claims SET welcome_hash=?,welcome_expires=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY) WHERE id=?')->execute([hash('sha256',$plain),$id]);
            $welcome=$origin.'/#welcome='.$plain;
        }
        $this->db->prepare('UPDATE member_claims SET user_id=?,redeemed_at=UTC_TIMESTAMP() WHERE id=?')->execute([$user,$id]);
        $this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id) VALUES (?,?,'member.claim.redeemed','member_claim',?)")->execute([$guild,$user,$id]);
        return ['guild_id'=>$guild,'guild'=>(string)$c['guild_name'],'token'=>$token,'welcome'=>$welcome];
    }

    /** One-time welcome link: the new member sets e-mail and password. Returns the user id. */
    public function complete(string $plain, string $email, string $password, string $name): int {
        if (!preg_match('/^[a-f0-9]{64}$/D',$plain)) throw new DomainError('WELCOME_INVALID');
        $email=mb_strtolower(trim($email));$name=trim($name);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254 || str_ends_with($email,self::PLACEHOLDER_DOMAIN)) throw new DomainError('INVALID_EMAIL');
        if (strlen($password)<14 || strlen($password)>1024) throw new DomainError('WEAK_PASSWORD');
        if ($name==='' || mb_strlen($name)>100) throw new DomainError('INVALID_NAME');
        $this->db->beginTransaction();
        try {
            $s=$this->db->prepare('SELECT id,user_id FROM member_claims WHERE welcome_hash=? AND welcome_expires>UTC_TIMESTAMP() FOR UPDATE');
            $s->execute([hash('sha256',$plain)]);$c=$s->fetch(PDO::FETCH_ASSOC);
            if (!$c || $c['user_id']===null) throw new DomainError('WELCOME_INVALID');
            $taken=$this->db->prepare('SELECT COUNT(*) FROM users WHERE email=?');$taken->execute([$email]);
            if ((int)$taken->fetchColumn()>0) throw new DomainError('EMAIL_TAKEN');
            $u=$this->db->prepare('UPDATE users SET email=?,password_hash=?,display_name=? WHERE id=? AND email LIKE ?');
            $u->execute([$email,password_hash($password,PASSWORD_DEFAULT),$name,(int)$c['user_id'],'%'.self::PLACEHOLDER_DOMAIN]);
            if ($u->rowCount()!==1) throw new DomainError('WELCOME_INVALID');
            $this->db->prepare('UPDATE member_claims SET welcome_hash=NULL,welcome_expires=NULL WHERE user_id=?')->execute([(int)$c['user_id']]);
            $this->db->commit();
            return (int)$c['user_id'];
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
