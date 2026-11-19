<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/** One-purpose server tokens. Security boundary is an authenticated backend, NOT an addon message. */
final class BridgeTokens
{
    public function __construct(private readonly PDO $db) {}

    public static function tokenIsWellFormed(string $token): bool
    {
        return (bool)preg_match('/^bl1_[a-f0-9]{64}$/D', $token);
    }

    public function issue(int $guild, int $actor, string $label): array
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 80) throw new DomainError('INVALID_TOKEN_LABEL');
        $this->db->beginTransaction();
        try {
            $role = $this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active' FOR UPDATE");
            $role->execute([$guild,$actor]);
            if (!in_array($role->fetchColumn(), ['admin','bank_manager'], true)) throw new DomainError('FORBIDDEN');
            $count = $this->db->prepare("SELECT COUNT(*) FROM bridge_tokens WHERE guild_id=? AND scope IN ('scan.stage','scan.auto') AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()");
            $count->execute([$guild]);
            if ((int)$count->fetchColumn() >= 5) throw new DomainError('BRIDGE_TOKEN_LIMIT');
            $plaintext = 'bl1_'.bin2hex(random_bytes(32));
            $digest=hash('sha256',$plaintext);
            $stmt=$this->db->prepare("INSERT INTO bridge_tokens (guild_id,created_by,label,token_hash,scope,expires_at) VALUES (?,?,?,?,'scan.stage',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 90 DAY))");
            $stmt->execute([$guild,$actor,$label,$digest]);
            $tokenId=(int)$this->db->lastInsertId();
            $audit=$this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id) VALUES (?,?,'bridge.token.created','bridge_token',?)");
            $audit->execute([$guild,$actor,$tokenId]);
            $this->db->commit();
            return ['id'=>$tokenId,'label'=>$label,'token'=>$plaintext,'expires_in_days'=>90,'scope'=>'scan.stage'];
        } catch(\Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    /** Personal token for in-game orders. Any active member; grants NO inventory rights. */
    public function issueMemberOrders(int $guild, int $actor, string $label): array
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 80) throw new DomainError('INVALID_TOKEN_LABEL');
        $this->db->beginTransaction();
        try {
            $role = $this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active' FOR UPDATE");
            $role->execute([$guild,$actor]);
            if (!$role->fetchColumn()) throw new DomainError('FORBIDDEN');
            $count = $this->db->prepare("SELECT COUNT(*) FROM bridge_tokens WHERE guild_id=? AND created_by=? AND scope='member.orders' AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()");
            $count->execute([$guild,$actor]);
            if ((int)$count->fetchColumn() >= 2) throw new DomainError('BRIDGE_TOKEN_LIMIT');
            $plaintext = 'bl1_'.bin2hex(random_bytes(32));
            $stmt=$this->db->prepare("INSERT INTO bridge_tokens (guild_id,created_by,label,token_hash,scope,expires_at) VALUES (?,?,?,?,'member.orders',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 DAY))");
            $stmt->execute([$guild,$actor,$label,hash('sha256',$plaintext)]);
            $tokenId=(int)$this->db->lastInsertId();
            $audit=$this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id) VALUES (?,?,'bridge.token.created','bridge_token',?)");
            $audit->execute([$guild,$actor,$tokenId]);
            $this->db->commit();
            return ['id'=>$tokenId,'label'=>$label,'token'=>$plaintext,'expires_in_days'=>180,'scope'=>'member.orders'];
        } catch(\Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function authorize(int $guild, string $token): int
    {
        if (!self::tokenIsWellFormed($token)) throw new DomainError('BRIDGE_TOKEN_INVALID');
        $s=$this->db->prepare("SELECT t.id,t.created_by FROM bridge_tokens t
          JOIN guild_memberships m ON m.guild_id=t.guild_id AND m.user_id=t.created_by JOIN guilds g ON g.id=t.guild_id AND g.locked_at IS NULL
          WHERE t.guild_id=? AND t.token_hash=? AND t.scope='scan.stage'
          AND t.revoked_at IS NULL AND t.expires_at>UTC_TIMESTAMP()
          AND m.status='active' AND m.role IN ('admin','bank_manager') LIMIT 1");
        $s->execute([$guild,hash('sha256',$token)]);
        $row=$s->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainError('BRIDGE_TOKEN_INVALID');
        $u=$this->db->prepare('UPDATE bridge_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?');
        $u->execute([(int)$row['id']]);
        return (int)$row['created_by'];
    }

    /** Only an administrator, with fresh password confirmation, may opt into
     * automatic application. The new token has a visibly different scope.
     * Existing scan.stage tokens never inherit reconciliation authority. */
    public function configureAutomation(int $guild,int $actor,string $password,bool $enable,string $label=''): array
    {
        $this->db->beginTransaction();
        try {
            $role=$this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active' FOR UPDATE");
            $role->execute([$guild,$actor]);
            if ($role->fetchColumn()!=='admin') throw new DomainError('FORBIDDEN');
            $pw=$this->db->prepare('SELECT password_hash FROM users WHERE id=?');$pw->execute([$actor]);
            $stored=$pw->fetchColumn();
            if (!is_string($stored) || !password_verify($password,$stored)) throw new DomainError('REAUTH_FAILED');
            // Disable old automatic credentials on each state transition. No
            // revoked token can silently regain power after re-enabling.
            $this->db->prepare("UPDATE bridge_tokens SET revoked_at=UTC_TIMESTAMP() WHERE guild_id=? AND scope='scan.auto' AND revoked_at IS NULL")->execute([$guild]);
            $issued=null;
            if ($enable) {
                $label=trim($label);
                if ($label==='' || mb_strlen($label)>80) throw new DomainError('INVALID_TOKEN_LABEL');
                $q=$this->db->prepare('SELECT COUNT(*) FROM bridge_tokens WHERE guild_id=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()');
                $q->execute([$guild]);if ((int)$q->fetchColumn()>=5) throw new DomainError('BRIDGE_TOKEN_LIMIT');
                $plain='bl1_'.bin2hex(random_bytes(32));
                $ins=$this->db->prepare("INSERT INTO bridge_tokens (guild_id,created_by,label,token_hash,scope,expires_at) VALUES (?,?,?,?,'scan.auto',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 90 DAY))");
                $ins->execute([$guild,$actor,$label,hash('sha256',$plain)]);
                $issued=['id'=>(int)$this->db->lastInsertId(),'token'=>$plain,'scope'=>'scan.auto','expires_in_days'=>90];
            }
            $up=$this->db->prepare("INSERT INTO guild_scan_automation (guild_id,enabled,enabled_by,enabled_at,disabled_at) VALUES (?,?,?,IF(?=1,UTC_TIMESTAMP(),NULL),IF(?=0,UTC_TIMESTAMP(),NULL)) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),enabled_by=VALUES(enabled_by),enabled_at=VALUES(enabled_at),disabled_at=VALUES(disabled_at)");
            $up->execute([$guild,$enable?1:0,$actor,$enable?1:0,$enable?1:0]);
            $act=$enable?'scan.automation.enabled':'scan.automation.disabled';
            $a=$this->db->prepare('INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,?,?,?,?)');
            $a->execute([$guild,$actor,$act,'guild',$guild,json_encode(['auto_token_issued'=>$issued!==null,'human_opt_in'=>true],JSON_THROW_ON_ERROR)]);
            $this->db->commit();
            return ['enabled'=>$enable,'credential'=>$issued];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    /** Authentication + scope are independent of client-provided characters. */
    public function authorizeScoped(int $guild,string $token): array
    {
        if (!self::tokenIsWellFormed($token)) throw new DomainError('BRIDGE_TOKEN_INVALID');
        $s=$this->db->prepare("SELECT t.id,t.created_by,t.scope FROM bridge_tokens t
          JOIN guild_memberships m ON m.guild_id=t.guild_id AND m.user_id=t.created_by JOIN guilds g ON g.id=t.guild_id AND g.locked_at IS NULL
          WHERE t.guild_id=? AND t.token_hash=?
          AND t.revoked_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND m.status='active'
          AND ((t.scope IN ('scan.stage','scan.auto') AND m.role IN ('admin','bank_manager')) OR t.scope='member.orders') LIMIT 1");
        $s->execute([$guild,hash('sha256',$token)]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainError('BRIDGE_TOKEN_INVALID');
        // In particular an automated grant is admin-only even if the account's
        // role was reduced after issuing the token.
        if ($row['scope']==='scan.auto') {
            $role=$this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'");
            $role->execute([$guild,$row['created_by']]);
            if ($role->fetchColumn()!=='admin') throw new DomainError('BRIDGE_TOKEN_INVALID');
        }
        $this->db->prepare('UPDATE bridge_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?')->execute([$row['id']]);
        return ['actor'=>(int)$row['created_by'],'scope'=>(string)$row['scope'],'token_id'=>(int)$row['id']];
    }

    public function revoke(int $guild, int $actor, int $tokenId): bool
    {
        $check=$this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'");
        $check->execute([$guild,$actor]);
        $isAdmin=$check->fetchColumn()==='admin';
        // Members may revoke their own personal tokens; everything else stays admin-only.
        $stmt=$this->db->prepare('UPDATE bridge_tokens SET revoked_at=UTC_TIMESTAMP() WHERE guild_id=? AND id=? AND revoked_at IS NULL'.($isAdmin?'':' AND created_by=?'));
        $stmt->execute($isAdmin?[$guild,$tokenId]:[$guild,$tokenId,$actor]);
        if($stmt->rowCount()===1){
            $a=$this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id) VALUES (?,?,'bridge.token.revoked','bridge_token',?)");
            $a->execute([$guild,$actor,$tokenId]);
        }
        return $stmt->rowCount()===1;
    }
}
