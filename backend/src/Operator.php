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
