<?php
declare(strict_types=1);
namespace Banklord;

use PDO;

/**
 * Sends e-mails over SMTP (SSL on 465 or STARTTLS on 587), certificate checked.
 * The operator enters the account in the operator area; the password is stored
 * encrypted (key in private/, outside the web root and outside git) and is never
 * shown again. Development: BANKLORD_MAIL_SINK writes mails to a file instead.
 */
final class Mailer
{
    private const KEYS=['mail_host','mail_port','mail_user','mail_from','mail_from_name','mail_pass','site_url'];

    public function __construct(private PDO $db, private string $root) {}

    private function get(string $k): string
    {
        $s=$this->db->prepare('SELECT value FROM site_settings WHERE name=?');$s->execute([$k]);$v=$s->fetchColumn();
        return $v===false?'':(string)$v;
    }

    private function put(string $k,string $v): void
    {
        $this->db->prepare('INSERT INTO site_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$k,$v]);
    }

    /** Settings for the operator form; never the password. */
    public function settings(): array
    {
        return ['host'=>$this->get('mail_host'),'port'=>(int)($this->get('mail_port')?:465),'user'=>$this->get('mail_user'),
            'from'=>$this->get('mail_from'),'from_name'=>$this->get('mail_from_name')?:'Banklord',
            'has_password'=>$this->get('mail_pass')!=='','site_url'=>$this->get('site_url'),'ready'=>$this->ready()];
    }

    public function ready(): bool
    {
        if (self::sink()!==null) return true;
        return $this->get('mail_host')!=='' && $this->get('mail_user')!=='' && $this->get('mail_pass')!=='' && $this->get('site_url')!=='';
    }

    /** Operator saves the SMTP account. Empty password = keep the stored one. $siteUrl comes from the operator's own request. */
    public function save(array $in,string $siteUrl): array
    {
        $host=trim((string)($in['host']??''));$port=$in['port']??465;$user=trim((string)($in['user']??''));
        $from=trim((string)($in['from']??''))?:$user;$fromName=trim((string)($in['from_name']??''))?:'Banklord';$pass=(string)($in['password']??'');
        if (!preg_match('/^[A-Za-z0-9.-]{3,253}$/D',$host) || !in_array($port,[465,587],true)
            || !filter_var($user,FILTER_VALIDATE_EMAIL) && !preg_match('/^[A-Za-z0-9._@+-]{1,200}$/D',$user)
            || !filter_var($from,FILTER_VALIDATE_EMAIL) || mb_strlen($fromName)>60 || preg_match('/[\x00-\x1f\x7f"<>]/',$fromName)
            || strlen($pass)>100 || preg_match('/[\r\n\0]/',$pass)) throw new DomainError('INVALID_MAIL_SETTINGS');
        if (!preg_match('~^https?://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._/-]*)?$~D',$siteUrl)) throw new DomainError('INVALID_MAIL_SETTINGS');
        foreach (['mail_host'=>$host,'mail_port'=>(string)$port,'mail_user'=>$user,'mail_from'=>$from,'mail_from_name'=>$fromName,'site_url'=>rtrim($siteUrl,'/')] as $k=>$v) $this->put($k,$v);
        if ($pass!=='') $this->put('mail_pass',$this->encrypt($pass));
        return $this->settings();
    }

    public function siteUrl(): string { return $this->get('site_url'); }

    private function key(): string
    {
        $dir=$this->root.'/private';$file=$dir.'/mail.key';
        if (!is_file($file)) {
            if (!is_dir($dir) || !is_writable($dir)) throw new DomainError('MAIL_KEY_UNAVAILABLE');
            $tmp=$file.'.'.bin2hex(random_bytes(4));
            if (file_put_contents($tmp,bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)))===false) throw new DomainError('MAIL_KEY_UNAVAILABLE');
            @chmod($tmp,0600);rename($tmp,$file);
        }
        $k=hex2bin(trim((string)file_get_contents($file)));
        if (!is_string($k) || strlen($k)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new DomainError('MAIL_KEY_UNAVAILABLE');
        return $k;
    }

    private function encrypt(string $plain): string
    {
        $n=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($n.sodium_crypto_secretbox($plain,$n,$this->key()));
    }

    private function decrypt(string $stored): string
    {
        $raw=base64_decode($stored,true);
        if (!is_string($raw) || strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new DomainError('MAIL_NOT_CONFIGURED');
        $p=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->key());
        if (!is_string($p)) throw new DomainError('MAIL_NOT_CONFIGURED');
        return $p;
    }

    private static function sink(): ?string
    {
        $s=getenv('BANKLORD_MAIL_SINK');return is_string($s) && $s!=='' ? $s : null;
    }

    /** Sends a plain-text mail. Throws DomainError('MAIL_FAILED') with nothing secret in it. */
    public function send(string $to,string $subject,string $body): void
    {
        if (!filter_var($to,FILTER_VALIDATE_EMAIL) || str_ends_with(strtolower($to),'.invalid')) throw new DomainError('MAIL_BAD_RECIPIENT');
        if (preg_match('/[\r\n]/',$subject)) throw new DomainError('MAIL_FAILED');
        if (($sink=self::sink())!==null) {
            file_put_contents($sink,json_encode(['to'=>$to,'subject'=>$subject,'body'=>$body],JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX);return;
        }
        if (!$this->ready()) throw new DomainError('MAIL_NOT_CONFIGURED');
        $host=$this->get('mail_host');$port=(int)$this->get('mail_port');$user=$this->get('mail_user');$pass=$this->decrypt($this->get('mail_pass'));
        $from=$this->get('mail_from')?:$user;$fromName=$this->get('mail_from_name')?:'Banklord';
        $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'SNI_enabled'=>true]]);
        $sock=@stream_socket_client(($port===465?'ssl://':'tcp://').$host.':'.$port,$errno,$errstr,10,STREAM_CLIENT_CONNECT,$ctx);
        if (!$sock) throw new DomainError('MAIL_FAILED');
        stream_set_timeout($sock,15);
        try {
            $this->expect($sock,220);
            $me=parse_url($this->get('site_url'),PHP_URL_HOST)?:'localhost';
            $this->cmd($sock,'EHLO '.$me,250);
            if ($port===587) {
                $this->cmd($sock,'STARTTLS',220);
                if (!stream_socket_enable_crypto($sock,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new DomainError('MAIL_FAILED');
                $this->cmd($sock,'EHLO '.$me,250);
            }
            $this->cmd($sock,'AUTH LOGIN',334);$this->cmd($sock,base64_encode($user),334);$this->cmd($sock,base64_encode($pass),235);
            $this->cmd($sock,'MAIL FROM:<'.$from.'>',250);$this->cmd($sock,'RCPT TO:<'.$to.'>',[250,251]);$this->cmd($sock,'DATA',354);
            $headers=['Date: '.date('r'),'From: '.mb_encode_mimeheader($fromName,'UTF-8','Q').' <'.$from.'>','To: <'.$to.'>',
                'Subject: '.mb_encode_mimeheader($subject,'UTF-8','Q'),'Message-ID: <'.bin2hex(random_bytes(12)).'@'.$me.'>',
                'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: base64','Auto-Submitted: auto-generated'];
            $data=implode("\r\n",$headers)."\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n");
            fwrite($sock,$data."\r\n.\r\n");$this->expect($sock,250);
            fwrite($sock,"QUIT\r\n");
        } finally { fclose($sock); }
    }

    private function cmd($sock,string $line,int|array $code): void { fwrite($sock,$line."\r\n");$this->expect($sock,$code); }

    private function expect($sock,int|array $code): void
    {
        $codes=(array)$code;
        while (true) {
            $l=fgets($sock,1024);
            if ($l===false) throw new DomainError('MAIL_FAILED');
            if (strlen($l)>=4 && $l[3]==='-') continue;
            if (!in_array((int)substr($l,0,3),$codes,true)) throw new DomainError('MAIL_FAILED');
            return;
        }
    }
}
