<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/**
 * Signed release channel. Uploads are authenticated with a publish token, but
 * nothing is trusted without an Ed25519 signature by the offline release key:
 * a stolen token can only upload inert files. Files are content-addressed.
 */
final class ReleasePublisher {
    public const PUBLIC_KEY_B64='fnurLSrCmhYmaJw6CG0NZtwMka5aEIQWqYC6DmcGMRk=';
    public const MAX_FILE=67_108_864;
    private const CHUNK_MAX=8_388_608;

    public function __construct(private readonly PDO $db, private readonly string $root) {}

    private function dir(): string { return $this->root.'/private/releases'; }
    public function manifestPath(): string { return $this->dir().'/manifest.json'; }
    public function filePath(string $sha, string $ext): string { return $this->dir().'/files/'.$sha.'.'.$ext; }

    public static function validName(string $sha, string $ext): bool {
        return (bool)preg_match('/^[a-f0-9]{64}$/D',$sha) && in_array($ext,['zip','exe'],true);
    }

    public function issueToken(int $actor, string $label): array {
        $label=trim($label);
        if ($label==='' || mb_strlen($label)>80) throw new DomainError('INVALID_TOKEN_LABEL');
        $plain='blp1_'.bin2hex(random_bytes(32));
        $this->db->prepare('INSERT INTO release_publish_tokens (created_by,label,token_hash) VALUES (?,?,?)')->execute([$actor,$label,hash('sha256',$plain)]);
        return ['id'=>(int)$this->db->lastInsertId(),'label'=>$label,'token'=>$plain];
    }

    public function authorize(string $bearer): int {
        if (!preg_match('/^Bearer (blp1_[a-f0-9]{64})$/D',$bearer,$m)) throw new DomainError('PUBLISH_TOKEN_INVALID');
        $s=$this->db->prepare("SELECT t.id FROM release_publish_tokens t JOIN guild_memberships m ON m.user_id=t.created_by
            WHERE t.token_hash=? AND t.revoked_at IS NULL AND m.role='admin' AND m.status='active' LIMIT 1");
        $s->execute([hash('sha256',$m[1])]);$id=$s->fetchColumn();
        if (!$id) throw new DomainError('PUBLISH_TOKEN_INVALID');
        $this->db->prepare('UPDATE release_publish_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
        return (int)$id;
    }

    /** Resumable chunk upload: append at $offset; on $final verify the SHA-256. */
    public function putChunk(string $sha, string $ext, int $offset, string $data, bool $final): array {
        if (!self::validName($sha,$ext)) throw new DomainError('RELEASE_FILE_NAME');
        $target=$this->filePath($sha,$ext);
        if (is_file($target)) return ['complete'=>true,'size'=>filesize($target)];
        if (!is_dir($this->dir().'/files') && !mkdir($this->dir().'/files',0700,true)) throw new DomainError('RELEASE_STORAGE');
        if (strlen($data)>self::CHUNK_MAX) throw new DomainError('RELEASE_CHUNK_TOO_LARGE');
        $part=$target.'.part';
        clearstatcache(true,$part);
        $size=is_file($part)?filesize($part):0;
        if ($offset===0 && $size>0) {@unlink($part);$size=0;}
        if ($offset!==$size) return ['complete'=>false,'size'=>$size,'error'=>'OFFSET_MISMATCH'];
        if ($size+strlen($data)>self::MAX_FILE) {@unlink($part);throw new DomainError('RELEASE_FILE_TOO_LARGE');}
        if (file_put_contents($part,$data,FILE_APPEND|LOCK_EX)!==strlen($data)) throw new DomainError('RELEASE_STORAGE');
        if (!$final) return ['complete'=>false,'size'=>$size+strlen($data)];
        if (!hash_equals($sha,(string)hash_file('sha256',$part))) {@unlink($part);throw new DomainError('RELEASE_FILE_HASH');}
        if (!rename($part,$target)) throw new DomainError('RELEASE_STORAGE');
        @chmod($target,0600);
        return ['complete'=>true,'size'=>filesize($target)];
    }

    /** @return array{0:array,1:string} decoded payload and raw manifest */
    public static function verify(string $manifest, ?string $host=null): array {
        if (!function_exists('sodium_crypto_sign_verify_detached')) throw new DomainError('SODIUM_MISSING');
        $env=json_decode($manifest,true);
        if (strlen($manifest)>16384 || !is_array($env) || !is_string($env['payload']??null) || !is_string($env['signature']??null)) throw new DomainError('MANIFEST_INVALID');
        $payload=base64_decode($env['payload'],true);$sig=base64_decode($env['signature'],true);$key=base64_decode(self::PUBLIC_KEY_B64,true);
        if ($payload===false || $sig===false || $key===false || strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($sig,$payload,$key)) throw new DomainError('MANIFEST_SIGNATURE_INVALID');
        $p=json_decode($payload,true);
        if (!is_array($p) || ($p['protocol']??0)!==1 || ($p['channel']??'')!=='preview' || ($p['client']??'')!=='mop-classic-5.5.4') throw new DomainError('MANIFEST_INVALID');
        $apps=$p['apps']??[];
        if (!is_array($apps) || count($apps)>8) throw new DomainError('MANIFEST_INVALID');
        foreach ($apps as $k=>$v) if (!is_string($k) || !preg_match('/^(darwin|linux)-(amd64|arm64)$/D',$k)) throw new DomainError('MANIFEST_INVALID');
        $parts=['addon'=>[$p['addon']??null,'zip'],'companion'=>[$p['companion']??null,'exe'],'updater'=>[$p['updater']??null,'exe'],'web'=>[$p['web']??null,'zip']];
        foreach ($apps as $k=>$v) $parts['app:'.$k]=[$v,'zip'];
        foreach ($parts as [$f,$ext]) {
            if (!is_array($f) || !is_string($f['version']??null) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]{1,40})?$/D',$f['version'])
                || !is_string($f['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D',$f['sha256'])
                || ($f['url']??'')!=='https://'.($host ?? self::updateHost()).'/updates/preview/files/'.$f['sha256'].'.'.$ext) throw new DomainError('MANIFEST_INVALID');
        }
        return [$p,$manifest];
    }

    /** Host of this update server (config key UPDATE_HOST); defaults to the official one. */
    public static function updateHost(): string {
        $h=strtolower(trim((string)(getenv('UPDATE_HOST') ?: 'banklord.mgc-nom.net')));
        if (!preg_match('/^[a-z0-9.-]{1,253}(?::[0-9]{1,5})?$/D',$h)) throw new DomainError('UPDATE_HOST_INVALID');
        return $h;
    }

    public function publish(int $tokenId, string $manifest, WebUpdater $web): array {
        [$p]=self::verify($manifest);
        foreach (self::files($p) as [$sha,$ext])
            if (!is_file($this->filePath($sha,$ext))) throw new DomainError('RELEASE_FILE_MISSING');
        $current=@file_get_contents($this->manifestPath());
        if (is_string($current) && $current!=='') {
            [$old]=self::verify($current);
            if (strcmp((string)$p['published_at'],(string)$old['published_at'])<=0) throw new DomainError('RELEASE_NOT_NEWER');
        }
        // The website goes first; clients only see the new manifest after it succeeded.
        $webResult=null;
        if (version_compare(self::plain($p['web']['version']),self::plain($web->installedVersion()),'>')) {
            $webResult=$web->apply(WebUpdater::readPayload($this->filePath($p['web']['sha256'],'zip')),$p['web']['version']);
        }
        $tmp=$this->manifestPath().'.tmp';
        if (file_put_contents($tmp,$manifest,LOCK_EX)!==strlen($manifest) || !rename($tmp,$this->manifestPath())) throw new DomainError('RELEASE_STORAGE');
        $this->db->prepare('INSERT INTO release_publications (manifest_sha256,web_version,addon_version,companion_version,updater_version,web_applied,published_by_token,details_json) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([hash('sha256',$manifest),$p['web']['version'],$p['addon']['version'],$p['companion']['version'],$p['updater']['version'],$webResult?1:0,$tokenId,json_encode($webResult)]);
        $this->pruneFiles($p);
        return ['published'=>true,'web'=>$webResult?['applied'=>true]+$webResult:['applied'=>false,'installed'=>$web->installedVersion()],
            'versions'=>['web'=>$p['web']['version'],'addon'=>$p['addon']['version'],'companion'=>$p['companion']['version'],'updater'=>$p['updater']['version']]];
    }

    private static function plain(string $v): string { return (string)preg_replace('/-.*$/','',$v); }

    /** Keep the files of the current release only (plus anything younger than a day). */
    private function pruneFiles(array $p): void {
        $keep=[];foreach (self::files($p) as [$sha]) $keep[$sha]=true;
        foreach (glob($this->dir().'/files/*') ?: [] as $f) {
            $sha=substr(basename($f),0,64);
            if (!isset($keep[$sha]) && filemtime($f)<time()-86400) @unlink($f);
        }
    }

    public function currentStatus(): array {
        $m=@file_get_contents($this->manifestPath());
        if (!is_string($m) || $m==='') return ['published'=>false];
        [$p]=self::verify($m);
        return ['published'=>true,'published_at'=>$p['published_at'],'digest'=>hash('sha256',$m),
            'versions'=>['web'=>$p['web']['version'],'addon'=>$p['addon']['version'],'companion'=>$p['companion']['version'],'updater'=>$p['updater']['version']],
            'apps'=>array_map(static fn($a)=>$a['version'],$p['apps']??[])];
    }

    /** All files of a release as [sha256, extension]. */
    public static function files(array $p): array {
        $out=[[$p['addon']['sha256'],'zip'],[$p['companion']['sha256'],'exe'],[$p['updater']['sha256'],'exe'],[$p['web']['sha256'],'zip']];
        foreach ($p['apps']??[] as $a) $out[]=[$a['sha256'],'zip'];
        return $out;
    }
}
