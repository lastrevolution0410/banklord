<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/**
 * Creating further guilds on the same website, e.g. a Retail guild next to a
 * Classic one. Any guild admin may create one and becomes its admin.
 */
final class Guilds
{
    /** Guilds one person may create; a guard against mistakes and abuse. */

    public function __construct(private readonly PDO $db) {}

    /** Number of guilds this account has set up (counts against the operator's limit). */
    public function createdBy(int $actor): int
    {
        $c=$this->db->prepare("SELECT COUNT(*) FROM audit_events WHERE actor_user_id=? AND action='guild.created'");$c->execute([$actor]);
        return (int)$c->fetchColumn();
    }

    public function create(int $actor, array $in): array
    {
        // Every account may set up its guild; the operator sets how many (0 = only the operator).
        $op=new Operator($this->db);
        $name=is_string($in['name']??null)?trim($in['name']):'';$realm=is_string($in['realm']??null)?trim($in['realm']):'';
        $region=$in['region']??'EU';$flavor=GameFlavor::check($in['wow_flavor']??null);
        $ok=fn(string $s,int $max)=>mb_strlen($s)>=2 && mb_strlen($s)<=$max && preg_match('//u',$s) && !preg_match('/[\x00-\x1f\x7f<>|]/',$s);
        if (!$ok($name,120) || !$ok($realm,100) || !in_array($region,['EU','US','KR','TW'],true)) throw new DomainError('INVALID_GUILD');
        $c=$this->db->prepare("SELECT COUNT(*) FROM audit_events WHERE actor_user_id=? AND action='guild.created'");$c->execute([$actor]);
        if (!$op->is($actor) && (int)$c->fetchColumn()>=$op->setting('guilds_per_account')) throw new DomainError('GUILD_LIMIT');
        $d=$this->db->prepare('SELECT COUNT(*) FROM guilds WHERE name=? AND realm=? AND wow_flavor=?');$d->execute([$name,$realm,$flavor]);
        if ((int)$d->fetchColumn()>0) throw new DomainError('GUILD_EXISTS');
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO guilds (name,realm,region,wow_flavor) VALUES (?,?,?,?)')->execute([$name,$realm,$region,$flavor]);
            $g=(int)$this->db->lastInsertId();
            $this->db->prepare("INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,'admin')")->execute([$g,$actor]);
            $this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,'guild.created','guild',?,?)")
                ->execute([$g,$actor,$g,json_encode(['name'=>$name,'realm'=>$realm,'wow_flavor'=>$flavor],JSON_THROW_ON_ERROR)]);
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
        return ['id'=>$g,'name'=>$name,'realm'=>$realm,'region'=>$region,'wow_flavor'=>$flavor,'role'=>'admin'];
    }
}
