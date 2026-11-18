<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/** Stores validated observations for later authorized reconciliation. No stock mutation. */
final class ScanStaging
{
    public function __construct(private readonly PDO $db) {}

    public function stage(int $guildId, int $actorId, array $import,bool $automatic=false): array
    {
        $this->db->beginTransaction();
        try {
            $guild = $this->db->prepare('SELECT name,realm,wow_flavor FROM guilds WHERE id=? FOR UPDATE');
            $guild->execute([$guildId]);
            $record = $guild->fetch(PDO::FETCH_ASSOC);
            $auth = $this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active' FOR UPDATE");
            $auth->execute([$guildId, $actorId]);
            if (!in_array($auth->fetchColumn(), ['admin','bank_manager','officer'], true)) throw new DomainError('FORBIDDEN');
            if (!$record || $record['wow_flavor'] !== 'mop_classic' || $import['guild'] !== $record['name'] || $import['realm'] !== $record['realm']) {
                throw new DomainError('IMPORT_GUILD_REALM_MISMATCH');
            }
            // Serialize upload admission per guild. Duplicates remain repeatable
            // even after a quota has been reached; only new snapshots consume space.
            $exists = $this->db->prepare('SELECT id FROM stock_snapshots WHERE guild_id=? AND source=? AND source_key=?');
            $exists->execute([$guildId, $import['source'], $import['source_key']]);
            $liveId=$exists->fetchColumn();
            if (!$liveId) {
                $receipt=$this->db->prepare('SELECT original_snapshot_id FROM scan_import_receipts WHERE guild_id=? AND source=? AND source_key=?');
                $receipt->execute([$guildId,$import['source'],$import['source_key']]);
                $originalId=$receipt->fetchColumn();
                if ($originalId !== false) {
                    $this->db->commit();
                    return ['id'=>(int)$originalId,'created'=>false,'status'=>'archived','quality'=>'historical',
                        'stock_changed'=>false,'slots'=>$import['occupied_slots'],'tabs'=>$import['total_tabs'],
                        'variants'=>$import['item_variants'],'quantity'=>$import['total_quantity']];
                }

                // Retention must not accidentally reset the daily rate-limit.
                $quota=$this->db->prepare("SELECT COUNT(*) FROM audit_events WHERE guild_id=? AND action='scan.staged' AND created_at>=?");
                $quota->execute([$guildId,gmdate('Y-m-d H:i:s',time()-86400)]);
                $dailyCount=(int)$quota->fetchColumn();
                $pending=$this->db->prepare("SELECT COUNT(*) FROM stock_snapshots WHERE guild_id=? AND status='pending'");
                $pending->execute([$guildId]);$pendingCount=(int)$pending->fetchColumn();
                if ($pendingCount >= 50 || $dailyCount >= ($automatic ? 120 : 20)) {
                    throw new DomainError('IMPORT_QUOTA_REACHED');
                }
            }
            $s = $this->db->prepare("INSERT INTO stock_snapshots (guild_id,source,source_key,observed_at,quality,status) VALUES (?,?,?,?, 'unverified','pending') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
            $s->execute([$guildId, $import['source'], $import['source_key'], gmdate('Y-m-d H:i:s',$import['observed_at'])]);
            $snapshotId = (int)$this->db->lastInsertId();
            $receipt=$this->db->prepare('INSERT IGNORE INTO scan_import_receipts (guild_id,source,source_key,original_snapshot_id) VALUES (?,?,?,?)');
            $receipt->execute([$guildId,$import['source'],$import['source_key'],$snapshotId]);
            $created = $s->rowCount() === 1;
            if ($created) {
                $insert = $this->db->prepare('INSERT INTO snapshot_source_lines (snapshot_id,tab_no,slot_no,wow_item_id,variant_key,item_link,item_name,quantity) VALUES (?,?,?,?,?,?,?,?)');
                foreach ($import['lines'] as $line) {
                    $insert->execute([$snapshotId, $line['tab_no'], $line['slot_no'], $line['wow_item_id'],
                        $line['variant_key'], $line['item_link'], $line['item_name'], $line['quantity']]);
                }
                $audit = $this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,'scan.staged','stock_snapshot',?,?)");
                $audit->execute([$guildId, $actorId, $snapshotId, json_encode([
                    'source'=>$import['source'], 'source_key'=>$import['source_key'],
                    'tab_count'=>$import['total_tabs'], 'slot_count'=>$import['occupied_slots'],
                    'quality'=>'unverified', 'physical_stock_changed'=>false,
                ], JSON_THROW_ON_ERROR)]);
            }
            // Also accept metadata for a previously uploaded identical snapshot.
            // Never authorizes stock or reservation changes.
            ItemMetadata::persist($this->db,$guildId,$import);
            // Every accepted new snapshot triggers transactional retention for this guild.
            // Existing order/stock ledger, audit and client metadata are untouched.
            if ($created) {
                $all=$this->db->prepare('SELECT id FROM stock_snapshots WHERE guild_id=? ORDER BY id DESC FOR UPDATE');
                $all->execute([$guildId]);$ids=array_map('intval',$all->fetchAll(PDO::FETCH_COLUMN));
                $oldIds=array_slice($ids,5);
                if ($oldIds) {
                    $removeLegacy=$this->db->prepare('DELETE FROM snapshot_lines WHERE snapshot_id=?');
                    $removeSnapshot=$this->db->prepare('DELETE FROM stock_snapshots WHERE id=? AND guild_id=?');
                    foreach ($oldIds as $oldId) {
                        $removeLegacy->execute([$oldId]);
                        $removeSnapshot->execute([$oldId,$guildId]);
                    }
                    $a=$this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,'scan.retention_pruned','stock_snapshot',NULL,?)");
                    $a->execute([$guildId,$actorId,json_encode(['count'=>count($oldIds),'latest_five_kept'=>true],JSON_THROW_ON_ERROR)]);
                }
            }
            $head=$this->db->prepare('SELECT status,quality FROM stock_snapshots WHERE id=? AND guild_id=?');
            $head->execute([$snapshotId,$guildId]);$headRow=$head->fetch(PDO::FETCH_ASSOC);
            $this->db->commit();
            return ['id'=>$snapshotId,'created'=>$created,'status'=>$headRow['status'],'quality'=>$headRow['quality'],
                'slots'=>$import['occupied_slots'],'tabs'=>$import['total_tabs'],'variants'=>$import['item_variants'],
                'quantity'=>$import['total_quantity'],'stock_changed'=>false];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
