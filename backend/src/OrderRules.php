<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/**
 * Guild order rules set by admins: auto-expiry of old orders, weekly piece
 * limits per role (above them an officer or admin approves) and view-only
 * guild bank tabs whose items are shown but cannot be ordered.
 */
final class OrderRules
{
    public const DEFAULTS=['expiry_days'=>0,'member_weekly_limit'=>0,'manager_weekly_limit'=>0,'viewonly_tabs'=>[]];

    public function __construct(private readonly PDO $db) {}

    public function get(int $guild): array
    {
        $s=$this->db->prepare('SELECT expiry_days,member_weekly_limit,manager_weekly_limit,viewonly_tabs FROM guild_order_rules WHERE guild_id=?');
        $s->execute([$guild]);$r=$s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return self::DEFAULTS;
        return ['expiry_days'=>(int)$r['expiry_days'],'member_weekly_limit'=>(int)$r['member_weekly_limit'],
            'manager_weekly_limit'=>(int)$r['manager_weekly_limit'],'viewonly_tabs'=>self::tabs((string)$r['viewonly_tabs'])];
    }

    /** @return list<int> sorted unique tab numbers 1..8 */
    public static function tabs(string $csv): array
    {
        $out=[];
        foreach (explode(',',$csv) as $t) if (preg_match('/^[1-8]$/D',trim($t))) $out[(int)trim($t)]=true;
        $out=array_keys($out);sort($out);return $out;
    }

    public function save(int $guild, int $actor, array $in): array
    {
        $role=$this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'");
        $role->execute([$guild,$actor]);
        if ($role->fetchColumn()!=='admin') throw new DomainError('FORBIDDEN');
        $int=static function($v,int $max): int {
            if (!is_int($v) || $v<0 || $v>$max) throw new DomainError('INVALID_ORDER_RULES');
            return $v;
        };
        $tabs=$in['viewonly_tabs']??[];
        if (!is_array($tabs) || count($tabs)>8) throw new DomainError('INVALID_ORDER_RULES');
        foreach ($tabs as $t) if (!is_int($t) || $t<1 || $t>8) throw new DomainError('INVALID_ORDER_RULES');
        $rules=['expiry_days'=>$int($in['expiry_days']??0,365),'member_weekly_limit'=>$int($in['member_weekly_limit']??0,1000000),
            'manager_weekly_limit'=>$int($in['manager_weekly_limit']??0,1000000),'viewonly_tabs'=>self::tabs(implode(',',$tabs))];
        $q=$this->db->prepare('INSERT INTO guild_order_rules (guild_id,expiry_days,member_weekly_limit,manager_weekly_limit,viewonly_tabs,updated_by) VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE expiry_days=VALUES(expiry_days),member_weekly_limit=VALUES(member_weekly_limit),manager_weekly_limit=VALUES(manager_weekly_limit),viewonly_tabs=VALUES(viewonly_tabs),updated_by=VALUES(updated_by)');
        $q->execute([$guild,$rules['expiry_days'],$rules['member_weekly_limit'],$rules['manager_weekly_limit'],implode(',',$rules['viewonly_tabs']),$actor]);
        $a=$this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,'guild.order_rules','guild',?,?)");
        $a->execute([$guild,$actor,$guild,json_encode($rules,JSON_THROW_ON_ERROR)]);
        return $rules;
    }

    /** Weekly limit for a role; 0 = unlimited. Officers and admins are never limited. */
    public static function limitFor(array $rules, string $role): int
    {
        return match ($role) {
            'member'=>$rules['member_weekly_limit'],
            'bank_manager'=>$rules['manager_weekly_limit'],
            default=>0,
        };
    }

    /** Pieces the user ordered in the last 7 days (rejected/cancelled orders do not count). */
    public function weeklyUsed(int $guild, int $user, int $exceptOrder=0): int
    {
        $s=$this->db->prepare("SELECT COALESCE(SUM(ol.requested_qty),0) FROM orders o JOIN order_lines ol ON ol.order_id=o.id
            WHERE o.guild_id=? AND o.user_id=? AND o.id<>? AND o.status NOT IN ('rejected','cancelled') AND o.created_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY");
        $s->execute([$guild,$user,$exceptOrder]);return (int)$s->fetchColumn();
    }

    /**
     * Cancels open, untouched orders older than the configured number of days.
     * Orders with deliveries already booked keep their delivered part; only the
     * open rest is cancelled. Returns the cancelled order ids.
     */
    public function expire(int $guild): array
    {
        $days=$this->get($guild)['expiry_days'];
        if ($days<1) return [];
        $s=$this->db->prepare("SELECT id FROM orders WHERE guild_id=? AND status IN ('submitted','reserved','partial','partially_fulfilled')
            AND created_at<UTC_TIMESTAMP()-INTERVAL ? DAY ORDER BY id LIMIT 50");
        $s->execute([$guild,$days]);$done=[];
        $orders=new Orders($this->db);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try { $orders->expire($guild,(int)$id);$done[]=(int)$id; } catch (DomainError $e) {}
        }
        return $done;
    }
}
