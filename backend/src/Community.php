<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/** Guild-scoped invitation, membership and character administration. */
final class Community {
    public function __construct(private PDO $db) {}
    public static function inviteFormat(string $token): bool {return preg_match('/^wgi1_[a-f0-9]{64}$/D',$token)===1;}
    public static function passwordAllowed(string $password): bool {return strlen($password)>=12 && strlen($password)<=256;}
    public static function characterAllowed(string $name): bool {return preg_match('/^[\p{L}][\p{L}\-\']{1,31}$/uD',$name)===1;}
    private function admin(int $guild,int $actor): void {
        $s=$this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'");
        $s->execute([$guild,$actor]);if($s->fetchColumn()!=='admin')throw new DomainError('ADMIN_REQUIRED');
    }
    public function issueInvitation(int $guild,int $actor,string $role='member'): array {
        $this->admin($guild,$actor);
        if(!in_array($role,['member','officer','bank_manager'],true))throw new DomainError('INVALID_INVITE_ROLE');
        $token='wgi1_'.bin2hex(random_bytes(32));$hash=hash('sha256',$token);
        $s=$this->db->prepare('INSERT INTO guild_invitations (guild_id,created_by,token_hash,role,expires_at) VALUES (?,?,?,?,UTC_TIMESTAMP()+INTERVAL 48 HOUR)');
        $s->execute([$guild,$actor,$hash,$role]);
        return ['token'=>$token,'guild_id'=>$guild,'role'=>$role,'expires_hours'=>48];
    }
    public function redeem(string $token,string $email,string $name,string $password): array {
        $email=mb_strtolower(trim($email));$name=trim($name);
        if(!self::inviteFormat($token) || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254 ||
           mb_strlen($name)<2 || mb_strlen($name)>100 || !self::passwordAllowed($password))throw new DomainError('INVALID_REGISTRATION');
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare('SELECT id,guild_id,role FROM guild_invitations WHERE token_hash=? AND redeemed_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
            $s->execute([hash('sha256',$token)]);$row=$s->fetch();if(!$row)throw new DomainError('INVITE_EXPIRED_OR_USED');
            $s=$this->db->prepare('SELECT id FROM users WHERE email=?');$s->execute([$email]);
            if($s->fetchColumn())throw new DomainError('EMAIL_ALREADY_REGISTERED');
            $s=$this->db->prepare('INSERT INTO users (email,password_hash,display_name) VALUES (?,?,?)');
            $s->execute([$email,password_hash($password,PASSWORD_DEFAULT),$name]);$uid=(int)$this->db->lastInsertId();
            $s=$this->db->prepare('INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,?)');
            $s->execute([(int)$row['guild_id'],$uid,$row['role']]);
            $s=$this->db->prepare('UPDATE guild_invitations SET redeemed_at=UTC_TIMESTAMP(),redeemed_by=? WHERE id=? AND redeemed_at IS NULL');
            $s->execute([$uid,$row['id']]);if($s->rowCount()!==1)throw new DomainError('INVITE_ALREADY_USED');
            $this->db->commit();return ['user_id'=>$uid,'guild_id'=>(int)$row['guild_id']];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Signed-in user joins one more guild with an invitation (one account for several guilds). */
    public function join(string $token,int $user): array {
        if(!self::inviteFormat($token))throw new DomainError('INVITE_EXPIRED_OR_USED');
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare('SELECT id,guild_id,role FROM guild_invitations WHERE token_hash=? AND redeemed_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
            $s->execute([hash('sha256',$token)]);$row=$s->fetch();if(!$row)throw new DomainError('INVITE_EXPIRED_OR_USED');
            $m=$this->db->prepare('SELECT status FROM guild_memberships WHERE guild_id=? AND user_id=?');$m->execute([(int)$row['guild_id'],$user]);
            $status=$m->fetchColumn();
            // Never re-enable a member an admin has disabled; an invitation stays unused then.
            if($status==='disabled')throw new DomainError('MEMBERSHIP_DISABLED');
            if($status!==false)throw new DomainError('ALREADY_MEMBER');
            $this->db->prepare('INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,?)')->execute([(int)$row['guild_id'],$user,$row['role']]);
            $u=$this->db->prepare('UPDATE guild_invitations SET redeemed_at=UTC_TIMESTAMP(),redeemed_by=? WHERE id=? AND redeemed_at IS NULL');
            $u->execute([$user,$row['id']]);if($u->rowCount()!==1)throw new DomainError('INVITE_ALREADY_USED');
            $this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id) VALUES (?,?,'member.invite.joined','user',?)")
                ->execute([(int)$row['guild_id'],$user,$user]);
            $g=$this->db->prepare('SELECT name,realm FROM guilds WHERE id=?');$g->execute([(int)$row['guild_id']]);$gr=$g->fetch()?:['name'=>'','realm'=>''];
            $this->db->commit();
            return ['guild_id'=>(int)$row['guild_id'],'role'=>(string)$row['role'],'name'=>(string)$gr['name'],'realm'=>(string)$gr['realm']];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function members(int $guild,int $actor): array {
        $this->admin($guild,$actor);
        $s=$this->db->prepare('SELECT m.user_id,u.display_name,u.email,m.role,m.status, m.joined_at FROM guild_memberships m JOIN users u ON u.id=m.user_id WHERE m.guild_id=? ORDER BY u.display_name,u.id');
        $s->execute([$guild]);return $s->fetchAll();
    }
    public function setMembership(int $guild,int $actor,int $user,string $role,string $status): void {
        if(!in_array($role,['member','officer','bank_manager','admin'],true)||!in_array($status,['active','disabled'],true))throw new DomainError('INVALID_MEMBERSHIP');
        $this->db->beginTransaction();
        try {
            // Serialize demotions in this guild: two concurrent admins must never remove the last admin.
            $guildLock=$this->db->prepare('SELECT id FROM guilds WHERE id=? FOR UPDATE');
            $guildLock->execute([$guild]);
            if(!$guildLock->fetchColumn())throw new DomainError('GUILD_NOT_FOUND');
            $this->admin($guild,$actor);
            $s=$this->db->prepare('SELECT role,status FROM guild_memberships WHERE guild_id=? AND user_id=? FOR UPDATE');
            $s->execute([$guild,$user]);$current=$s->fetch();if(!$current)throw new DomainError('MEMBER_NOT_FOUND');
            if($current['role']==='admin' && $current['status']==='active' && ($role!=='admin'||$status!=='active')) {
                $s=$this->db->prepare("SELECT COUNT(*) FROM guild_memberships WHERE guild_id=? AND role='admin' AND status='active'");$s->execute([$guild]);
                if((int)$s->fetchColumn()<2)throw new DomainError('LAST_ADMIN_CANNOT_BE_REMOVED');
            }
            $s=$this->db->prepare('UPDATE guild_memberships SET role=?,status=? WHERE guild_id=? AND user_id=?');$s->execute([$role,$status,$guild,$user]);
            $s=$this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,'membership.change','user',?,?)");
            $s->execute([$guild,$actor,$user,json_encode(['role'=>$role,'status'=>$status],JSON_THROW_ON_ERROR)]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function addCharacter(int $guild,int $user,string $name,string $realm): int {
        $name=trim($name);$realm=trim($realm);
        if(!self::characterAllowed($name)||mb_strlen($realm)<2||mb_strlen($realm)>100)throw new DomainError('INVALID_CHARACTER');
        $s=$this->db->prepare("SELECT g.realm FROM guild_memberships m JOIN guilds g ON g.id=m.guild_id WHERE m.guild_id=? AND m.user_id=? AND m.status='active'");
        $s->execute([$guild,$user]);$allowed=$s->fetchColumn();if(!$allowed||mb_strtolower($allowed)!==mb_strtolower($realm))throw new DomainError('WRONG_REALM');
        $s=$this->db->prepare('INSERT INTO characters (guild_id,user_id,name,realm) VALUES (?,?,?,?)');$s->execute([$guild,$user,$name,$realm]);
        return (int)$this->db->lastInsertId();
    }
    public function characters(int $guild,int $user,bool $admin): array {
        $s=$this->db->prepare('SELECT id,user_id,name,realm,verification_status FROM characters WHERE guild_id=?'.($admin?'':' AND user_id=?').' ORDER BY name');
        $s->execute($admin?[$guild]:[$guild,$user]);return $s->fetchAll();
    }
    public function verifyCharacter(int $guild,int $actor,int $char,string $status): void {
        $this->admin($guild,$actor);if(!in_array($status,['verified','revoked'],true))throw new DomainError('INVALID_VERIFICATION');
        $s=$this->db->prepare('UPDATE characters SET verification_status=? WHERE guild_id=? AND id=?');$s->execute([$status,$guild,$char]);
        if(!$s->rowCount())throw new DomainError('CHARACTER_NOT_FOUND');
        $s=$this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,'character.review','character',?,?)");
        $s->execute([$guild,$actor,$char,json_encode(['status'=>$status],JSON_THROW_ON_ERROR)]);
    }
}
