<?php
declare(strict_types=1);
namespace Banklord;
use PDO;
require_once __DIR__.'/Version.php';

/**
 * Help & feedback: bug reports, feature ideas and language requests.
 *
 * Members report from the guild website or in game (addon outbox, uploaded by
 * the companion). The guild website keeps a receipt per report and forwards
 * the report to the central inbox on the official Banklord site, which is the
 * host of the update channel. A site that is its own publisher (the official
 * site, or a self-hosted site with UPDATE_SOURCE=off) keeps the inbox itself.
 *
 * Nothing personal is forwarded: no e-mail, account or character name, unless
 * the member fills in the optional contact field on purpose.
 */
final class Feedback {
    public const KINDS=['bug','idea','language'];
    public const AREAS=['addon','web','companion','setup','unknown'];
    public const STATUSES=['new','seen','planned','done','rejected','duplicate'];
    private const HOURLY_LIMIT=20;
    private const DAILY_LIMIT=400;
    /** Test hook: fn(string $url, array $payload): array */
    public static ?\Closure $transport=null;

    public function __construct(private readonly PDO $db) {}

    // ---------------------------------------------------------------- input

    private static function text(mixed $v, int $max, bool $multiline=false): string {
        if (!is_string($v) && !is_int($v)) return '';
        $s=str_replace("\r\n","\n",trim((string)$v));
        $s=preg_replace($multiline?'/[\x00-\x09\x0b-\x1f\x7f]/u':'/[\x00-\x1f\x7f]/u','',$s)??'';
        return mb_substr($s,0,$max);
    }

    /** Validates a report from any source; returns the clean report. */
    public static function clean(array $in): array {
        $kind=(string)($in['kind']??'');
        if (!in_array($kind,self::KINDS,true)) throw new DomainError('FEEDBACK_KIND_INVALID');
        $r=['kind'=>$kind,'title'=>self::text($in['title']??'',120),'text'=>self::text($in['text']??'',4000,true)];
        $area=(string)($in['area']??'unknown');
        $r['area']=in_array($area,self::AREAS,true)?$area:'unknown';
        if ($kind==='bug') {
            $r['steps']=self::text($in['steps']??'',2000,true);
            $r['expected']=self::text($in['expected']??'',1000,true);
        }
        if ($kind==='language') {
            $r['language']=self::text($in['language']??'',60);
            $help=(string)($in['help']??'no');
            $r['help']=in_array($help,['yes','maybe','no'],true)?$help:'no';
            if (mb_strlen($r['language'])<2) throw new DomainError('FEEDBACK_LANGUAGE_REQUIRED');
            if (mb_strlen($r['text'])<10) $r['text']=trim('Sprachwunsch: '.$r['language'].'. '.$r['text']);
            if ($r['title']==='') $r['title']=mb_substr('Sprache: '.$r['language'],0,120);
        }
        if (mb_strlen($r['title'])<4) throw new DomainError('FEEDBACK_TITLE_REQUIRED');
        if (mb_strlen($r['text'])<10) throw new DomainError('FEEDBACK_TEXT_REQUIRED');
        $contact=mb_strtolower(self::text($in['contact']??'',254));
        if ($contact!=='' && !filter_var($contact,FILTER_VALIDATE_EMAIL)) throw new DomainError('INVALID_EMAIL');
        $r['contact']=$contact;
        $meta=[];
        foreach (['web','addon','companion','build','client','lang','source'] as $k) {
            $v=self::text(($in['meta'][$k]??''),60);
            if ($v!=='') $meta[$k]=$v;
        }
        $r['meta']=$meta;
        return $r;
    }

    // -------------------------------------------------------- central inbox

    /** Official site: accept a forwarded report. Returns id + receipt. */
    public function accept(array $in, string $site, string $sender): array {
        $r=self::clean($in);
        $site=self::text($site,255);
        $hash=hash('sha256','feedback|'.$sender);
        $c=$this->db->prepare('SELECT SUM(created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)),COUNT(*) FROM feedback_inbox WHERE sender_hash=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)');
        $c->execute([$hash]);[$hour,$day]=$c->fetch(PDO::FETCH_NUM);
        if ((int)$hour>=self::HOURLY_LIMIT || (int)$day>=self::DAILY_LIMIT) throw new DomainError('FEEDBACK_RATE_LIMIT');
        $receipt=bin2hex(random_bytes(32));
        $details=['area'=>$r['area'],'text'=>$r['text']];
        foreach (['steps','expected','language','help'] as $k) if (isset($r[$k])) $details[$k]=$r[$k];
        $this->db->prepare('INSERT INTO feedback_inbox (kind,title,details,meta,site,contact,receipt_hash,sender_hash) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$r['kind'],$r['title'],json_encode($details,JSON_UNESCAPED_UNICODE),json_encode($r['meta'],JSON_UNESCAPED_UNICODE),
                $site,$r['contact']!==''?$r['contact']:null,hash('sha256',$receipt),$hash]);
        return ['id'=>(int)$this->db->lastInsertId(),'receipt'=>$receipt];
    }

    /** Official site: status for receipts (only what the reporter may see). */
    public function lookup(array $receipts): array {
        $out=[];
        $s=$this->db->prepare('SELECT status,reply FROM feedback_inbox WHERE receipt_hash=?');
        foreach (array_slice($receipts,0,50) as $receipt) {
            if (!is_string($receipt) || !preg_match('/^[a-f0-9]{64}$/D',$receipt)) continue;
            $s->execute([hash('sha256',$receipt)]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if ($row) $out[$receipt]=['status'=>(string)$row['status'],'reply'=>(string)$row['reply']];
        }
        return $out;
    }

    public function inbox(string $status='', string $kind=''): array {
        $where=[];$args=[];
        if (in_array($status,self::STATUSES,true)) {$where[]='status=?';$args[]=$status;}
        if (in_array($kind,self::KINDS,true)) {$where[]='kind=?';$args[]=$kind;}
        $s=$this->db->prepare('SELECT id,kind,title,details,meta,site,contact,status,reply,created_at,updated_at FROM feedback_inbox'
            .($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id DESC LIMIT 300');
        $s->execute($args);$rows=[];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['id']=(int)$r['id'];
            $r['details']=json_decode((string)$r['details'],true)?:[];
            $r['meta']=json_decode((string)$r['meta'],true)?:[];
            $rows[]=$r;
        }
        $c=$this->db->query('SELECT status,COUNT(*) FROM feedback_inbox GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
        return ['items'=>$rows,'counts'=>array_map('intval',$c)];
    }

    public function answer(int $id, string $status, string $reply): void {
        if (!in_array($status,self::STATUSES,true)) throw new DomainError('FEEDBACK_STATUS_INVALID');
        $u=$this->db->prepare('UPDATE feedback_inbox SET status=?,reply=?,updated_at=UTC_TIMESTAMP() WHERE id=?');
        $u->execute([$status,self::text($reply,1000,true),$id]);
        if ($u->rowCount()===0) {
            $e=$this->db->prepare('SELECT COUNT(*) FROM feedback_inbox WHERE id=?');$e->execute([$id]);
            if ((int)$e->fetchColumn()===0) throw new DomainError('NOT_FOUND');
        }
    }

    // ----------------------------------------------------------- guild site

    /** Where reports go: null = keep them in this site's own inbox. */
    public static function centralUrl(): ?string {
        if (UpstreamUpdater::isPublisher()) return null;
        return 'https://'.parse_url((string)UpstreamUpdater::source(),PHP_URL_HOST).'/api/v1/feedback';
    }

    public static function enabled(): bool { return strtolower((string)getenv('FEEDBACK'))!=='off'; }

    private function send(string $path, array $payload): array {
        $url=self::centralUrl().$path;
        if (self::$transport!==null) return (self::$transport)($url,$payload);
        $body=json_encode($payload,JSON_UNESCAPED_UNICODE);
        $ctx=stream_context_create(['http'=>['method'=>'POST','timeout'=>15,'follow_location'=>0,'ignore_errors'=>true,
            'header'=>"Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: Banklord-Feedback\r\n",'content'=>$body],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $raw=@file_get_contents($url,false,$ctx,0,65536);
        if (!is_string($raw)) throw new DomainError('FEEDBACK_UNREACHABLE');
        $data=json_decode($raw,true);
        if (!is_array($data)) throw new DomainError('FEEDBACK_UNREACHABLE');
        if (isset($data['error'])) throw new DomainError(preg_match('/^[A-Z_]{3,40}$/D',(string)$data['error'])?(string)$data['error']:'FEEDBACK_REJECTED');
        return $data;
    }

    /** Stores the receipt and forwards the report. Returns the local report. */
    public function submit(int $guild, int $user, array $in, string $source, ?string $clientKey, string $site): array {
        if (!self::enabled()) throw new DomainError('FEEDBACK_DISABLED');
        $in['meta']=($in['meta']??[])+['web'=>Version::WEB,'source'=>$source];
        $r=self::clean($in);
        $id=0;
        if ($clientKey!==null) {
            // The addon keeps a report until it was forwarded; repeat only unsent ones.
            $e=$this->db->prepare('SELECT id,remote_id,user_id FROM feedback_reports WHERE guild_id=? AND client_key=?');$e->execute([$guild,$clientKey]);
            if ($old=$e->fetch(PDO::FETCH_ASSOC)) {
                if ((int)$old['user_id']!==$user || $old['remote_id']!==null) return $this->report((int)$old['id']);
                $id=(int)$old['id'];
            }
        }
        if ($id===0) {
            $this->db->prepare('INSERT INTO feedback_reports (guild_id,user_id,client_key,source,kind,title) VALUES (?,?,?,?,?,?)')
                ->execute([$guild,$user,$clientKey,$source,$r['kind'],$r['title']]);
            $id=(int)$this->db->lastInsertId();
        }
        try {
            $res=self::centralUrl()===null
                ? $this->accept($r,$site,'site|'.$site.'|'.$guild.'|'.$user)
                : $this->send('/submit',['report'=>$r,'site'=>$site]);
            if (!is_int($res['id']??null) || !preg_match('/^[a-f0-9]{64}$/D',(string)($res['receipt']??''))) throw new DomainError('FEEDBACK_UNREACHABLE');
            $this->db->prepare('UPDATE feedback_reports SET remote_id=?,receipt=?,error=NULL,checked_at=UTC_TIMESTAMP() WHERE id=?')->execute([$res['id'],$res['receipt'],$id]);
        } catch (DomainError $e) {
            // Web: the member sees the error and can send again. In game: the
            // addon keeps the report and the next upload tries again.
            if ($clientKey===null) {
                $this->db->prepare('DELETE FROM feedback_reports WHERE id=?')->execute([$id]);
                throw $e;
            }
            $this->db->prepare('UPDATE feedback_reports SET error=?,checked_at=UTC_TIMESTAMP() WHERE id=?')->execute([substr($e->getMessage(),0,40),$id]);
        }
        return $this->report($id);
    }

    private function report(int $id): array {
        $s=$this->db->prepare('SELECT id,source,kind,title,remote_id,status,reply,error,UNIX_TIMESTAMP(created_at) AS created,client_key FROM feedback_reports WHERE id=?');
        $s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);
        return ['id'=>(int)$r['id'],'source'=>$r['source'],'kind'=>$r['kind'],'title'=>$r['title'],'number'=>$r['remote_id']!==null?(int)$r['remote_id']:null,
            'status'=>$r['error']!==null&&$r['remote_id']===null?'pending':$r['status'],'reply'=>$r['reply'],'created_at'=>(int)$r['created'],'client_key'=>$r['client_key']];
    }

    /** Refreshes stale statuses from the central inbox (throttled). */
    public function refresh(int $guild, int $user, string $site): void {
        $s=$this->db->prepare('SELECT id,receipt FROM feedback_reports WHERE guild_id=? AND user_id=? AND receipt IS NOT NULL AND status NOT IN (\'done\',\'rejected\',\'duplicate\')
            '.(self::centralUrl()===null?'':'AND (checked_at IS NULL OR checked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)) ').'ORDER BY id DESC LIMIT 30');
        $s->execute([$guild,$user]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;
        $receipts=array_column($rows,'receipt');
        try {
            $status=self::centralUrl()===null ? $this->lookup($receipts) : ($this->send('/status',['receipts'=>$receipts])['reports']??[]);
        } catch (DomainError $e) { return; }
        $u=$this->db->prepare('UPDATE feedback_reports SET status=?,reply=?,checked_at=UTC_TIMESTAMP() WHERE id=?');
        foreach ($rows as $row) {
            $st=$status[$row['receipt']]??null;
            if (is_array($st) && in_array($st['status']??'',self::STATUSES,true)) $u->execute([$st['status'],self::text($st['reply']??'',1000,true),(int)$row['id']]);
        }
    }

    public function mine(int $guild, int $user, string $site): array {
        $this->refresh($guild,$user,$site);
        $s=$this->db->prepare('SELECT id FROM feedback_reports WHERE guild_id=? AND user_id=? ORDER BY id DESC LIMIT 30');
        $s->execute([$guild,$user]);
        return array_map(fn($id)=>$this->report((int)$id),$s->fetchAll(PDO::FETCH_COLUMN));
    }

    // -------------------------------------------------------------- in game

    /** BanklordDB.feedbackOutbox rows from the companion upload. */
    public function processIngame(int $guild, int $user, string $savedVars, string $site, array $build): array {
        try { $data=(new SavedVariablesParser($savedVars))->parse(); } catch (\Throwable $e) { return []; }
        $rows=$data['feedbackOutbox']??[];
        if (!is_array($rows)) return [];
        $out=[];
        foreach (array_slice($rows,0,10) as $row) {
            if (!is_array($row)) continue;
            $key=$row['key']??null;
            if (!is_string($key) || !preg_match('/^fb-[0-9]{6,12}-[a-z0-9]{6}$/D',$key)) continue;
            $row['meta']=['addon'=>$row['addon']??'','build'=>$row['build']??'','client'=>$row['client']??'','lang'=>$row['lang']??''];
            try { $rep=$this->submit($guild,$user,$row,'ingame',$key,$site); $out[$key]=['status'=>$rep['status'],'number'=>$rep['number']]; }
            catch (DomainError $e) { $out[$key]=['status'=>'invalid','error'=>$e->getMessage()]; }
        }
        return $out;
    }

    /** Short list for the addon (ServerState.lua). */
    public function stateView(int $guild, int $user, string $site): array {
        $out=[];
        foreach (array_slice($this->mine($guild,$user,$site),0,10) as $r) {
            $out[]=['key'=>(string)($r['client_key']??''),'number'=>$r['number']??0,'kind'=>$r['kind'],'title'=>mb_substr($r['title'],0,80),
                'status'=>$r['status'],'reply'=>mb_substr($r['reply'],0,300)];
        }
        return $out;
    }
}
