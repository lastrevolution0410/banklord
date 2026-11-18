<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/**
 * Optional Discord notifications per guild via a channel webhook. Messages are
 * queued inside the business transaction and sent after the HTTP response, so
 * a slow or unreachable Discord never delays or breaks an order.
 */
final class Discord
{
    public const EVENTS=['order','approval','shipped','restock'];
    private const URL='~^https://(?:discord\.com|discordapp\.com|ptb\.discord\.com|canary\.discord\.com)/api/webhooks/[0-9]{5,25}/[A-Za-z0-9_-]{20,120}$~D';

    public function __construct(private readonly PDO $db) {}

    public static function validUrl(mixed $url): bool { return is_string($url) && strlen($url)<=255 && preg_match(self::URL,$url)===1; }

    /** Settings without the secret webhook path. */
    public function view(int $guild): array
    {
        $s=$this->db->prepare('SELECT webhook_url,events FROM guild_discord WHERE guild_id=?');$s->execute([$guild]);$r=$s->fetch(PDO::FETCH_ASSOC);
        return ['configured'=>(bool)$r,'events'=>$r?array_values(array_filter(explode(',',$r['events']))):self::EVENTS];
    }

    public function save(int $guild, int $actor, array $in): array
    {
        $this->admin($guild,$actor);
        $events=$in['events']??self::EVENTS;
        if (!is_array($events)) throw new DomainError('INVALID_DISCORD');
        foreach ($events as $e) if (!in_array($e,self::EVENTS,true)) throw new DomainError('INVALID_DISCORD');
        $url=$in['webhook_url']??null;
        if ($url==='' ) {
            $this->db->prepare('DELETE FROM guild_discord WHERE guild_id=?')->execute([$guild]);
            $this->audit($guild,$actor,['removed'=>true]);return $this->view($guild);
        }
        $csv=implode(',',array_values(array_unique($events)));
        if ($url===null) {
            // Only the events changed; keep the stored webhook.
            $u=$this->db->prepare('UPDATE guild_discord SET events=?,updated_by=? WHERE guild_id=?');$u->execute([$csv,$actor,$guild]);
            if ($u->rowCount()===0 && !$this->view($guild)['configured']) throw new DomainError('INVALID_DISCORD');
        } else {
            if (!self::validUrl($url)) throw new DomainError('INVALID_DISCORD');
            $this->db->prepare('INSERT INTO guild_discord (guild_id,webhook_url,events,updated_by) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE webhook_url=VALUES(webhook_url),events=VALUES(events),updated_by=VALUES(updated_by)')->execute([$guild,$url,$csv,$actor]);
        }
        $this->audit($guild,$actor,['events'=>$events]);
        return $this->view($guild);
    }

    /** Queues a message if the guild wants this event. Never throws. */
    public static function queue(PDO $db, int $guild, string $event, string $text): void
    {
        try {
            $s=$db->prepare('SELECT events FROM guild_discord WHERE guild_id=?');$s->execute([$guild]);$ev=$s->fetchColumn();
            if ($ev===false || ($event!=='test' && !in_array($event,explode(',',(string)$ev),true))) return;
            // Discord markup and mentions are neutralised: names come from players.
            $text=str_replace(['@','`'],['＠','ˋ'],mb_substr($text,0,1800));
            $db->prepare('INSERT INTO discord_outbox (guild_id,message) VALUES (?,?)')->execute([$guild,$text]);
        } catch (\Throwable $e) {}
    }

    public function test(int $guild, int $actor): void
    {
        $this->admin($guild,$actor);
        if (!$this->view($guild)['configured']) throw new DomainError('INVALID_DISCORD');
        self::queue($this->db,$guild,'test','Banklord ist verbunden. Hier erscheinen Bestellungen und Hinweise der Gildenbank.');
    }

    /** Sends up to $max pending messages. Called after the response is out. */
    public static function flush(PDO $db, int $max=5): int
    {
        if (getenv('BANKLORD_DISCORD_OFF')==='1' || !function_exists('curl_init')) return 0;
        $sent=0;
        $q=$db->query('SELECT o.id,o.message,d.webhook_url FROM discord_outbox o JOIN guild_discord d ON d.guild_id=o.guild_id
            WHERE o.sent_at IS NULL AND o.attempts<3 ORDER BY o.id LIMIT '.(int)$max);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
            if (!self::validUrl($m['webhook_url'])) continue;
            $db->prepare('UPDATE discord_outbox SET attempts=attempts+1 WHERE id=?')->execute([$m['id']]);
            $ch=curl_init($m['webhook_url']);
            curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_USERAGENT=>'Banklord (guild bank notifications)',
                CURLOPT_POSTFIELDS=>json_encode(['content'=>$m['message'],'username'=>'Banklord','allowed_mentions'=>['parse'=>[]]],JSON_UNESCAPED_UNICODE)]);
            curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
            if ($code>=200 && $code<300) {$db->prepare('UPDATE discord_outbox SET sent_at=UTC_TIMESTAMP() WHERE id=?')->execute([$m['id']]);$sent++;}
        }
        $db->exec('DELETE FROM discord_outbox WHERE created_at<UTC_TIMESTAMP()-INTERVAL 7 DAY');
        return $sent;
    }

    private function admin(int $guild, int $actor): void
    {
        $r=$this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'");$r->execute([$guild,$actor]);
        if ($r->fetchColumn()!=='admin') throw new DomainError('FORBIDDEN');
    }
    private function audit(int $guild, int $actor, array $details): void
    {
        $this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,'guild.discord','guild',?,?)")
            ->execute([$guild,$actor,$guild,json_encode($details,JSON_THROW_ON_ERROR)]);
    }
}
