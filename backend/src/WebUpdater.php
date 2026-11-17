<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/**
 * Applies a website payload whose SHA-256 is covered by a valid release signature
 * (see ReleasePublisher). Same safety steps as upgrade-web.php: private SQL backup
 * and file backup first, then pending migrations, then files (index.php last),
 * file rollback on write errors. MariaDB DDL cannot be rolled back.
 */
final class WebUpdater {
    private const MAX_FILE=1_000_000;
    private const MAX_BACKUP_BYTES=41_943_040;
    private const MAX_BACKUP_ROWS=150_000;

    public function __construct(private readonly PDO $db, private readonly string $root) {}

    public function installedVersion(): string {
        $raw=@file_get_contents($this->root.'/private/upgrade-version.json');
        $data=is_string($raw)?json_decode($raw,true):null;
        return is_array($data)&&is_string($data['version']??null)?$data['version']:'0.0.0';
    }

    /** @return array<string,string> name => content */
    public static function readPayload(string $zipPath): array {
        $zip=new \ZipArchive();
        if ($zip->open($zipPath)!==true) throw new DomainError('WEB_PAYLOAD_INVALID');
        $out=[];
        try {
            if ($zip->numFiles<5 || $zip->numFiles>200) throw new DomainError('WEB_PAYLOAD_INVALID');
            for ($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i);$name=(string)($stat['name']??'');
                if (!preg_match('~^backend/(?:public|src|sql|bin)/[A-Za-z0-9_][A-Za-z0-9_.-]{0,100}$~D',$name) || isset($out[$name])
                    || (int)$stat['size']<1 || (int)$stat['size']>self::MAX_FILE) throw new DomainError('WEB_PAYLOAD_INVALID');
                $data=$zip->getFromIndex($i);
                if (!is_string($data) || strlen($data)!==(int)$stat['size']) throw new DomainError('WEB_PAYLOAD_INVALID');
                $out[$name]=$data;
            }
        } finally { $zip->close(); }
        foreach (['backend/public/index.php','backend/src/Database.php'] as $required)
            if (!isset($out[$required])) throw new DomainError('WEB_PAYLOAD_INCOMPLETE');
        return $out;
    }

    /** Migrations in the payload not yet recorded in schema_migrations, in name order. */
    public function pendingMigrations(array $payload): array {
        $known=[];
        $has=$this->db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'")->fetchColumn();
        if ((int)$has===1) foreach ($this->db->query('SELECT name FROM schema_migrations') as $r) $known[$r['name']]=true;
        else throw new DomainError('SCHEMA_MIGRATIONS_MISSING'); // first install of 0.9.1 goes through upgrade-web.php
        $pending=[];
        foreach (array_keys($payload) as $name)
            if (preg_match('~^backend/sql/([0-9]{3}_[a-z0-9_]+\.sql)$~D',$name,$m) && !isset($known[$m[1]])) $pending[]=$m[1];
        sort($pending);
        return $pending;
    }

    public function apply(array $payload, string $version): array {
        @set_time_limit(300);
        $private=$this->root.'/private';
        $lock=fopen($private.'/.browser-upgrade-lock','c+');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new DomainError('UPDATE_ALREADY_RUNNING');
        try {
            foreach ($payload as $name=>$_) {
                $dir=dirname($this->target($name));
                if (!is_dir($dir) || is_link($dir) || !is_writable($dir)) throw new DomainError('WEB_TARGET_NOT_WRITABLE');
            }
            $pending=$this->pendingMigrations($payload);
            $dir=$private.'/upgrades/backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5));
            if (!is_dir($private.'/upgrades')) mkdir($private.'/upgrades',0700,true);
            if (!mkdir($dir,0700)) throw new DomainError('BACKUP_FAILED');
            $backup=$this->backupDatabase($dir.'/db-preupgrade.sql.gz');
            $this->pruneBackups($private.'/upgrades',10);
            $old=[];
            foreach ($payload as $name=>$_) {
                $target=$this->target($name);
                if (is_file($target)) {
                    $copy=$dir.'/'.str_replace('/','--',substr($name,8));
                    if (!copy($target,$copy)) throw new DomainError('BACKUP_FAILED');
                    $old[$target]=$copy;
                }
            }
            $record=$this->db->prepare('INSERT IGNORE INTO schema_migrations (name) VALUES (?)');
            foreach ($pending as $script) {
                foreach (self::queries($payload['backend/sql/'.$script]) as $q) $this->db->exec($q);
                $record->execute([$script]);
            }
            $order=array_keys($payload);
            usort($order,static fn($a,$b)=>($a==='backend/public/index.php')<=>($b==='backend/public/index.php'));
            $written=[];
            try {
                foreach ($order as $name) {
                    $target=$this->target($name);
                    $tmp=dirname($target).'/.'.basename($target).'.up-'.bin2hex(random_bytes(5));
                    if (file_put_contents($tmp,$payload[$name],LOCK_EX)!==strlen($payload[$name])) throw new DomainError('WRITE_FAILED');
                    @chmod($tmp,str_starts_with($name,'backend/public/')?0644:0600);
                    if (!rename($tmp,$target)) {@unlink($tmp);throw new DomainError('WRITE_FAILED');}
                    $written[]=$target;
                }
                file_put_contents($private.'/upgrade-version.json',json_encode(['version'=>$version,'at'=>gmdate('c'),'db_backup'=>basename($dir),'via'=>'signed-release'],JSON_THROW_ON_ERROR),LOCK_EX);
            } catch (\Throwable $e) {
                foreach (array_reverse($written) as $t) { if (isset($old[$t])) copy($old[$t],$t); else @unlink($t); }
                throw $e;
            }
            if (function_exists('opcache_reset')) @opcache_reset();
            return ['migrations'=>$pending,'files'=>count($payload),'backup'=>basename($dir),'backup_rows'=>$backup];
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }

    /** Keep only the newest $keep backup folders (names sort by date). */
    private function pruneBackups(string $base, int $keep): void {
        $dirs=glob($base.'/backup-*',GLOB_ONLYDIR)?:[];sort($dirs);
        foreach (array_slice($dirs,0,max(0,count($dirs)-$keep)) as $old) {
            foreach (glob($old.'/{,.}[!.]*',GLOB_BRACE)?:[] as $f) if (is_file($f)) @unlink($f);
            @rmdir($old);
        }
    }

    private function target(string $name): string { return $this->root.'/'.substr($name,strlen('backend/')); }

    public static function queries(string $sql): array {
        $clean=(string)preg_replace('/^\s*--[^\r\n]*$/m','',$sql);
        return array_values(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$clean)?:[]),static fn($x)=>$x!==''));
    }

    private function backupDatabase(string $destination): int {
        $gz=gzopen($destination,'wb6');if ($gz===false) throw new DomainError('BACKUP_FAILED');
        $bytes=0;$rows=0;
        $put=static function(string $line) use ($gz,&$bytes): void {
            $bytes+=strlen($line); if ($bytes>self::MAX_BACKUP_BYTES) throw new DomainError('BACKUP_TOO_LARGE');
            if (gzwrite($gz,$line)!==strlen($line)) throw new DomainError('BACKUP_FAILED');
        };
        try {
            $put("-- Banklord automatic pre-update backup ".gmdate('c')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            foreach ($this->db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $meta) {
                if (strtoupper((string)$meta[1])!=='BASE TABLE' || !preg_match('/^[A-Za-z0-9_]{1,64}$/D',(string)$meta[0])) continue;
                $ident='`'.$meta[0].'`';
                $create=$this->db->query('SHOW CREATE TABLE '.$ident)->fetch(PDO::FETCH_NUM);
                $put("\nDROP TABLE IF EXISTS $ident;\n".$create[1].";\n");
                $st=$this->db->query('SELECT * FROM '.$ident);
                while (($row=$st->fetch(PDO::FETCH_NUM))!==false) {
                    if (++$rows>self::MAX_BACKUP_ROWS) throw new DomainError('BACKUP_TOO_LARGE');
                    $put('INSERT INTO '.$ident.' VALUES ('.implode(',',array_map(fn($v)=>$v===null?'NULL':$this->db->quote((string)$v),$row)).");\n");
                }
            }
            $put("SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (\Throwable $e) { gzclose($gz);@unlink($destination);throw $e; }
        gzclose($gz);@chmod($destination,0600);
        return $rows;
    }
}
