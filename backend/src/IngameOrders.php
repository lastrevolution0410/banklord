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
            $out[]=['client_key'=>$key,'code'=>$code,'character'=>$char,'note'=>$note,'guild'=>self::tag($row)];
        }
        return $out;
    }

    /** Guild tag of an outbox row: [name, realm] or null for untagged rows. */
    private static function tag(array $row): ?array {
        $g=$row['guild']??null;$r=$row['guildRealm']??'';
        if (!is_string($g) || $g==='' || strlen($g)>80 || !is_string($r) || strlen($r)>100) return null;
        return [$g,$r];
    }
    private static function norm(string $s): string { return mb_strtolower(str_replace([' ',"'",'-'],'',$s)); }
    /** True when a row belongs to this guild; rows for another guild wait for that guild's connection. */
    public static function forGuild(?array $tag, ?array $record): bool {
        if ($tag===null || $record===null) return true;
        return self::norm($tag[0])===self::norm((string)$record['name']) && ($tag[1]==='' || self::norm($tag[1])===self::norm((string)$record['realm']));
    }

    /** Characters seen in game (BanklordDB.characters) that are in this guild get linked to the uploader. */
    public function syncCharacters(int $guild, int $user, string $savedVars, array $record): int {
        try { $data=(new SavedVariablesParser($savedVars))->parse(); } catch (\Throwable $e) { return 0; }
        $chars=$data['characters']??[];
        if (!is_array($chars)) return 0;
        $n=0;
        foreach (array_slice($chars,0,40,true) as $key=>$row) {
            if (!is_string($key) || !is_array($row) || strlen($key)>120) continue;
            if (!self::forGuild(self::tag($row)??['',''],$record)) continue;
            $this->rememberCharacter($guild,$user,$key);$n++;
        }
        return $n;
    }

    /** @return array<string,array{order_id?:int,error?:string}> keyed by client key */
    public function processOutbox(int $guild, int $user, string $savedVars, ?array $record=null): array {
        try { $entries=self::extract($savedVars); }
        catch (\InvalidArgumentException $e) { return ['_file'=>['error'=>$e->getMessage()]]; }
        $entries=array_values(array_filter($entries,fn($e)=>self::forGuild($e['guild'],$record)));
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
                $id=$orders->create($guild,$user,$entry['client_key'],$lines,mb_substr($note,0,500));
                $results[$entry['client_key']]=['order_id'=>$id];
                if ($entry['character']!=='') {
                    $this->db->prepare('UPDATE orders SET recipient=? WHERE id=? AND recipient IS NULL')->execute([$entry['character'],$id]);
                    $this->rememberCharacter($guild,$user,$entry['character']);
                }
            } catch (DomainError $e) {
                $results[$entry['client_key']]=['error'=>$e->getMessage()];
            }
        }
        return $results;
    }

    /** Links an in-game character ("Name-Realm") to the uploading account once. */
    public function rememberCharacter(int $guild, int $user, string $character): void {
        [$name,$realm]=array_pad(explode('-',$character,2),2,'');
        if (!Community::characterAllowed($name) || mb_strlen($realm)<2 || mb_strlen($realm)>100) return;
        $this->db->prepare("INSERT IGNORE INTO characters (guild_id,user_id,name,realm) VALUES (?,?,?,?)")->execute([$guild,$user,$name,$realm]);
    }

    /** @return list<array{key:string,order:int,action:string}> */
    public static function extractManage(string $savedVars): array {
        $data=(new SavedVariablesParser($savedVars))->parse();
        $rows=$data['manageOutbox']??[];
        if (!is_array($rows) || count($rows)>60) throw new \InvalidArgumentException('MANAGE_OUTBOX_INVALID');
        $out=[];
        foreach ($rows as $row) {
            $key=$row['key']??null;$order=$row['order']??null;$action=$row['action']??null;
            if (!is_string($key) || !preg_match('/^ma-[0-9]{6,12}-[a-z0-9]{6}$/D',$key) || !is_int($order) || $order<1
              || !in_array($action,['deliver','reject'],true)) throw new \InvalidArgumentException('MANAGE_ROW_INVALID');
            $out[]=['key'=>$key,'order'=>$order,'action'=>$action,'guild'=>self::tag($row)];
        }
        return $out;
    }

    /** Bank manager actions queued in game (mail sent, order rejected). */
    public function processManage(int $guild, int $user, string $savedVars, ?array $record=null): array {
        try { $rows=self::extractManage($savedVars); }
        catch (\InvalidArgumentException $e) { return []; }
        $rows=array_filter($rows,fn($r)=>self::forGuild($r['guild'],$record));
        $orders=new Orders($this->db);$results=[];
        foreach ($rows as $r) {
            try {
                $status=$r['action']==='deliver'?$orders->deliver($guild,$user,$r['order'],$r['key']):$orders->reject($guild,$user,$r['order']);
                $results[$r['key']]=['status'=>$status];
            } catch (DomainError $e) { $results[$r['key']]=['error'=>$e->getMessage()]; }
        }
        return $results;
    }

    /** Open orders of the guild for bank managers (null for other roles). */
    public function manageView(int $guild, int $user): ?array {
        $r=$this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'");
        $r->execute([$guild,$user]);
        if (!in_array($r->fetchColumn(),['officer','bank_manager','admin'],true)) return null;
        $s=$this->db->prepare("SELECT o.id,o.status,UNIX_TIMESTAMP(o.created_at) AS created,u.display_name AS requester,
            COALESCE(o.recipient,(SELECT CONCAT(c.name,'-',c.realm) FROM characters c WHERE c.guild_id=o.guild_id AND c.user_id=o.user_id AND c.verification_status<>'revoked' ORDER BY c.verification_status='verified' DESC,c.id LIMIT 1),'') AS recipient
            FROM orders o JOIN users u ON u.id=o.user_id
            WHERE o.guild_id=? AND o.status IN ('submitted','reserved','partial','partially_fulfilled') ORDER BY o.id LIMIT 40");
        $s->execute([$guild]);
        $l=$this->db->prepare("SELECT i.wow_item_id,i.name,ol.requested_qty,ol.approved_qty,ol.fulfilled_qty,ol.decision
            FROM order_lines ol JOIN guild_stock gs ON gs.id=ol.stock_id JOIN items i ON i.id=gs.item_id WHERE ol.order_id=? ORDER BY ol.id LIMIT 12");
        $out=[];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $l->execute([(int)$o['id']]);$lines=[];
            foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $line) {
                if ($line['decision']==='rejected') continue;
                $want=$line['decision']==='approved'?(int)$line['approved_qty']:(int)$line['requested_qty'];
                $open=$want-(int)$line['fulfilled_qty'];
                if ($open>0) $lines[]=['item_id'=>(int)$line['wow_item_id'],'name'=>mb_substr((string)$line['name'],0,80),'qty'=>$open];
            }
            if ($lines) $out[]=['id'=>(int)$o['id'],'status'=>(string)$o['status'],'created_at'=>(int)$o['created'],
                'requester'=>mb_substr((string)$o['requester'],0,60),'recipient'=>mb_substr((string)$o['recipient'],0,80),'lines'=>$lines];
        }
        return $out;
    }

    /** Compact own-order view written into the addon by the companion. */
    public function state(int $guild, int $user, array $outboxResults=[], array $manageResults=[]): array {
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
        $g=$this->db->prepare('SELECT name,realm FROM guilds WHERE id=?');$g->execute([$guild]);$gr=$g->fetch(PDO::FETCH_ASSOC)?:['name'=>'','realm'=>''];
        $state=['generated_at'=>time(),'guild'=>['name'=>(string)$gr['name'],'realm'=>(string)$gr['realm']],'orders'=>$out,'outbox'=>(object)$outbox,'manage_results'=>(object)$manageResults];
        $manage=$this->manageView($guild,$user);
        if ($manage!==null) $state['manage']=$manage;
        return $state;
    }
}
