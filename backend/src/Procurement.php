<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/** Guild-scoped replenishment and manual fulfillment worklists. Not automatic WoW actions. */
final class Procurement
{
    public function __construct(private readonly PDO $db) {}

    public static function needed(int $physical,int $reserved,int $min,int $target,bool $verified): ?int
    {
        if ($physical<0 || $reserved<0 || $reserved>$physical || $min<0 || $target<$min || $target>1000000) {
            throw new DomainError('INVALID_RESTOCK_STATE');
        }
        if (!$verified) return null;
        $available=$physical-$reserved;
        return $available<$min ? max(0,$target-$available) : 0;
    }

    private function permitted(int $guild,int $actor,bool $write=false): void
    {
        $s=$this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'" . ($write?' FOR UPDATE':''));
        $s->execute([$guild,$actor]);
        if(!in_array($s->fetchColumn(),['bank_manager','admin'],true))throw new DomainError('FORBIDDEN');
    }

    public function configure(int $guild,int $actor,int $stockId,int $minimum,int $target): void
    {
        if($stockId<1 || $minimum<0 || $target<$minimum || $target>1000000)throw new DomainError('INVALID_RESTOCK_RULE');
        $this->db->beginTransaction();
        try {
            $this->permitted($guild,$actor,true);
            $s=$this->db->prepare('SELECT id FROM guild_stock WHERE guild_id=? AND id=? FOR UPDATE');
            $s->execute([$guild,$stockId]);if(!$s->fetchColumn())throw new DomainError('STOCK_NOT_FOUND');
            $q=$this->db->prepare('INSERT INTO guild_restock_rules(guild_id,stock_id,minimum_qty,target_qty,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE minimum_qty=VALUES(minimum_qty),target_qty=VALUES(target_qty),updated_by=VALUES(updated_by)');
            $q->execute([$guild,$stockId,$minimum,$target,$actor]);
            $log=$this->db->prepare("INSERT INTO audit_events(guild_id,actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,'restock.configure','stock',?,?)");
            $log->execute([$guild,$actor,$stockId,json_encode(['minimum'=>$minimum,'target'=>$target],JSON_THROW_ON_ERROR)]);
            $this->db->commit();
        } catch(\Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function rules(int $guild,int $actor): array
    {
        $this->permitted($guild,$actor);
        $q=$this->db->prepare("SELECT r.stock_id,i.name,i.wow_item_id,i.variant_key,r.minimum_qty,r.target_qty,
         s.physical_qty,s.reserved_qty,s.observed_at,s.confidence,
         (s.confidence='verified') AS fresh
         FROM guild_restock_rules r JOIN guild_stock s ON s.id=r.stock_id AND s.guild_id=r.guild_id
         JOIN items i ON i.id=s.item_id WHERE r.guild_id=? ORDER BY i.name,r.stock_id LIMIT 2000");
        $q->execute([$guild]);$rows=$q->fetchAll();
        foreach($rows as &$r){
            $r['missing_qty']=self::needed((int)$r['physical_qty'],(int)$r['reserved_qty'],(int)$r['minimum_qty'],(int)$r['target_qty'],(bool)$r['fresh']);
            $r['status']=$r['missing_qty']===null?'unverified':($r['missing_qty']>0?'shortage':'ok');
        }
        unset($r);
        return $rows;
    }

    /** The bank operator, never a server-side bot, executes these rows in WoW. */
    public function manifest(int $guild,int $actor): array
    {
        $this->permitted($guild,$actor);
        $q=$this->db->prepare("SELECT o.id AS order_id,o.user_id,u.display_name,l.id AS line_id,i.name,i.wow_item_id,i.variant_key,
          l.approved_qty-l.fulfilled_qty AS to_issue,gs.confidence,gs.observed_at
          FROM orders o JOIN users u ON u.id=o.user_id JOIN order_lines l ON l.order_id=o.id
          JOIN guild_stock gs ON gs.id=l.stock_id AND gs.guild_id=o.guild_id
          JOIN items i ON i.id=gs.item_id
          WHERE o.guild_id=? AND o.status IN ('reserved','partial','partially_fulfilled')
          AND l.decision='approved' AND l.approved_qty>l.fulfilled_qty
          ORDER BY o.created_at,o.id,l.id LIMIT 500");
        $q->execute([$guild]);return $q->fetchAll();
    }

    /** Sanitizes spreadsheet cells against formula injection while preserving UTF-8. */
    public static function csvCell(string $value): string
    {
        return preg_match('/^\s*[=+@\-\t\r]/u',$value) ? "'".$value : $value;
    }

    public static function csv(array $rows): string
    {
        $handle=fopen('php://temp','w+');
        if(!$handle)throw new \RuntimeException('CSV_BUFFER_FAILED');
        fputcsv($handle,['WoW-Item-ID','Variante','Name','Mindestbestand','Zielbestand','Verfuegbar','Nachkaufmenge','Status'],';', '"','');
        foreach($rows as $row){
            if($row['missing_qty']===0)continue;
            fputcsv($handle,[(string)$row['wow_item_id'],self::csvCell((string)$row['variant_key']),self::csvCell((string)$row['name']),
              (string)$row['minimum_qty'],(string)$row['target_qty'],
              $row['missing_qty']===null?'':(string)((int)$row['physical_qty']-(int)$row['reserved_qty']),
              $row['missing_qty']===null?'':(string)$row['missing_qty'],$row['status']],';','"','');
        }
        rewind($handle);$out=stream_get_contents($handle);fclose($handle);
        return "\xEF\xBB\xBF".$out;
    }
}
