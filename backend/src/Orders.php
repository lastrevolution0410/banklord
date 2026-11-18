<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/** Transactional command handlers; call only with authenticated server-side userId. */
final class Orders
{
    public function __construct(private PDO $db) {}

    private function activeRole(int $guild, int $user, string $action): string
    {
        $lock = $this->db->inTransaction() ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'" . $lock);
        $stmt->execute([$guild,$user]);
        $role = $stmt->fetchColumn();
        if (!$role || !Domain::roleCan($role,$action)) throw new DomainError('FORBIDDEN');
        return $role;
    }

    public function create(int $guild, int $user, string $key, array $lines, string $note='', bool $urgent=false): int
    {
        $this->activeRole($guild,$user,'order.submit');
        if (!preg_match('/^[a-zA-Z0-9_-]{12,100}$/D', $key)) throw new DomainError('BAD_REQUEST_KEY');
        if (count($lines) < 1 || count($lines) > 100) throw new DomainError('INVALID_LINES');
        if (strlen($note) > 500) throw new DomainError('NOTE_TOO_LONG');
        $combined=[];
        foreach ($lines as $l) {
            if (!is_array($l) || !isset($l['stock_id'], $l['quantity']) || !is_int($l['stock_id']) || $l['stock_id'] < 1) throw new DomainError('BAD_LINE');
            $quantity = Domain::quantity($l['quantity']);
            $stock = $l['stock_id'];
            $combined[$stock] = ($combined[$stock] ?? 0) + $quantity;
            Domain::quantity($combined[$stock]);
        }
        ksort($combined, SORT_NUMERIC);
        $fingerprint = hash('sha256', json_encode(['lines'=>$combined,'note'=>$note], JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try {
            $this->activeRole($guild,$user,'order.submit');
            $s=$this->db->prepare('SELECT id,request_fingerprint FROM orders WHERE guild_id=? AND user_id=? AND client_key=?');
            $s->execute([$guild,$user,$key]);
            $existing = $s->fetch();
            if ($existing) {
                if (!hash_equals($existing['request_fingerprint'],$fingerprint)) throw new DomainError('IDEMPOTENCY_KEY_REUSED');
                $this->db->commit(); return (int)$existing['id'];
            }
            $in=implode(',',array_fill(0,count($combined),'?'));
            $s=$this->db->prepare("SELECT id FROM guild_stock WHERE guild_id=? AND id IN ($in)");
            $s->execute(array_merge([$guild],array_keys($combined)));
            if (count($s->fetchAll()) !== count($combined)) throw new DomainError('FOREIGN_OR_UNKNOWN_STOCK');
            // View-only bank tabs: their share of an item is shown but cannot be ordered.
            $v=$this->db->prepare("SELECT id,physical_qty,reserved_qty,viewonly_qty FROM guild_stock WHERE guild_id=? AND id IN ($in)");
            $v->execute(array_merge([$guild],array_keys($combined)));
            foreach ($v->fetchAll() as $row) {
                if ((int)$row['viewonly_qty']>0 && $combined[(int)$row['id']]>(int)$row['physical_qty']-(int)$row['reserved_qty']-(int)$row['viewonly_qty']) throw new DomainError('VIEW_ONLY_STOCK');
            }
            $rules=new OrderRules($this->db);
            $limit=OrderRules::limitFor($rules->get($guild),$this->activeRole($guild,$user,'order.submit'));
            $approval=$limit>0 && $rules->weeklyUsed($guild,$user)+array_sum($combined)>$limit ? 1 : 0;
            $s=$this->db->prepare('INSERT INTO orders (guild_id,user_id,client_key,request_fingerprint,note,needs_approval,priority) VALUES (?,?,?,?,?,?,?)');
            $s->execute([$guild,$user,$key,$fingerprint,$note,$approval,$urgent?1:0]);
            $order=(int)$this->db->lastInsertId();
            $s=$this->db->prepare('INSERT INTO order_lines (order_id,stock_id,requested_qty) VALUES (?,?,?)');
            foreach ($combined as $stock=>$quantity) $s->execute([$order,$stock,$quantity]);
            $this->audit($guild,$user,'order.create','order',$order,[]);
            $this->notifyManagers($guild,$user,$order);
            $who=$this->db->prepare('SELECT display_name FROM users WHERE id=?');$who->execute([$user]);
            $summary=$this->summary($order);
            Discord::queue($this->db,$guild,'order',($urgent?'🔴 Dringend: ':'').'Neue Bestellung #'.$order.' von '.$who->fetchColumn().': '.$summary.($note!==''?' – „'.mb_substr($note,0,120).'“':''));
            if ($approval) Discord::queue($this->db,$guild,'approval','Bestellung #'.$order.' liegt über dem Wochenlimit und braucht die Freigabe eines Offiziers.');
            $this->db->commit(); return $order;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            // A concurrent duplicate transaction can win the unique index race.
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                $s=$this->db->prepare('SELECT id,request_fingerprint FROM orders WHERE guild_id=? AND user_id=? AND client_key=?');
                $s->execute([$guild,$user,$key]);
                $row=$s->fetch();
                if ($row) {
                    if (!hash_equals($row['request_fingerprint'],$fingerprint)) throw new DomainError('IDEMPOTENCY_KEY_REUSED');
                    return (int)$row['id'];
                }
            }
            throw $e;
        }
    }

    /** Approve/reject ALL lines once, as an atomic operation. No approval without known verified stock. */
    public function decide(int $guild,int $actor,int $orderId,array $decisions): string
    {
        $this->db->beginTransaction();
        try {
            $role=$this->activeRole($guild,$actor,'order.approve');
            $o=$this->lockOrder($guild,$orderId);
            // A partly mailed order whose lines are still undecided can be decided for the rest.
            $partlyMailed=$o['status']==='partially_fulfilled';
            if ($o['status'] !== 'submitted' && !$partlyMailed) throw new DomainError('ORDER_ALREADY_DECIDED');
            $this->clearApproval($guild,$actor,$role,$o,$decisions);
            $s=$this->db->prepare('SELECT id,stock_id,requested_qty,fulfilled_qty,decision FROM order_lines WHERE order_id=? ORDER BY stock_id,id FOR UPDATE');
            $s->execute([$orderId]); $rows=$s->fetchAll();
            if ($partlyMailed) foreach ($rows as $r) if ($r['decision']!=='pending') throw new DomainError('ORDER_ALREADY_DECIDED');
            if (count($rows) !== count($decisions)) throw new DomainError('DECISION_MISMATCH');
            $counts=[];
            foreach ($rows as $r) {
                $id=(int)$r['id'];
                if (!array_key_exists($id,$decisions)) throw new DomainError('MISSING_DECISION');
                $amount=$decisions[$id];
                if (!is_int($amount) || $amount < (int)$r['fulfilled_qty'] || $amount > (int)$r['requested_qty']) throw new DomainError('BAD_APPROVAL');
                $counts[$id]=$amount;
            }
            $st=$this->db->prepare("UPDATE guild_stock SET reserved_qty=reserved_qty+?,stock_version=stock_version+1 WHERE id=? AND guild_id=? AND confidence='verified' AND observed_at >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE AND physical_qty >= reserved_qty + viewonly_qty + ?");
            $ul=$this->db->prepare("UPDATE order_lines SET approved_qty=?,decision=? WHERE id=? AND order_id=?");
            foreach ($rows as $r) {
                $id=(int)$r['id']; $quantity=$counts[$id];
                // Already mailed pieces need no reservation.
                $reserve=$quantity-(int)$r['fulfilled_qty'];
                if ($reserve > 0) {
                    $st->execute([$reserve,(int)$r['stock_id'],$guild,$reserve]);
                    if ($st->rowCount() !== 1) throw new DomainError('INSUFFICIENT_OR_UNVERIFIED_STOCK');
                    $this->ledger($guild,(int)$r['stock_id'],$id,'reserve',0,$reserve,$actor,'reserve-line-'.$id);
                }
                $ul->execute([$quantity,$quantity ? 'approved':'rejected',$id,$orderId]);
            }
            $state=$this->updateState($orderId);
            $this->audit($guild,$actor,'order.decide','order',$orderId,['state'=>$state]);
            $this->notifyOwner($guild,$orderId,'order.decided');
            $this->db->commit(); return $state;
        } catch (\Throwable $e) { if($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    /** Record verified physical handover; idempotency is scoped to the order line + op key. */
    public function fulfill(int $guild,int $actor,int $orderId,int $lineId,int $quantity,string $operationKey): string
    {
        Domain::quantity($quantity);
        if (!preg_match('/^[a-zA-Z0-9_-]{12,100}$/D',$operationKey)) throw new DomainError('BAD_OPERATION_KEY');
        $this->db->beginTransaction();
        try {
            $this->activeRole($guild,$actor,'order.fulfill');
            $order=$this->lockOrder($guild,$orderId);
            if (!in_array($order['status'],['reserved','partial','partially_fulfilled','fulfilled'],true)) throw new DomainError('ORDER_NOT_FULFILLABLE');
            $check=$this->db->prepare('SELECT order_line_id,physical_delta,event_type FROM stock_ledger WHERE guild_id=? AND operation_key=?');
            $check->execute([$guild,$operationKey]);
            if ($prior=$check->fetch()) {
                if ((int)$prior['order_line_id'] !== $lineId || (int)$prior['physical_delta'] !== -$quantity || $prior['event_type'] !== 'fulfill') {
                    throw new DomainError('IDEMPOTENCY_KEY_REUSED');
                }
                $this->db->commit(); return $order['status'];
            }
            $s=$this->db->prepare('SELECT * FROM order_lines WHERE id=? AND order_id=? FOR UPDATE');
            $s->execute([$lineId,$orderId]); $line=$s->fetch();
            if (!$line || $line['decision'] !== 'approved') throw new DomainError('LINE_NOT_APPROVED');
            if ((int)$line['approved_qty'] - (int)$line['fulfilled_qty'] < $quantity) throw new DomainError('OVERFULFILL');
            $s=$this->db->prepare('UPDATE guild_stock SET physical_qty=physical_qty-?,reserved_qty=reserved_qty-?,stock_version=stock_version+1 WHERE id=? AND guild_id=? AND physical_qty>=? AND reserved_qty>=?');
            $s->execute([$quantity,$quantity,(int)$line['stock_id'],$guild,$quantity,$quantity]);
            if ($s->rowCount() !== 1) throw new DomainError('STOCK_CONFLICT');
            $s=$this->db->prepare('UPDATE order_lines SET fulfilled_qty=fulfilled_qty+? WHERE id=?');
            $s->execute([$quantity,$lineId]);
            $this->ledger($guild,(int)$line['stock_id'],$lineId,'fulfill',-$quantity,-$quantity,$actor,$operationKey);
            $state=$this->updateState($orderId);
            $this->audit($guild,$actor,'order.fulfill','order',$orderId,['line_id'=>$lineId,'qty'=>$quantity]);
            $this->notifyOwner($guild,$orderId,'order.fulfilled');
            $this->db->commit();return $state;
        } catch(\Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }


    /** Cancel pending order or unfulfilled remainder; already fulfilled quantities stay booked. */
    public function cancel(int $guild,int $actor,int $orderId): string
    {
        return $this->cancelOrder($guild,$actor,$orderId,false);
    }

    /** Auto-expiry (guild rule): cancels the open rest of an old order without an acting user. */
    public function expire(int $guild,int $orderId): string
    {
        return $this->cancelOrder($guild,0,$orderId,true);
    }

    private function cancelOrder(int $guild,int $actor,int $orderId,bool $system): string
    {
        $this->db->beginTransaction();
        try {
            $order=$this->lockOrder($guild,$orderId);
            if ($system) $actor=(int)$order['user_id'];
            else {
                $role=$this->activeRole($guild,$actor,'order.submit');
                if ((int)$order['user_id']!==$actor && !in_array($role,['officer','bank_manager','admin'],true)) throw new DomainError('FORBIDDEN');
            }
            if ($order['status']==='cancelled') {$this->db->commit();return 'cancelled';}
            if (!in_array($order['status'],['submitted','reserved','partial','partially_fulfilled'],true)) throw new DomainError('ORDER_NOT_CANCELLABLE');
            $s=$this->db->prepare('SELECT id,stock_id,approved_qty,fulfilled_qty,decision FROM order_lines WHERE order_id=? ORDER BY stock_id,id FOR UPDATE');
            $s->execute([$orderId]);
            foreach ($s->fetchAll() as $line) {
                if ($line['decision']!=='approved') continue;
                $remaining=(int)$line['approved_qty']-(int)$line['fulfilled_qty'];
                if ($remaining < 0) throw new DomainError('STOCK_CONFLICT');
                if (!$remaining) continue;
                $up=$this->db->prepare('UPDATE guild_stock SET reserved_qty=reserved_qty-?,stock_version=stock_version+1 WHERE id=? AND guild_id=? AND reserved_qty>=?');
                $up->execute([$remaining,$line['stock_id'],$guild,$remaining]);
                if($up->rowCount()!==1)throw new DomainError('STOCK_CONFLICT');
                $this->ledger($guild,(int)$line['stock_id'],(int)$line['id'],'release',0,-$remaining,$actor,'cancel-line-'.$line['id']);
            }
            // Delivered parts stay booked: a partly delivered order ends as fulfilled for what arrived.
            $d=$this->db->prepare('SELECT COALESCE(SUM(fulfilled_qty),0) FROM order_lines WHERE order_id=?');$d->execute([$orderId]);
            $final=(int)$d->fetchColumn()>0?'fulfilled':'cancelled';
            if ($final==='fulfilled') {
                $this->db->prepare("UPDATE order_lines SET approved_qty=fulfilled_qty,decision=IF(fulfilled_qty>0,'approved','rejected') WHERE order_id=?")->execute([$orderId]);
            }
            $s=$this->db->prepare('UPDATE orders SET status=?,needs_approval=0 WHERE id=? AND guild_id=?');$s->execute([$final,$orderId,$guild]);
            $this->audit($guild,$actor,$system?'order.expired':'order.cancel','order',$orderId,$system?['reason'=>'expired']:[]);
            $this->notifyOwner($guild,$orderId,'order.cancelled');
            $this->db->commit();return $final;
        } catch (\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /**
     * In-game handover by mail: the bank manager took the items from the bank
     * and sent them. Pending lines count as approved in full; reservations are
     * released because the next bank scan already lowers the physical stock.
     */
    public function deliver(int $guild,int $actor,int $orderId,string $key,?array $only=null): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{12,80}$/D',$key)) throw new DomainError('BAD_OPERATION_KEY');
        if ($only!==null) {
            if (!$only || count($only)>12) throw new DomainError('BAD_PARTIAL_LINES');
            foreach ($only as $item=>$qty) if (!is_int($item) || $item<1 || !is_int($qty) || $qty<1 || $qty>1000000) throw new DomainError('BAD_PARTIAL_LINES');
        }
        $this->db->beginTransaction();
        try {
            $this->activeRole($guild,$actor,'order.fulfill');
            $order=$this->lockOrder($guild,$orderId);
            if ($order['status']==='fulfilled') {$this->db->commit();return 'fulfilled';}
            // Expiry may have run while a manager was already on the way to the
            // mailbox: the mail went out, so it is still booked. Its reservations
            // were released by the expiry and must not be released twice.
            $expired=$order['status']==='cancelled' && $this->wasExpired($guild,$orderId);
            if (!$expired && !in_array($order['status'],['submitted','reserved','partial','partially_fulfilled'],true)) throw new DomainError('ORDER_NOT_FULFILLABLE');
            if ((int)$order['needs_approval']===1) throw new DomainError('APPROVAL_REQUIRED');
            // The same in-game handover can be uploaded more than once: book it once.
            $seen=$this->db->prepare('SELECT COUNT(*) FROM stock_ledger WHERE guild_id=? AND operation_key LIKE ?');
            $seen->execute([$guild,$key.'-%']);
            if ((int)$seen->fetchColumn()>0) {$this->db->commit();return (string)$order['status'];}
            $s=$this->db->prepare('SELECT ol.*,i.wow_item_id FROM order_lines ol JOIN guild_stock gs ON gs.id=ol.stock_id JOIN items i ON i.id=gs.item_id WHERE ol.order_id=? ORDER BY ol.stock_id,ol.id FOR UPDATE');
            $s->execute([$orderId]);
            $rel=$this->db->prepare('UPDATE guild_stock SET reserved_qty=GREATEST(reserved_qty-?,0),stock_version=stock_version+1 WHERE id=? AND guild_id=?');
            $up=$this->db->prepare("UPDATE order_lines SET approved_qty=?,fulfilled_qty=?,decision='approved' WHERE id=?");
            $sent=[];
            foreach ($s->fetchAll() as $line) {
                if ($line['decision']==='rejected') continue;
                $target=$line['decision']==='approved'?(int)$line['approved_qty']:(int)$line['requested_qty'];
                $done=(int)$line['fulfilled_qty'];
                if ($target<=$done) continue;
                $give=$target-$done;
                if ($only!==null) {
                    $item=(int)$line['wow_item_id'];
                    $give=min($give,$only[$item]??0);
                    if ($give<=0) continue;
                    $only[$item]-=$give;
                }
                $reserved=$line['decision']==='approved' && !$expired?min($give,(int)$line['approved_qty']-$done):0;
                if ($reserved>0) $rel->execute([$reserved,(int)$line['stock_id'],$guild]);
                if ($line['decision']!=='approved' && $done+$give<$target) {
                    // Partly sent, not decided yet: the line stays open for the rest.
                    $this->db->prepare('UPDATE order_lines SET approved_qty=?,fulfilled_qty=? WHERE id=?')->execute([$done+$give,$done+$give,(int)$line['id']]);
                } else $up->execute([$target,$done+$give,(int)$line['id']]);
                $this->ledger($guild,(int)$line['stock_id'],(int)$line['id'],'fulfill',0,-$reserved,$actor,$key.'-'.$line['id']);
                $sent[(int)$line['id']]=$give;
            }
            if (!$sent) {$this->db->commit();return (string)$order['status'];}
            $state=$this->updateState($orderId);
            if ($expired && $state!=='fulfilled') {
                // The rest of an expired order stays closed.
                $this->db->prepare("UPDATE order_lines SET approved_qty=fulfilled_qty,decision=IF(fulfilled_qty>0,'approved','rejected') WHERE order_id=?")->execute([$orderId]);
                $state=$this->updateState($orderId);
            }
            $this->audit($guild,$actor,'order.deliver','order',$orderId,['via'=>'ingame-mail','partial'=>$only!==null,'lines'=>$sent]);
            $this->notifyOwner($guild,$orderId,'order.fulfilled');
            $who=$this->db->prepare('SELECT u.display_name FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?');$who->execute([$orderId]);
            Discord::queue($this->db,$guild,'shipped','📦 Bestellung #'.$orderId.' von '.$who->fetchColumn().($state==='fulfilled'?' ist verschickt.':' ist teilweise verschickt, der Rest folgt.'));
            $this->db->commit();return $state;
        } catch(\Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    /** Manager rejects an order: undecided orders are declined, others cancelled. */
    public function reject(int $guild,int $actor,int $orderId): string
    {
        $s=$this->db->prepare('SELECT status FROM orders WHERE guild_id=? AND id=?');$s->execute([$guild,$orderId]);
        $status=$s->fetchColumn();
        if ($status===false) throw new DomainError('ORDER_NOT_FOUND');
        if (in_array($status,['rejected','cancelled'],true)) return (string)$status;
        if ($status!=='submitted') {
            $this->activeRole($guild,$actor,'order.approve');
            return $this->cancel($guild,$actor,$orderId);
        }
        $l=$this->db->prepare('SELECT id FROM order_lines WHERE order_id=?');$l->execute([$orderId]);
        $decisions=[];foreach ($l->fetchAll() as $r) $decisions[(int)$r['id']]=0;
        return $this->decide($guild,$actor,$orderId,$decisions);
    }

    private function wasExpired(int $guild,int $orderId): bool
    {
        $s=$this->db->prepare("SELECT COUNT(*) FROM audit_events WHERE guild_id=? AND entity_type='order' AND entity_id=? AND action='order.expired' AND created_at>=UTC_TIMESTAMP()-INTERVAL 14 DAY");
        $s->execute([$guild,$orderId]);return (int)$s->fetchColumn()>0;
    }

    /** Officers and admins release an order that is above the weekly limit of its member. */
    public function approve(int $guild,int $actor,int $orderId): string
    {
        $this->db->beginTransaction();
        try {
            $role=$this->activeRole($guild,$actor,'order.approve');
            if (!in_array($role,['officer','admin'],true)) throw new DomainError('FORBIDDEN');
            $order=$this->lockOrder($guild,$orderId);
            if (!in_array($order['status'],['submitted','reserved','partial','partially_fulfilled'],true)) throw new DomainError('ORDER_NOT_FULFILLABLE');
            if ((int)$order['needs_approval']===1) {
                $this->db->prepare('UPDATE orders SET needs_approval=0 WHERE id=?')->execute([$orderId]);
                $this->audit($guild,$actor,'order.limit_approved','order',$orderId,[]);
            }
            $this->db->commit();return (string)$order['status'];
        } catch(\Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    /** Deciding an order above the limit: rejecting is always allowed, approving needs an officer or admin. */
    private function clearApproval(int $guild,int $actor,string $role,array $order,array $decisions): void
    {
        if ((int)$order['needs_approval']!==1) return;
        if (array_sum($decisions)===0) {$this->db->prepare('UPDATE orders SET needs_approval=0 WHERE id=?')->execute([(int)$order['id']]);return;}
        if (!in_array($role,['officer','admin'],true)) throw new DomainError('APPROVAL_REQUIRED');
        $this->db->prepare('UPDATE orders SET needs_approval=0 WHERE id=?')->execute([(int)$order['id']]);
        $this->audit($guild,$actor,'order.limit_approved','order',(int)$order['id'],[]);
    }

    /** Short text like "20× Windwollstoff, 5× Erz" for messages. */
    private function summary(int $order): string
    {
        $s=$this->db->prepare('SELECT GROUP_CONCAT(CONCAT(ol.requested_qty,\'× \',i.name) ORDER BY ol.id SEPARATOR \', \') FROM order_lines ol JOIN guild_stock gs ON gs.id=ol.stock_id JOIN items i ON i.id=gs.item_id WHERE ol.order_id=?');
        $s->execute([$order]);return mb_substr((string)$s->fetchColumn(),0,600);
    }

    private function notifyOwner(int $guild,int $order,string $kind): void
    {
        $s=$this->db->prepare('INSERT INTO notifications (guild_id,user_id,kind,order_id) SELECT guild_id,user_id,?,id FROM orders WHERE guild_id=? AND id=?');
        $s->execute([$kind,$guild,$order]);
    }
    private function notifyManagers(int $guild,int $user,int $order): void
    {
        $s=$this->db->prepare("INSERT INTO notifications (guild_id,user_id,kind,order_id)
          SELECT guild_id,user_id,'order.submitted',? FROM guild_memberships
          WHERE guild_id=? AND status='active' AND role IN ('officer','bank_manager','admin') AND user_id<>?");
        $s->execute([$order,$guild,$user]);
    }

    private function lockOrder(int $guild,int $order): array
    {
        $s=$this->db->prepare('SELECT * FROM orders WHERE guild_id=? AND id=? FOR UPDATE');
        $s->execute([$guild,$order]); $row=$s->fetch();
        if (!$row) throw new DomainError('ORDER_NOT_FOUND'); return $row;
    }
    private function updateState(int $order): string
    {
        $s=$this->db->prepare('SELECT decision,requested_qty,approved_qty,fulfilled_qty FROM order_lines WHERE order_id=?');
        $s->execute([$order]); $lines=$s->fetchAll(); $status=Domain::orderState($lines);
        // Partly sent by in-game mail before a decision: show it as partly delivered.
        if ($status==='submitted' && array_sum(array_map(fn($l)=>(int)$l['fulfilled_qty'],$lines))>0) $status='partially_fulfilled';
        $s=$this->db->prepare('UPDATE orders SET status=? WHERE id=?');
        $s->execute([$status,$order]);return $status;
    }
    private function ledger(int $guild,int $stock,int $line,string $type,int $physical,int $reserved,int $actor,string $key): void
    {
        $s=$this->db->prepare('INSERT INTO stock_ledger (guild_id,stock_id,order_line_id,event_type,physical_delta,reserved_delta,actor_user_id,operation_key) VALUES (?,?,?,?,?,?,?,?)');
        $s->execute([$guild,$stock,$line,$type,$physical,$reserved,$actor,$key]);
    }
    private function audit(int $guild,int $actor,string $action,string $type,int $entity,array $details): void
    {
        $s=$this->db->prepare('INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,?,?,?,?)');
        $s->execute([$guild,$actor,$action,$type,$entity,json_encode($details,JSON_THROW_ON_ERROR)]);
    }
}
