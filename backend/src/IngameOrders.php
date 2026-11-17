<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/**
 * In-game orders: the addon queues the cart in BanklordDB.orderOutbox, the
 * companion uploads the SavedVariables file with the uploader's personal bridge
 * token. Orders are created for the TOKEN OWNER through Orders::create (same
 * rights, idempotency and audit as the website). The character name in the
 * file is informational only and never grants identity.
 */
final class IngameOrders {
    public const MAX_OUTBOX=10;
    private const STATE_ORDERS=20;
    private const STATE_LINES=12;

    public function __construct(private readonly PDO $db) {}

    /** @return list<array{client_key:string,code:string,character:string,note:string}> */
    public static function extract(string $savedVars): array {
        $data=(new SavedVariablesParser($savedVars))->parse();
        $rows=$data['orderOutbox']??[];
        if (!is_array($rows)) throw new \InvalidArgumentException('OUTBOX_INVALID');
        if (count($rows)>self::MAX_OUTBOX) throw new \InvalidArgumentException('OUTBOX_LIMIT');
        $out=[];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new \InvalidArgumentException('OUTBOX_ROW_INVALID');
            $key=$row['clientKey']??null;$code=$row['code']??null;$char=$row['character']??'';$note=$row['note']??'';$at=$row['createdAt']??null;
            if (!is_string($key) || !preg_match('/^ig-[0-9]{6,12}-[a-z0-9]{6}$/D',$key)
              || !is_string($code) || !PeerRelay::validCode($code)
              || !is_string($char) || strlen($char)>80 || preg_match('/[|\x00-\x1f\x7f]/',$char)
              || !is_string($note) || strlen($note)>200 || preg_match('/[|\x00-\x1f\x7f]/',$note)
              || !is_int($at) || $at<time()-30*86400 || $at>time()+86400) throw new \InvalidArgumentException('OUTBOX_ROW_INVALID');
            $out[]=['client_key'=>$key,'code'=>$code,'character'=>$char,'note'=>$note];
        }
        return $out;
    }

    /** @return array<string,array{order_id?:int,error?:string}> keyed by client key */
    public function processOutbox(int $guild, int $user, string $savedVars): array {
        try { $entries=self::extract($savedVars); }
        catch (\InvalidArgumentException $e) { return ['_file'=>['error'=>$e->getMessage()]]; }
        $orders=new Orders($this->db);$results=[];
        $find=$this->db->prepare("SELECT gs.id FROM guild_stock gs JOIN items i ON i.id=gs.item_id
            WHERE gs.guild_id=? AND i.wow_flavor='mop_classic' AND i.wow_item_id=? AND i.variant_key=?");
        foreach ($entries as $entry) {
            $lines=[];$missing=false;
            foreach (explode('|',substr($entry['code'],4)) as $part) {
                [$itemId,$qty,$variant]=explode(',',$part,3);
                $find->execute([$guild,(int)$itemId,$variant]);$stock=$find->fetchColumn();
                if ($stock===false) {$missing=true;break;}
                $lines[]=['stock_id'=>(int)$stock,'quantity'=>(int)$qty];
            }
            if ($missing) {$results[$entry['client_key']]=['error'=>'UNKNOWN_ITEM'];continue;}
            $note='Ingame'.($entry['character']!==''?' ('.$entry['character'].')':'').($entry['note']!==''?': '.$entry['note']:'');
            try {
                $results[$entry['client_key']]=['order_id'=>$orders->create($guild,$user,$entry['client_key'],$lines,mb_substr($note,0,500))];
            } catch (DomainError $e) {
                $results[$entry['client_key']]=['error'=>$e->getMessage()];
            }
        }
        return $results;
    }

    /** Compact own-order view written into the addon by the companion. */
    public function state(int $guild, int $user, array $outboxResults=[]): array {
        $s=$this->db->prepare('SELECT id,client_key,status,UNIX_TIMESTAMP(created_at) AS created FROM orders
            WHERE guild_id=? AND user_id=? ORDER BY id DESC LIMIT '.self::STATE_ORDERS);
        $s->execute([$guild,$user]);$orders=$s->fetchAll(PDO::FETCH_ASSOC);
        $l=$this->db->prepare('SELECT i.wow_item_id,i.name,ol.requested_qty,ol.approved_qty,ol.fulfilled_qty,ol.decision
            FROM order_lines ol JOIN guild_stock gs ON gs.id=ol.stock_id JOIN items i ON i.id=gs.item_id
            WHERE ol.order_id=? ORDER BY ol.id LIMIT '.self::STATE_LINES);
        $out=[];
        foreach ($orders as $o) {
            $l->execute([(int)$o['id']]);$lines=[];
            foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $line) $lines[]=[
                'item_id'=>(int)$line['wow_item_id'],'name'=>mb_substr((string)$line['name'],0,80),
                'requested'=>(int)$line['requested_qty'],'approved'=>(int)$line['approved_qty'],
                'fulfilled'=>(int)$line['fulfilled_qty'],'decision'=>(string)$line['decision']];
            $out[]=['id'=>(int)$o['id'],'client_key'=>(string)$o['client_key'],'status'=>(string)$o['status'],
                'created_at'=>(int)$o['created'],'lines'=>$lines];
        }
        $outbox=[];
        foreach ($outboxResults as $key=>$r) if ($key!=='_file') $outbox[$key]=$r;
        return ['generated_at'=>time(),'orders'=>$out,'outbox'=>(object)$outbox];
    }
}
