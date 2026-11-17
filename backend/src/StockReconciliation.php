<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/** Explicit human-confirmed snapshot reconciliation. Does not trust addon identity or timestamps. */
final class StockReconciliation
{
    /** SavedVariables reach the companion only on /reload or logout, often hours after the scan. */
    public const MAX_AGE_AUTOMATED=1800;
    public const MAX_AGE_MANUAL=43200;

    public function __construct(private readonly PDO $db) {}

    /** Advisory preview only. confirm() repeats all checks under the guild row lock.
     * Never use this read-only result as authorization or as permission to bypass a conflict. */
    public function inspect(int $guild, int $snapshotId): array
    {
        $stmt=$this->db->prepare('SELECT id,observed_at,status,quality,source,source_key FROM stock_snapshots WHERE guild_id=? AND id=?');
        $stmt->execute([$guild,$snapshotId]);
        $snapshot=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$snapshot) return ['can_confirm'=>false,'reason'=>'SNAPSHOT_NOT_FOUND'];
        $result=['can_confirm'=>false,'reason'=>null,'observed_at'=>$snapshot['observed_at'],
            'checked_at'=>gmdate('Y-m-d H:i:s'),'conflict'=>null];
        if ($snapshot['status']!=='pending' || $snapshot['quality']!=='unverified'
            || $snapshot['source']!=='wgb-addon-savedvariables-v2') {
            $result['reason']='SNAPSHOT_NOT_PENDING';return $result;
        }
        // Pre-patch snapshots used the scan FINISH time; their capture windows
        // cannot be reconstructed from staged rows. Never confirm them blindly.
        if (!str_starts_with((string)$snapshot['source_key'],'window-v1-')) {
            $result['reason']='LEGACY_SCAN_REFRESH_REQUIRED';return $result;
        }
        $observed=strtotime($snapshot['observed_at'].' UTC');
        if (!$observed || time()-$observed>self::MAX_AGE_MANUAL || $observed>time()+300) {
            $result['reason']='SNAPSHOT_TOO_OLD';return $result;
        }
        $last=$this->db->prepare('SELECT MAX(id) FROM stock_snapshots WHERE guild_id=?');
        $last->execute([$guild]);
        if ((int)$last->fetchColumn()!==$snapshotId) {
            $result['reason']='SNAPSHOT_NOT_LATEST';return $result;
        }
        $newest=$this->db->prepare('SELECT MAX(observed_at) FROM guild_stock WHERE guild_id=?');
        $newest->execute([$guild]);$stockTime=$newest->fetchColumn();
        if (is_string($stockTime) && strcmp($stockTime,(string)$snapshot['observed_at'])>0) {
            $result['reason']='SCAN_OLDER_THAN_STOCK';return $result;
        }
        // Preserve identical timestamp ordering semantics from confirm().
        $movement=$this->db->prepare('SELECT id,event_type,physical_delta,created_at FROM stock_ledger WHERE guild_id=? AND physical_delta<>0 AND event_type<>\'scan_reconcile\' AND created_at>=? ORDER BY created_at DESC,id DESC LIMIT 1');
        $movement->execute([$guild,$snapshot['observed_at']]);
        $conflict=$movement->fetch(PDO::FETCH_ASSOC);
        if ($conflict) {
            $result['reason']='MOVEMENTS_AFTER_SCAN';
            $conflictTime=strtotime((string)$conflict['created_at'].' UTC');
            $result['conflict']=['ledger_id'=>(int)$conflict['id'],
                'event_type'=>(string)$conflict['event_type'],
                'created_at'=>(string)$conflict['created_at'],
                // A future-dated legacy DATETIME can occur if earlier versions
                // wrote in the shared-hosting server's non-UTC time zone.
                // Diagnostic only: NEVER bypass the physical-ledger safeguard.
                'clock_anomaly'=>$conflictTime!==false && $conflictTime>time()+300];
            return $result;
        }
        $result['can_confirm']=true;
        $result['reason']='READY_FOR_RECHECK';
        return $result;
    }

    public function confirm(int $guild,int $actor,int $snapshotId,bool $automated=false): array
    {
        $this->db->beginTransaction();
        try {
            // All inventory admission for one guild is serialized through this row.
            $g=$this->db->prepare('SELECT id,wow_flavor FROM guilds WHERE id=? FOR UPDATE');
            $g->execute([$guild]);$gr=$g->fetch();
            if (!$gr || $gr['wow_flavor']!=='mop_classic') throw new DomainError('GUILD_NOT_FOUND');
            $role=$this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active' FOR UPDATE");
            $role->execute([$guild,$actor]);
            if (!in_array($role->fetchColumn(),['admin','bank_manager'],true))throw new DomainError('FORBIDDEN');
            if ($automated) {
                // A scan.auto grant alone cannot enable automation; explicit
                // site-admin consent stored server-side is separately required.
                $setting=$this->db->prepare('SELECT enabled FROM guild_scan_automation WHERE guild_id=? FOR UPDATE');
                $setting->execute([$guild]);
                if ((int)$setting->fetchColumn()!==1) throw new DomainError('AUTO_SCAN_NOT_ENABLED');
            }
            $s=$this->db->prepare('SELECT * FROM stock_snapshots WHERE id=? AND guild_id=? FOR UPDATE');
            $s->execute([$snapshotId,$guild]);$snap=$s->fetch();
            if (!$snap || $snap['status']!=='pending' || $snap['quality']!=='unverified' || $snap['source']!=='wgb-addon-savedvariables-v2') throw new DomainError('SNAPSHOT_NOT_PENDING');
            if (!str_starts_with((string)$snap['source_key'],'window-v1-')) throw new DomainError('LEGACY_SCAN_REFRESH_REQUIRED');
            $time=strtotime($snap['observed_at'].' UTC');
            if (!$time || time()-$time>($automated?self::MAX_AGE_AUTOMATED:self::MAX_AGE_MANUAL) || $time>time()+300) throw new DomainError('SNAPSHOT_TOO_OLD');
            $latest=$this->db->prepare('SELECT MAX(id) FROM stock_snapshots WHERE guild_id=?');
            $latest->execute([$guild]);if ((int)$latest->fetchColumn()!==$snapshotId) throw new DomainError('SNAPSHOT_NOT_LATEST');
            // A server-recorded issue AFTER the scan must never be double-counted.
            $movement=$this->db->prepare("SELECT COUNT(*) FROM stock_ledger WHERE guild_id=? AND physical_delta<>0 AND event_type<>'scan_reconcile' AND created_at>=?");
            $movement->execute([$guild,$snap['observed_at']]);
            if ((int)$movement->fetchColumn()>0) throw new DomainError('MOVEMENTS_AFTER_SCAN');
            $rows=$this->db->prepare('SELECT wow_item_id,variant_key,MAX(item_name) AS item_name,SUM(quantity) AS quantity FROM snapshot_source_lines WHERE snapshot_id=? GROUP BY wow_item_id,variant_key ORDER BY wow_item_id,variant_key');
            $rows->execute([$snapshotId]);$sums=$rows->fetchAll();
            if (count($sums)>5000) throw new DomainError('SNAPSHOT_LIMIT');
            $insertItem=$this->db->prepare("INSERT INTO items (wow_flavor,wow_item_id,variant_key,name) VALUES ('mop_classic',?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
            $itemStocks=[];
            foreach ($sums as $entry) {
                $qty=(int)$entry['quantity'];
                if($qty<=0 || $qty>1000000)throw new DomainError('SNAPSHOT_QUANTITY');
                $insertItem->execute([(int)$entry['wow_item_id'],$entry['variant_key'],$entry['item_name']]);
                $itemStocks[(int)$this->db->lastInsertId()]=$qty;
            }
            // Lock all existing stock before updating to prevent overselling between reconciliation and approval.
            $existing=$this->db->prepare('SELECT id,item_id,physical_qty,reserved_qty,observed_at FROM guild_stock WHERE guild_id=? ORDER BY id FOR UPDATE');
            $existing->execute([$guild]);$existingRows=$existing->fetchAll();
            // Never regress to a snapshot that predates any accepted item state.
            // Before mutating anything, assess all newly observed quantities.
            $previous=0;$incoming=0;
            foreach ($existingRows as $stock) {
                if (!empty($stock['observed_at']) && strcmp((string)$stock['observed_at'], (string)$snap['observed_at'])>0)
                    throw new DomainError('SCAN_OLDER_THAN_STOCK');
                $previous+=(int)$stock['physical_qty'];
                $incoming+=(int)($itemStocks[(int)$stock['item_id']]??0);
            }
            foreach ($itemStocks as $item=>$qty) {
                $has=false;foreach($existingRows as $stock)if((int)$stock['item_id']===$item){$has=true;break;}
                if(!$has)$incoming+=$qty;
            }
            // Large unexpected drops indicate incomplete/stale scans or a
            // real mass withdrawal: human review, never silent stock deletion.
            if ($automated && $previous>=20 && $incoming<$previous*0.65)
                throw new DomainError('AUTO_SCAN_LARGE_DECREASE');
            $seen=[];$changes=0;
            foreach($existingRows as $stock){
                $item=(int)$stock['item_id'];$wanted=$itemStocks[$item]??0;
                if($wanted<(int)$stock['reserved_qty'])throw new DomainError('RESERVATION_EXCEEDS_SCAN');
                $delta=$wanted-(int)$stock['physical_qty'];
                $up=$this->db->prepare("UPDATE guild_stock SET physical_qty=?,confidence='verified',observed_at=?,stock_version=stock_version+1 WHERE id=? AND guild_id=?");
                $up->execute([$wanted,$snap['observed_at'],$stock['id'],$guild]);
                if($delta!==0){$this->ledger($guild,(int)$stock['id'],$actor,$delta,$snapshotId);$changes++;}
                $seen[$item]=true;
            }
            foreach($itemStocks as $item=>$qty){
                if(isset($seen[$item])) continue;
                $up=$this->db->prepare("INSERT INTO guild_stock (guild_id,item_id,physical_qty,confidence,observed_at) VALUES (?,?,?,'verified',?)");
                $up->execute([$guild,$item,$qty,$snap['observed_at']]);
                $this->ledger($guild,(int)$this->db->lastInsertId(),$actor,$qty,$snapshotId);
                $changes++;
            }
            $upd=$this->db->prepare("UPDATE stock_snapshots SET quality='complete',status='reconciled' WHERE guild_id=? AND id=? AND status='pending'");
            $upd->execute([$guild,$snapshotId]);
            $audit=$this->db->prepare('INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,?,?,?,?)');
            $audit->execute([$guild,$actor,$automated?'scan.automatically_reconciled':'scan.manually_confirmed',
                'stock_snapshot',$snapshotId,json_encode(['changed_stocks'=>$changes,'confirmed_by_human'=>!$automated,
                    'source_untrusted'=>true,'automated'=>$automated],JSON_THROW_ON_ERROR)]);
            $this->db->commit();
            return ['snapshot_id'=>$snapshotId,'status'=>'reconciled','changed_stocks'=>$changes,'automated'=>$automated];
        } catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function ledger(int $guild,int $stock,int $actor,int $delta,int $snapshot): void
    {
        $s=$this->db->prepare("INSERT INTO stock_ledger (guild_id,stock_id,event_type,physical_delta,reserved_delta,actor_user_id,operation_key) VALUES (?,?,'scan_reconcile',?,0,?,?)");
        $s->execute([$guild,$stock,$delta,$actor,'scan-'.$snapshot.'-stock-'.$stock]);
    }
}
