<?php
declare(strict_types=1);
namespace Banklord;

use PDO;

/**
 * The site operator runs the whole website: sees every guild, the central
 * feedback inbox and the update/release tools, and sets site-wide limits.
 * Guild admins only manage their own guild.
 */
final class Operator
{
    public const SETTINGS=['guilds_per_account'=>[3,0,50],
        // Guild leaders may sign up on their own (account + guild in one step); off by default.
        'self_signup'=>[0,0,1],
        // At most this many self sign-ups per 24 hours on the whole website.
        'signups_per_day'=>[20,0,500]];

    public function __construct(private PDO $db) {}

    public function is(int $user): bool
    {
        $s=$this->db->prepare('SELECT is_operator FROM users WHERE id=?');$s->execute([$user]);
        return (int)$s->fetchColumn()===1;
    }

    public function setting(string $name): int
    {
        [$default,$min,$max]=self::SETTINGS[$name];
        $s=$this->db->prepare('SELECT value FROM site_settings WHERE name=?');$s->execute([$name]);$v=$s->fetchColumn();
        return $v===false || !ctype_digit((string)$v) ? $default : max($min,min($max,(int)$v));
    }

    public function settings(): array
    {
        $out=[];foreach (self::SETTINGS as $k=>[$d,$min,$max]) $out[$k]=['value'=>$this->setting($k),'min'=>$min,'max'=>$max];
        return $out;
    }

    public function save(int $actor,array $in): array
    {
        if (!$this->is($actor)) throw new DomainError('FORBIDDEN');
        foreach (self::SETTINGS as $k=>[$d,$min,$max]) {
            if (!array_key_exists($k,$in)) continue;
            $v=$in[$k];
            if (!is_int($v) || $v<$min || $v>$max) throw new DomainError('INVALID_SETTING');
            $this->db->prepare('INSERT INTO site_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$k,(string)$v]);
        }
        return $this->settings();
    }

    /** Every guild of the website with a few numbers, for the operator's overview. */
    public function guilds(int $actor): array
    {
        if (!$this->is($actor)) throw new DomainError('FORBIDDEN');
        $s=$this->db->prepare("SELECT g.id,g.name,g.realm,g.region,g.wow_flavor,UNIX_TIMESTAMP(g.created_at) AS created,
            (SELECT COUNT(*) FROM guild_memberships m WHERE m.guild_id=g.id AND m.status='active') AS members,
            (SELECT GROUP_CONCAT(u.display_name ORDER BY u.display_name SEPARATOR ', ') FROM guild_memberships m JOIN users u ON u.id=m.user_id
               WHERE m.guild_id=g.id AND m.role='admin' AND m.status='active') AS admins,
            (SELECT UNIX_TIMESTAMP(MAX(o.created_at)) FROM orders o WHERE o.guild_id=g.id) AS last_order,
            (SELECT role FROM guild_memberships m WHERE m.guild_id=g.id AND m.user_id=? AND m.status='active') AS my_role,
            g.locked_at IS NOT NULL AS locked
            FROM guilds g ORDER BY g.name,g.realm,g.id");
        $s->execute([$actor]);
        return array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>(string)$r['name'],'realm'=>(string)$r['realm'],'region'=>(string)$r['region'],
            'wow_flavor'=>(string)$r['wow_flavor'],'created_at'=>(int)$r['created'],'members'=>(int)$r['members'],'admins'=>(string)($r['admins']??''),
            'last_order'=>$r['last_order']===null?null:(int)$r['last_order'],'my_role'=>$r['my_role']===null?null:(string)$r['my_role'],'locked'=>(int)$r['locked']===1],$s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Operator locks a guild (e.g. abuse) or unlocks it again. Members keep their data. */
    public function lock(int $actor,int $guild,bool $locked): bool
    {
        if (!$this->is($actor)) throw new DomainError('FORBIDDEN');
        $g=$this->db->prepare('SELECT id FROM guilds WHERE id=?');$g->execute([$guild]);
        if (!$g->fetchColumn()) throw new DomainError('GUILD_NOT_FOUND');
        $this->db->prepare('UPDATE guilds SET locked_at='.($locked?'UTC_TIMESTAMP()':'NULL').' WHERE id=?')->execute([$guild]);
        $this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id) VALUES (?,?,?,'guild',?)")
            ->execute([$guild,$actor,$locked?'operator.locked':'operator.unlocked',$guild]);
        return $locked;
    }

    /** Accounts of the website for the operator: search by name or e-mail, 50 per page. */
    public function accounts(int $actor,string $q,int $page): array
    {
        if (!$this->is($actor)) throw new DomainError('FORBIDDEN');
        $q=mb_substr(trim($q),0,100);$like='%'.addcslashes($q,'%_\\').'%';$page=max(0,min(10000,$page));
        // Also by WoW character: members who joined in game are often known by their character.
        $where="WHERE (?='' OR u.email LIKE ? OR u.display_name LIKE ? OR EXISTS (SELECT 1 FROM characters ch WHERE ch.user_id=u.id AND ch.name LIKE ?))";
        $c=$this->db->prepare("SELECT COUNT(*) FROM users u $where");$c->execute([$q,$like,$like,$like]);$total=(int)$c->fetchColumn();
        $s=$this->db->prepare("SELECT u.id,u.email,u.display_name,u.is_operator,UNIX_TIMESTAMP(u.created_at) AS created,UNIX_TIMESTAMP(u.last_login_at) AS last_login,
            u.locked_at IS NOT NULL AS locked,
            (SELECT GROUP_CONCAT(CONCAT(g.name,' (',m.role,')') ORDER BY g.name SEPARATOR ', ') FROM guild_memberships m JOIN guilds g ON g.id=m.guild_id
               WHERE m.user_id=u.id AND m.status='active') AS guilds,
            (SELECT GROUP_CONCAT(DISTINCT ch.name ORDER BY ch.name SEPARATOR ', ') FROM characters ch WHERE ch.user_id=u.id AND ch.verification_status<>'revoked') AS chars
            FROM users u $where ORDER BY u.created_at DESC,u.id DESC LIMIT 50 OFFSET ".($page*50));
        $s->execute([$q,$like,$like,$like]);
        $rows=array_map(fn($r)=>['id'=>(int)$r['id'],'email'=>str_ends_with((string)$r['email'],'@claim.invalid')?'':(string)$r['email'],
            'pending'=>str_ends_with((string)$r['email'],'@claim.invalid'),'display_name'=>(string)$r['display_name'],'operator'=>(int)$r['is_operator']===1,
            'created_at'=>(int)$r['created'],'last_login'=>$r['last_login']===null?null:(int)$r['last_login'],'locked'=>(int)$r['locked']===1,
            'guilds'=>(string)($r['guilds']??''),'characters'=>(string)($r['chars']??'')],$s->fetchAll(PDO::FETCH_ASSOC));
        return ['accounts'=>$rows,'total'=>$total,'page'=>$page];
    }

    private function target(int $actor,int $user): array
    {
        if (!$this->is($actor)) throw new DomainError('FORBIDDEN');
        $s=$this->db->prepare('SELECT id,email,is_operator FROM users WHERE id=?');$s->execute([$user]);$u=$s->fetch(PDO::FETCH_ASSOC);
        if (!$u) throw new DomainError('ACCOUNT_NOT_FOUND');
        // Never against yourself or another operator.
        if ((int)$u['id']===$actor || (int)$u['is_operator']===1) throw new DomainError('ACCOUNT_PROTECTED');
        return $u;
    }

    public function log(int $actor,string $action,?int $target): void
    {
        $this->db->prepare('INSERT INTO operator_events (actor_user_id,action,target_user_id) VALUES (?,?,?)')->execute([$actor,$action,$target]);
    }

    /** Lock or unlock an account. A locked account cannot sign in, its Companion stops. */
    public function lockAccount(int $actor,int $user,bool $locked): bool
    {
        $this->target($actor,$user);
        $this->db->prepare('UPDATE users SET locked_at='.($locked?'UTC_TIMESTAMP()':'NULL').' WHERE id=?')->execute([$user]);
        $this->log($actor,$locked?'account.locked':'account.unlocked',$user);
        return $locked;
    }

    /** Reset link for an account: e-mailed (if possible) or handed to the operator to pass on. */
    public function resetAccount(int $actor,int $user,Mailer $mail,string $siteUrl,bool $send): array
    {
        $u=$this->target($actor,$user);
        $email=(string)$u['email'];
        if (str_ends_with($email,'@claim.invalid')) throw new DomainError('ACCOUNT_PENDING');
        if ($send && (str_ends_with($email,'.invalid') || !$mail->ready())) throw new DomainError('MAIL_NOT_POSSIBLE');
        $token=(new PasswordReset($this->db,$mail))->createFor($user,$send?1:24);
        $link=rtrim($mail->siteUrl()?:$siteUrl,'/').'/#reset='.$token;
        if ($send) {
            $mail->send($email,'Banklord: Passwort zurücksetzen',"Hallo,\n\nder Betreiber der Banklord-Website hat dir einen Link geschickt, mit dem du ein neues Passwort festlegst (eine Stunde gültig):\n\n$link\n\nBanklord");
            $this->log($actor,'account.reset_mailed',$user);
            return ['sent'=>true];
        }
        $this->log($actor,'account.reset_link',$user);
        return ['link'=>$link,'hours'=>24];
    }

    public static function accountLocked(PDO $db,int $user): bool
    {
        $s=$db->prepare('SELECT locked_at IS NOT NULL FROM users WHERE id=?');$s->execute([$user]);return (int)$s->fetchColumn()===1;
    }

    public static function guildLocked(PDO $db,int $guild): bool
    {
        $s=$db->prepare('SELECT locked_at IS NOT NULL FROM guilds WHERE id=?');$s->execute([$guild]);return (int)$s->fetchColumn()===1;
    }

    /** Operator opens a guild: becomes its admin (visible to the guild, written to its log). */
    public function open(int $actor,int $guild): array
    {
        if (!$this->is($actor)) throw new DomainError('FORBIDDEN');
        $g=$this->db->prepare('SELECT id,name,realm,region,wow_flavor FROM guilds WHERE id=?');$g->execute([$guild]);$row=$g->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainError('GUILD_NOT_FOUND');
        $m=$this->db->prepare('SELECT role,status FROM guild_memberships WHERE guild_id=? AND user_id=?');$m->execute([$guild,$actor]);$cur=$m->fetch(PDO::FETCH_ASSOC);
        if (!$cur || $cur['role']!=='admin' || $cur['status']!=='active') {
            $this->db->prepare("INSERT INTO guild_memberships (guild_id,user_id,role,status) VALUES (?,?,'admin','active')
                ON DUPLICATE KEY UPDATE role='admin',status='active'")->execute([$guild,$actor]);
            $this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id) VALUES (?,?,'operator.opened','guild',?)")
                ->execute([$guild,$actor,$guild]);
        }
        return ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'realm'=>(string)$row['realm'],'region'=>(string)$row['region'],'wow_flavor'=>(string)$row['wow_flavor'],'role'=>'admin'];
    }
}
