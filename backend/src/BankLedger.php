<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/**
 * Guild bank ledger. WoW only shows the last 25 entries per tab and for gold;
 * the addon reads these logs whenever a bank manager opens the bank and the
 * server keeps them permanently. Times in the game log are relative ("3 hours
 * ago"), so entries are matched within one hour to avoid double entries.
 */
final class BankLedger
{
    public const ITEM_ACTIONS=['deposit','withdraw','move'];
    public const MONEY_ACTIONS=['deposit','withdraw','repair','withdrawForTab','buyTab','depositSummary'];
    private const MAX_ROWS=600;

    public function __construct(private readonly PDO $db) {}

    /**
     * Reads BanklordDB.bankLog of an uploaded SavedVariables file and stores new
     * entries, the bank's gold and the item prices. Returns counts for the companion.
     */
    public function process(int $guild, int $actor, string $savedVars, array $record): array
    {
        try { $data=(new SavedVariablesParser($savedVars))->parse(); }
        catch (\InvalidArgumentException $e) { return ['status'=>'unreadable']; }
        $log=$data['bankLog']??null;
        if (!is_array($log)) return ['status'=>'none'];
        if (!is_string($log['guild']??null) || !IngameOrders::forGuild([$log['guild'],(string)($log['realm']??'')],$record)) return ['status'=>'other_guild'];
        $at=$log['capturedAt']??null;
        if (!is_int($at) || $at>time()+300 || $at<time()-30*86400) return ['status'=>'too_old'];
        $rows=[];
        foreach (['items'=>'item','gold'=>'money'] as $key=>$kind) {
            $list=$log[$key]??[];
            if (!is_array($list) || count($list)>self::MAX_ROWS) return ['status'=>'invalid'];
            foreach ($list as $e) { $r=$this->row($kind,$e,$at); if ($r===null) return ['status'=>'invalid']; $rows[]=$r; }
        }
        $added=$this->store($guild,$actor,$rows);
        $money=$log['money']??null;
        if (is_int($money) && $money>=0 && $money<10**15) {
            $this->db->prepare('INSERT INTO guild_bank_money (guild_id,copper,observed_at,updated_by) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE copper=IF(VALUES(observed_at)>=observed_at,VALUES(copper),copper),updated_by=IF(VALUES(observed_at)>=observed_at,VALUES(updated_by),updated_by),observed_at=GREATEST(observed_at,VALUES(observed_at))')
                ->execute([$guild,$money,gmdate('Y-m-d H:i:s',$at),$actor]);
        }
        $priced=$this->prices($guild,$log['prices']??[],$at);
        return ['status'=>'ok','added'=>$added,'priced'=>$priced];
    }

    /** Validates one addon entry; null = malformed file. */
    private function row(string $kind, mixed $e, int $capturedAt): ?array
    {
        if (!is_array($e)) return null;
        $action=$e['t']??null;$player=$e['p']??null;$qty=$e['q']??null;$when=$e['at']??null;
        if (!in_array($action,$kind==='item'?self::ITEM_ACTIONS:self::MONEY_ACTIONS,true)) return null;
        if (!is_string($player) || $player==='' || strlen($player)>80 || preg_match('/[\x00-\x1f\x7f|]/',$player)) return null;
        if (!is_int($qty) || $qty<0 || $qty>10**15 || !is_int($when) || $when>$capturedAt+3600 || $when<$capturedAt-5*366*86400) return null;
        $item=null;$name=null;$tab=null;$to=null;
        if ($kind==='item') {
            $item=$e['i']??null;$name=$e['n']??'';$tab=$e['tab']??null;$to=$e['to']??null;
            if (!is_int($item) || $item<1 || $item>99999999 || !is_string($name) || strlen($name)>200 || preg_match('/[\x00-\x1f\x7f]/',$name)) return null;
            if (!is_int($tab) || $tab<1 || $tab>8 || ($to!==null && (!is_int($to) || $to<1 || $to>8))) return null;
        }
        return ['kind'=>$kind,'action'=>$action,'player'=>$player,'item'=>$item,'name'=>$name,'qty'=>$qty,'tab'=>$tab,'to'=>$to,'at'=>$when-$when%3600];
    }

    /** Inserts entries not yet known; a stored entry at most one hour apart counts as the same. */
    private function store(int $guild, int $actor, array $rows): int
    {
        if (!$rows) return 0;
        $min=min(array_column($rows,'at'))-3600;$max=max(array_column($rows,'at'))+3600;
        $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT id FROM guilds WHERE id=? FOR UPDATE')->execute([$guild]);
            $s=$this->db->prepare('SELECT id,kind,action,player,wow_item_id,quantity,tab_no,tab_to,UNIX_TIMESTAMP(happened_at) AS at FROM guild_ledger
                WHERE guild_id=? AND happened_at BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?)');
            $s->execute([$guild,$min,$max]);
            $known=[];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $k) $known[self::sig($k['kind'],$k['action'],$k['player'],$k['wow_item_id'],(int)$k['quantity'],$k['tab_no'],$k['tab_to'])][]=['at'=>(int)$k['at'],'used'=>false];
            $ins=$this->db->prepare('INSERT INTO guild_ledger (guild_id,kind,action,player,wow_item_id,item_name,quantity,tab_no,tab_to,happened_at,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,FROM_UNIXTIME(?),?)');
            usort($rows,fn($a,$b)=>$a['at']<=>$b['at']);
            $added=0;
            foreach ($rows as $r) {
                $sig=self::sig($r['kind'],$r['action'],$r['player'],$r['item'],$r['qty'],$r['tab'],$r['to']);
                $match=false;
                foreach ($known[$sig]??[] as $i=>$k) if (!$k['used'] && abs($k['at']-$r['at'])<=3600) {$known[$sig][$i]['used']=true;$match=true;break;}
                if ($match) continue;
                $ins->execute([$guild,$r['kind'],$r['action'],$r['player'],$r['item'],$r['kind']==='item'?$r['name']:null,$r['qty'],$r['tab'],$r['to'],$r['at'],$actor]);
                $known[$sig][]=['at'=>$r['at'],'used'=>true];$added++;
            }
            $this->db->commit();return $added;
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    private static function sig(string $kind, string $action, string $player, mixed $item, int $qty, mixed $tab, mixed $to): string
    {
        return implode('|',[$kind,$action,mb_strtolower($player),(int)$item,$qty,(int)$tab,(int)$to]);
    }

    /** Market prices (copper per piece) from TSM or Auctionator, as captured in game. */
    private function prices(int $guild, mixed $list, int $at): int
    {
        if (!is_array($list) || count($list)>2000) return 0;
        $u=$this->db->prepare("UPDATE guild_stock gs JOIN items i ON i.id=gs.item_id SET gs.unit_price=?,gs.price_source=?,gs.price_at=FROM_UNIXTIME(?)
            WHERE gs.guild_id=? AND i.wow_item_id=? AND (gs.price_at IS NULL OR gs.price_at<=FROM_UNIXTIME(?))");
        $n=0;
        foreach ($list as $p) {
            if (!is_array($p) || !is_int($p['i']??null) || !is_int($p['c']??null) || $p['c']<0 || $p['c']>10**13 || !in_array($p['s']??null,['tsm','auctionator'],true)) continue;
            $u->execute([$p['c'],$p['s'],$at,$guild,$p['i'],$at]);$n+=$u->rowCount();
        }
        return $n;
    }

    /** Page of ledger entries, newest first, with optional filters. */
    public function entries(int $guild, string $kind='', string $player='', int $days=90, int $limit=200): array
    {
        $sql='SELECT id,kind,action,player,wow_item_id,item_name,quantity,tab_no,tab_to,happened_at FROM guild_ledger WHERE guild_id=? AND happened_at>=UTC_TIMESTAMP()-INTERVAL ? DAY';
        $args=[$guild,max(1,min($days,3650))];
        if (in_array($kind,['item','money'],true)) {$sql.=' AND kind=?';$args[]=$kind;}
        if ($player!=='') {$sql.=' AND player LIKE ?';$args[]='%'.addcslashes($player,'%_\\').'%';}
        $sql.=' ORDER BY happened_at DESC,id DESC LIMIT '.max(1,min($limit,2000));
        $s=$this->db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Gold balance and per-player totals over the given number of days. */
    public function summary(int $guild, int $days=30): array
    {
        $m=$this->db->prepare('SELECT copper,observed_at FROM guild_bank_money WHERE guild_id=?');$m->execute([$guild]);$money=$m->fetch(PDO::FETCH_ASSOC)?:null;
        $s=$this->db->prepare("SELECT player,
              SUM(CASE WHEN kind='money' AND action IN ('deposit','depositSummary') THEN quantity ELSE 0 END) AS gold_in,
              SUM(CASE WHEN kind='money' AND action IN ('withdraw','repair','withdrawForTab') THEN quantity ELSE 0 END) AS gold_out,
              SUM(CASE WHEN kind='item' AND action='deposit' THEN quantity ELSE 0 END) AS items_in,
              SUM(CASE WHEN kind='item' AND action='withdraw' THEN quantity ELSE 0 END) AS items_out
            FROM guild_ledger WHERE guild_id=? AND happened_at>=UTC_TIMESTAMP()-INTERVAL ? DAY GROUP BY player ORDER BY gold_in DESC,items_in DESC,player LIMIT 200");
        $s->execute([$guild,max(1,min($days,3650))]);
        $players=array_map(fn($r)=>['player'=>$r['player'],'gold_in'=>(int)$r['gold_in'],'gold_out'=>(int)$r['gold_out'],'items_in'=>(int)$r['items_in'],'items_out'=>(int)$r['items_out']],$s->fetchAll(PDO::FETCH_ASSOC));
        $v=$this->db->prepare('SELECT COALESCE(SUM(physical_qty*unit_price),0) AS value,COUNT(unit_price) AS priced,MAX(price_at) AS price_at FROM guild_stock WHERE guild_id=? AND physical_qty>0');
        $v->execute([$guild]);$val=$v->fetch(PDO::FETCH_ASSOC);
        return ['money'=>$money?['copper'=>(int)$money['copper'],'observed_at'=>$money['observed_at']]:null,'days'=>$days,'players'=>$players,
            'stock_value'=>['copper'=>(int)$val['value'],'priced_items'=>(int)$val['priced'],'price_at'=>$val['price_at']]];
    }

    public static function csv(array $rows): string
    {
        $out=fopen('php://temp','r+');
        fputcsv($out,['Zeit (UTC)','Art','Aktion','Spieler','Item-ID','Gegenstand','Menge/Kupfer','Fach','Nach Fach'],';','"','');
        foreach ($rows as $r) {
            $cells=[$r['happened_at'],$r['kind']==='money'?'Gold':'Gegenstand',$r['action'],$r['player'],$r['wow_item_id'],$r['item_name'],$r['quantity'],$r['tab_no'],$r['tab_to']];
            // Spreadsheet formula injection guard.
            $cells=array_map(fn($c)=>is_string($c) && preg_match('/^[=+\-@\t\r]/',$c)?"'".$c:$c,$cells);
            fputcsv($out,$cells,';','"','');
        }
        rewind($out);return "\xEF\xBB\xBF".stream_get_contents($out);
    }
}
