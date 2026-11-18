<?php
declare(strict_types=1);
/**
 * Banklord browser update assistant - existing installations only.
 * Upload this file to public/; it authenticates against the existing DB,
 * requires an unmodified release archive and backs up SQL + files outside webroot.
 * This is NOT a remotely self-updating endpoint.
 */
const BANKLORD_VERSION='1.3.0';
const BANKLORD_PAYLOAD_SHA256 = 'cbdaa619562e2c8835ab8405d69e04876a7c2943f53c3c170007c38f9e2166d3';
const BANKLORD_FILE_DIGESTS = [
    'backend/bin/bootstrap.php' => '575d1bc301031fd0063bad52811058c0e5e190d2d0a8abd39fb667ab247446ff',
    'backend/public/app.css' => '78793ec2f08d532a1a6f031c9dfc0cc1c08301063dc38e16108e507189f73ed2',
    'backend/public/app.html' => 'dbadb318e2591a995d7cfbf7e96586b8cd984ef552cc7a06d929f33e860ec183',
    'backend/public/app.js' => 'f073b9707228cb831d67e4959783bf1eabc46252c1fbab902104491f4308a855',
    'backend/public/import.css' => '102ab8535ade1eb3e2f2dff92c1a2b5288e011527b00094d08a5aafc2b35e83c',
    'backend/public/import.html' => 'f98c790170cd69d00295ea1050da6b3e3d67958dfd0b63e81059081f822c3c76',
    'backend/public/import.js' => 'ad7b389f19845a459403cf6ef170293fb39879f17fda6ab3c429c97249e0fc09',
    'backend/public/index.php' => '54068739d13818d3c8b18ead298820df7af79f456b48191ece24ffaaedb95545',
    'backend/public/locales.js' => 'f3da211da6aa2d699c8c233efcda7582f1fd948c6a180d0b01bcb1f75b66239f',
    'backend/sql/001_initial.sql' => '75d065bfcebe8fd62922ee71940cd459c12c2de72f3b485a293902231af53d24',
    'backend/sql/002_scan_staging.sql' => '01c7d6319c3c4ec355dded759e719d1070e0d3b690f0301aa8b965d7db612d23',
    'backend/sql/003_bridge_tokens.sql' => 'b5338b23cd43f1ff89ce001315fc58eb27f6774e0009dac03b19640377f6e65f',
    'backend/sql/004_members_and_comfort.sql' => '4ae300c3084572eee7c0d654d290fc6d8d440b460f7981d67172fdf16a907663',
    'backend/sql/005_procurement.sql' => 'd8ae7bdad0ab88b5e906686cb7f9c411982b8d520153cfd0e34168ed9e176c67',
    'backend/sql/006_peer_drafts.sql' => '3959c16d58b9530c9ac29c9e06f12c91a94eb2593c5ca34a5fccee8c7d989e7b',
    'backend/sql/007_item_localizations.sql' => 'a746b0313734bf53014f2813bcd5f13c2474450ee0781f98758ae93cd118f3cd',
    'backend/sql/008_automatic_scans.sql' => 'bd2890fd7327d691a699578348b38d3bf67f2e0aa03281283ec06ed1b4777d7f',
    'backend/sql/009_scan_retention.sql' => '3a2294e761d5491beb2948edfdb8b4e9768e3bafd35fd5eb990281b027f8bf8d',
    'backend/sql/010_member_order_tokens.sql' => '931069a252f5988d076ebf7a73ac734844c29b446535978adfba10b3a540ca8f',
    'backend/sql/011_release_publishing.sql' => '2d19f35ea10f24b670a5593d8182ba192d7a986ca57db871c447541e12088c60',
    'backend/sql/012_device_heartbeats.sql' => 'a222e6e220db8527c0675f19e9703f86314d9924506b62d13570fd77ed44f4eb',
    'backend/sql/013_banklord_identifiers.sql' => 'f8d7e5132958b2836fb55edb95be0c0d8883553c9845d803f99618de7a899db8',
    'backend/sql/014_order_recipient.sql' => '0c962f8bffb8ec9f362fa893aac5b5f63063f70fcb03bdb820147fa182a3d3f6',
    'backend/sql/015_member_claims.sql' => 'c7ffd33662f4e2735804ceebd445180771c70e1aa1127171668796b0a3065934',
    'backend/sql/016_feedback.sql' => 'cea3177ea8ef425bc667d2940300eedb370cb5900b42d9cd46221aaa004b4b3e',
    'backend/src/BridgeTokens.php' => 'ef3313ad7a78c0545f25ad67dbb0390638d9ddd5fea397674b6c07420a51f3df',
    'backend/src/Claims.php' => '93a7b5773064e143921815d7997073b83d676f9916a5c21d557d4d8e4afb9dad',
    'backend/src/Community.php' => '1dcd0277b91cde9bc5af946d402ad5533f7bad82848435cdc964410161a6e003',
    'backend/src/Config.php' => '74e8f8d8fdb38adf056143feab09372dac4f9752267fdb7a3617d03d13fe5823',
    'backend/src/Database.php' => '99ba9f3f26bc11c1185f517f20ded2407e8cec0e55cdff28938de3b51f117df8',
    'backend/src/Demo.php' => '661031720f32155b8a497268861ab9fb164b23d0505036cb99a84f973b35f91d',
    'backend/src/Domain.php' => '06168dd38b1afbfb71d79ccd33cd05d91ad58287fc6209983fb9f74773c4d26e',
    'backend/src/Feedback.php' => 'a9f43165405fbdea53119df4041ae8509f0995d6c9e0a99b8c7de639b8c98ef6',
    'backend/src/IconMap.php' => '5ede1c33b8f14f41ad757633e07b4538c0d43f45d41b8755feb0bc1dcd2b97c3',
    'backend/src/IngameOrders.php' => 'fbe2ae12956403a2c45824c1887708082c1c5639550fd89a4416b16045ad19fb',
    'backend/src/Installer.php' => 'd5cda2dbdd1e947aa17bb633e8bb41a922ad30082fd4dad04e4c3a5f6523b4d9',
    'backend/src/ItemAppearance.php' => '264777d9f602380124e2576fde50c3ec3dbb80d6d4a31b8908189ba5865d0482',
    'backend/src/ItemMetadata.php' => '2f76c89b1ab9ddd0bc02787485c89517a1c9e65187d96f073ce17fefb36363c9',
    'backend/src/Orders.php' => '25230883b14df9d931e84ae2f459a3906c6d039bb5ccd2aad913d9d2489e65a0',
    'backend/src/PeerRelay.php' => '5d23b7d9c537ec55ba9cbc5353352feed255e390409483d9b19b55bb89204cf3',
    'backend/src/Preferences.php' => 'fbb9030c3c6e2150e7e9ef32568f2f2b7e3aa6bc498ace2f1d7b999ecc403d33',
    'backend/src/Procurement.php' => '7b4eb0e1f41042ecfcef89ac17f178fbdee9c78eeb95d5396934fdbbc4bf64ac',
    'backend/src/ReleasePublisher.php' => '4375c989ec35d69d3bcfff211b49919d7c0ac105d0db90b532fe66ad0a7f1939',
    'backend/src/SavedVariablesParser.php' => '32ab57281622909d17a6b724c7e5158e38338adce8dc7ae4d950f5bb10b84642',
    'backend/src/ScanImport.php' => '9b86162f960747eb35c15f5d326ca68233e47fc6e80d2be361012cc188fff75d',
    'backend/src/ScanStaging.php' => '1892303aa41183bc1f61aed56ad2ce0d5b8e845d0ebeda597d6c8f3786c8da63',
    'backend/src/StockReconciliation.php' => 'dee5a7dfa39525949104ea0be948071cb9e43c530c2560dd71066e7dfc37c5dc',
    'backend/src/UpstreamUpdater.php' => '7af1aa325687c972101781b7b1b83cd8545563bdec0a7c084266a6d6e37dae80',
    'backend/src/Version.php' => '360e666049dcddad9d833dd327cb365d792b0cd6a6df974564e5f7838592a226',
    'backend/src/WebUpdater.php' => '60c07a88cffc83b1670a249d012a07f67db7841025c8a124d2811087d3e8632d',
    'backend/src/icon-filedata.tsv.gz' => '668d50315200dccb7e5728da372d90d136118ecb33474513792e81d5ab9c1832',
];
const BANKLORD_MAX_ARCHIVE_BYTES = 5242880;
const BANKLORD_MAX_BACKUP_SQL_BYTES = 41943040;
const BANKLORD_MAX_BACKUP_ROWS = 150000;
const BANKLORD_SESSION_LIFETIME = 1200;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$secure=(($_SERVER['HTTPS']??'')==='on'||($_SERVER['HTTPS']??'')==='1');
if(!$secure){http_response_code(403);exit('HTTPS erforderlich.');}
if(PHP_VERSION_ID<80200||!class_exists('ZipArchive')||!extension_loaded('pdo_mysql')||!extension_loaded('zlib')){
 http_response_code(503);exit('PHP >= 8.2, ZipArchive, pdo_mysql und zlib erforderlich.');
}
$root=dirname(__DIR__);
$private=$root.'/private';
if(!is_file($private.'/install.lock')||!is_file($private.'/config.local.php')){
 http_response_code(404);exit('Keine vorhandene Installation gefunden. Kein Erstinstaller.');
}
if(is_link(__FILE__)||is_link($private)||is_link($root)||realpath(__DIR__)!==realpath($root.'/public')){
 http_response_code(403);exit('Unsicherer Installationspfad.');
}
$config=require $private.'/config.local.php';
if(!is_array($config)) {http_response_code(503);exit('Private DB-Konfiguration ungültig.');}
foreach(['DB_HOST','DB_NAME','DB_USER','DB_PASS'] as $key){if(!isset($config[$key])||!is_string($config[$key])){http_response_code(503);exit('DB-Konfiguration unvollständig.');}}
if(!in_array($config['DB_HOST'],['localhost','127.0.0.1'],true)) {http_response_code(403);exit('Nur lokale MariaDB zulässig.');}
function db():PDO { global $config;
 return new PDO('mysql:host='.$config['DB_HOST'].';dbname='.$config['DB_NAME'].';charset=utf8mb4',$config['DB_USER'],$config['DB_PASS'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>8]);
}
function h($v):string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function fail_now(string $message):never {throw new RuntimeException($message);}
function csrf():void {if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['banklord_upgrade_csrf'],$_POST['csrf']))fail_now('Sitzung abgelaufen/CSRF-Prüfung fehlgeschlagen.');}
/** Failed-login throttle independent from browser session cookies. */
function login_throttle(bool $success=false):void {
 global $private;
 $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
 $file=$private.'/.upgrade-login-'.hash('sha256',$ip);
 $fh=fopen($file,'c+');if(!$fh)fail_now('Login-Schutz temporär nicht verfügbar.');
 try {
  if(!flock($fh,LOCK_EX))fail_now('Login-Schutz gesperrt.');
  $state=json_decode(stream_get_contents($fh),true);
  if(!is_array($state)||!isset($state['start'])||time()-(int)$state['start']>900)$state=['start'=>time(),'attempts'=>0];
  if(!$success && (int)$state['attempts']>=5)fail_now('Zu viele Loginversuche: Bitte nach 15 Minuten erneut versuchen.');
  $state=$success?['start'=>time(),'attempts'=>0]:['start'=>(int)$state['start'],'attempts'=>(int)$state['attempts']+1];
  rewind($fh);ftruncate($fh,0);fwrite($fh,json_encode($state,JSON_THROW_ON_ERROR));fflush($fh);
  @chmod($file,0600);
 }finally {flock($fh,LOCK_UN);fclose($fh);}
}
function auth_user(PDO $pdo,string $email,string $password):int {
 if(strlen($email)>254||strlen($password)>512||$email===''||$password==='')return 0;
 $q=$pdo->prepare("SELECT u.id,u.password_hash FROM users u WHERE u.email=? AND EXISTS(SELECT 1 FROM guild_memberships m WHERE m.user_id=u.id AND m.role='admin' AND m.status='active') LIMIT 1");
 $q->execute([$email]);$user=$q->fetch();
 return $user && password_verify($password,$user['password_hash'])?(int)$user['id']:0;
}
function authed():bool{return isset($_SESSION['banklord_upgrade_admin'],$_SESSION['banklord_upgrade_time'])&&is_int($_SESSION['banklord_upgrade_admin'])&&time()-(int)$_SESSION['banklord_upgrade_time']<=BANKLORD_SESSION_LIFETIME;}
function ensure_admin(PDO $pdo):void {
 if(!authed())fail_now('Bitte erneut anmelden.');
 $q=$pdo->prepare("SELECT 1 FROM guild_memberships WHERE user_id=? AND role='admin' AND status='active' LIMIT 1");
 $q->execute([$_SESSION['banklord_upgrade_admin']]);if(!$q->fetchColumn())fail_now('Administratorberechtigung nicht mehr vorhanden.');
}
function verify_payload(string $path):array {
 if(!is_file($path)||filesize($path)>BANKLORD_MAX_ARCHIVE_BYTES||filesize($path)<1000)fail_now('Archiv fehlt oder überschreitet das Größenlimit.');
 if(!hash_equals(BANKLORD_PAYLOAD_SHA256,(string)hash_file('sha256',$path)))fail_now('Archivprüfsumme falsch: Bitte ausschließlich das mitgelieferte Payload '.BANKLORD_VERSION.' verwenden.');
 $zip=new ZipArchive();if($zip->open($path)!==true)fail_now('ZIP beschädigt.');
 $out=[];
 try {
   if($zip->numFiles!==count(BANKLORD_FILE_DIGESTS))fail_now('Anzahl der ZIP-Dateien falsch.');
   for($i=0;$i<$zip->numFiles;$i++){
     $stat=$zip->statIndex($i);$name=$stat['name']??'';
     if(!is_string($name)||!array_key_exists($name,BANKLORD_FILE_DIGESTS)||isset($out[$name])||!preg_match('~^backend/(?:public|src|sql|bin)/[A-Za-z0-9_.-]+$~D',$name))fail_now('Unzulässiger ZIP-Pfad.');
     if((int)$stat['size']<1||(int)$stat['size']>350000)fail_now('Ungültige Archivdateigröße.');
     $data=$zip->getFromIndex($i);
     if(!is_string($data)||strlen($data)!==(int)$stat['size']||!hash_equals(BANKLORD_FILE_DIGESTS[$name],hash('sha256',$data)))fail_now('Dateiprüfsumme ungültig: '.$name);
     $out[$name]=$data;
   }
 }finally{$zip->close();}
 if(count($out)!==count(BANKLORD_FILE_DIGESTS))fail_now('Archiv unvollständig.');
 return $out;
}
function assess(PDO $pdo,array $payload):array {
 global $root,$config,$private;
 $marker=$private.'/upgrade-version.json';
 if(is_file($marker)){
   $last=json_decode((string)file_get_contents($marker),true);
   if(is_array($last)&&($last['version']??'')===BANKLORD_VERSION)fail_now('Diese Version ist bereits installiert. Kein erneutes Upgrade erforderlich.');
 }
 foreach($payload as $name=>$data){
   $target=$root.'/'.substr($name,strlen('backend/'));
   if(!is_dir(dirname($target))||is_link($target)||is_link(dirname($target)))fail_now('Fehlendes/unsicheres Zielverzeichnis: '.$name);
   if(!is_writable(dirname($target)))fail_now('Zielverzeichnis nicht beschreibbar: '.$name);
 }
 $sets=[
  '004_members_and_comfort.sql'=>['guild_invitations','user_favorites','user_wishlists','user_order_templates'],
  '005_procurement.sql'=>['guild_restock_rules'],
  '006_peer_drafts.sql'=>['peer_order_drafts'],
  '007_item_localizations.sql'=>['item_client_metadata'],
  '008_automatic_scans.sql'=>['guild_scan_automation'],
  '009_scan_retention.sql'=>['scan_import_receipts'],
  '011_release_publishing.sql'=>['schema_migrations','release_publish_tokens','release_publications'],
  '012_device_heartbeats.sql'=>['device_heartbeats'],
 ];$pending=[];
 $q=$pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
 foreach($sets as $script=>$tables){
  $found=0;foreach($tables as $table){$q->execute([$config['DB_NAME'],$table]);if($q->fetchColumn())$found++;}
  if($found>0&&$found<count($tables))fail_now('Teilmigration erkannt: '.$script.'. Automatik zur Sicherheit gesperrt.');
  if($found===0)$pending[]=$script;
 }
 // 010 only widens an ENUM; detect it by the column definition.
 $col=$pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='bridge_tokens' AND COLUMN_NAME='scope'");
 $col->execute([$config['DB_NAME']]);
 if(!str_contains((string)$col->fetchColumn(),'member.orders'))$pending[]='010_member_order_tokens.sql';
 // Data-only migrations from 013 on are recorded in schema_migrations.
 foreach(['013_banklord_identifiers.sql'] as $script){
  if(in_array('011_release_publishing.sql',$pending,true)){$pending[]=$script;continue;}
  $m=$pdo->prepare('SELECT 1 FROM schema_migrations WHERE name=?');$m->execute([$script]);if(!$m->fetchColumn())$pending[]=$script;
 }
 sort($pending);
 foreach(['users','guilds','guild_memberships','guild_stock','orders','stock_snapshots'] as $table){
  $q->execute([$config['DB_NAME'],$table]);if(!$q->fetchColumn())fail_now('Basistabelle fehlt: '.$table);
 }
 return $pending;
}
function sql_queries(string $sql):array {
 $clean=preg_replace('/^\s*--[^\r\n]*$/m','',$sql);
 if(!is_string($clean))fail_now('SQL ungültig.');
 return array_values(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$clean)?:[]),static fn($x)=>$x!==''));
}
function backup_database(PDO $pdo,string $destination):array {
 $gz=gzopen($destination,'wb6');if($gz===false)fail_now('SQL-Backup nicht anlegbar.');
 $bytes=0;$rows=0;
 $append=static function(string $line)use($gz,&$bytes):void{
  $bytes+=strlen($line);if($bytes>BANKLORD_MAX_BACKUP_SQL_BYTES)fail_now('Automatisches Datenbankbackup zu groß; Upgrade abgebrochen.');
  if(gzwrite($gz,$line)!==strlen($line))fail_now('SQL-Backup-Schreibfehler.');
 };
 try {
  $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
  $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
  $append("-- Banklord Pre-Upgrade Database Backup / private / ".gmdate('c')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
  $tables=$pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
  foreach($tables as $meta){
   if(count($meta)<2||strtoupper((string)$meta[1])!=='BASE TABLE')continue;
   $table=(string)$meta[0];if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table))fail_now('Unzulässiger Tabellenname im Backup.');
   $ident='`'.$table.'`';
   $details=$pdo->query('SHOW CREATE TABLE '.$ident)->fetch(PDO::FETCH_NUM);
   if(!is_array($details)||!isset($details[1]))fail_now('CREATE TABLE fehlt.');
   $append("\nDROP TABLE IF EXISTS ".$ident.";\n".$details[1].";\n");
   $st=$pdo->query('SELECT * FROM '.$ident);$colNames=[];
   for($i=0;$i<$st->columnCount();$i++){
    $c=(string)$st->getColumnMeta($i)['name'];if(!preg_match('/^[A-Za-z0-9_]+$/D',$c))fail_now('Ungültiger Spaltenname.');$colNames[]='`'.$c.'`';
   }
   while(($row=$st->fetch(PDO::FETCH_NUM))!==false){
    if(++$rows>BANKLORD_MAX_BACKUP_ROWS)fail_now('Datenbank zu groß für den sicheren Browser-Export.');
    $values=[];foreach($row as $value){$values[]=$value===null?'NULL':$pdo->quote((string)$value);}
    $append('INSERT INTO '.$ident.' ('.implode(',',$colNames).') VALUES ('.implode(',',$values).");\n");
   }
  }
  $append("SET FOREIGN_KEY_CHECKS=1;\n");$pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();gzclose($gz);@unlink($destination);throw $e;}
 gzclose($gz);@chmod($destination,0600);return ['rows'=>$rows,'raw_sql_bytes'=>$bytes];
}
function upgrade(PDO $pdo,array $payload,array $pending):array {
 global $root,$private;
 $lock=fopen($private.'/.browser-upgrade-lock','c+');
 if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))fail_now('Ein anderer Aktualisierungsvorgang läuft.');
 $dir=$private.'/upgrades/backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5));
 if(!is_dir($private.'/upgrades')&&!mkdir($private.'/upgrades',0700,true))fail_now('Backup-Basisverzeichnis nicht erstellbar.');
 if(!mkdir($dir,0700))fail_now('Backup-Verzeichnis nicht erstellbar.');
 try {
   // Backup *before* any SQL migrations or file replacements.
   $summary=backup_database($pdo,$dir.'/db-preupgrade.sql.gz');
   $old=[];
   foreach($payload as $name=>$data){
     $target=$root.'/'.substr($name,strlen('backend/'));
     if(is_file($target)){
       $relative=substr($name,strlen('backend/'));
       $backup=$dir.'/'.str_replace('/','--',$relative);
       if(!copy($target,$backup))fail_now('Dateisicherung fehlgeschlagen.');
       @chmod($backup,0600);$old[$target]=$backup;
     }
   }
   // Migrations are idempotency-gated; MariaDB DDL is NOT rollbackable.
   foreach($pending as $script){foreach(sql_queries($payload['backend/sql/'.$script]) as $query)$pdo->exec($query);}
   $written=[];
   try {
     // Replace entry point last, after all business code and assets are updated.
     $order=array_keys($payload);usort($order,static function($a,$b){return (($a==='backend/public/index.php')?1:0)<=> (($b==='backend/public/index.php')?1:0);});
     foreach($order as $name){
       $target=$root.'/'.substr($name,strlen('backend/'));
       $tmp=dirname($target).'/.'.basename($target).'.up-'.bin2hex(random_bytes(5));
       if(file_put_contents($tmp,$payload[$name],LOCK_EX)!==strlen($payload[$name]))fail_now('Schreiben fehlgeschlagen: '.$name);
       @chmod($tmp,str_starts_with($name,'backend/public/')?0644:0600);
       if(!rename($tmp,$target)){@unlink($tmp);fail_now('Dateiersatz fehlgeschlagen: '.$name);}
       $written[]=$target;
     }
     $marker=json_encode(['version'=>BANKLORD_VERSION,'at'=>gmdate('c'),'db_backup'=>basename($dir)],JSON_THROW_ON_ERROR);
     if(file_put_contents($private.'/upgrade-version.json',$marker,LOCK_EX)===false)fail_now('Versionsmarkierung fehlgeschlagen.');
     @chmod($private.'/upgrade-version.json',0600);
   }catch(Throwable $err){
     // File rollback; database rollback is NOT claimed.
     foreach(array_reverse($written) as $target){if(isset($old[$target]))copy($old[$target],$target);else @unlink($target);}
     throw $err;
   }
   return ['folder'=>$dir,'migrations'=>$pending,'file_count'=>count($payload),'backup'=>$summary];
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}

session_name('banklord_session');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
if(!isset($_SESSION['banklord_upgrade_csrf']))$_SESSION['banklord_upgrade_csrf']=bin2hex(random_bytes(32));
$notice='';$error='';$result=null;
try {
 if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf();$action=(string)($_POST['action']??'');$pdo=db();
  if($action==='login'){
    login_throttle();
    $_SESSION['upgrade_attempts']=(int)($_SESSION['upgrade_attempts']??0)+1;
    if($_SESSION['upgrade_attempts']>5)fail_now('Zu viele Versuche. Bitte Sitzung schließen und Zugang kontrollieren.');
    $uid=auth_user($pdo,trim((string)($_POST['email']??'')),(string)($_POST['password']??''));
    if(!$uid){usleep(350000);fail_now('Anmeldung fehlgeschlagen. Nur aktive Administratoren können aktualisieren.');}
    login_throttle(true);
    session_regenerate_id(true);$_SESSION['banklord_upgrade_csrf']=bin2hex(random_bytes(32));
    $_SESSION['banklord_upgrade_admin']=$uid;$_SESSION['banklord_upgrade_time']=time();$_SESSION['upgrade_attempts']=0;$notice='Administrator bestätigt.';
  }else{
    ensure_admin($pdo);
    if($action==='upload'){
      if(!isset($_FILES['payload'])||!is_array($_FILES['payload'])||($_FILES['payload']['error']??1)!==UPLOAD_ERR_OK)fail_now('Dateiupload fehlgeschlagen.');
      if((int)$_FILES['payload']['size']>BANKLORD_MAX_ARCHIVE_BYTES)fail_now('Archiv zu groß.');
      $incoming=$private.'/upgrades/incoming';if(!is_dir($incoming)&&!mkdir($incoming,0700,true))fail_now('Privater Upload-Ordner nicht erstellbar.');
      $dest=$incoming.'/bl-'.bin2hex(random_bytes(12)).'.zip';
      if(!move_uploaded_file((string)$_FILES['payload']['tmp_name'],$dest))fail_now('Upload nicht sicher speicherbar.');
      @chmod($dest,0600);
      try{$files=verify_payload($dest);$pending=assess($pdo,$files);}catch(Throwable $e){@unlink($dest);throw $e;}
      if(isset($_SESSION['banklord_upgrade_zip'])&&is_string($_SESSION['banklord_upgrade_zip']))@unlink($_SESSION['banklord_upgrade_zip']);
      $_SESSION['banklord_upgrade_zip']=$dest;$_SESSION['banklord_upgrade_time']=time();$notice='Archiv authentifiziert. '.count($files).' Dateien. Fehlende Migrationen: '.($pending?implode(', ',$pending):'keine').'.';
    }elseif($action==='apply'){
      $pass=(string)($_POST['password']??'');
      $q=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$_SESSION['banklord_upgrade_admin']]);
      if(!($hash=$q->fetchColumn())||!password_verify($pass,(string)$hash))fail_now('Administratorpasswort für Freigabe ungültig.');
      if(empty($_POST['confirm_backup'])||empty($_POST['confirm_maintenance']))fail_now('Bitte die beiden Sicherheitsbestätigungen setzen.');
      $zip=$_SESSION['banklord_upgrade_zip']??null;
      if(!is_string($zip)||!str_starts_with($zip,$private.'/upgrades/incoming/'))fail_now('Zuerst Paket hochladen.');
      $files=verify_payload($zip);$pending=assess($pdo,$files);
      @set_time_limit(180);$result=upgrade($pdo,$files,$pending);
      @unlink($zip);unset($_SESSION['banklord_upgrade_zip']);
      $notice='Update erfolgreich abgeschlossen: '.BANKLORD_VERSION.'. '.count($pending).' Migration(en), '.$result['file_count'].' Dateien. SQL-Backup im privaten Serverordner. Bitte Installerdatei entfernen.';
      // installer remains locked for same version; remove file manually if unlink is denied.
      @unlink(__FILE__);
    }else fail_now('Unbekannte Aktion.');
  }
 }
}catch(Throwable $e){$error=$e->getMessage();error_log('Banklord updater: '.get_class($e).' (operation failed)');}
$authenticated=authed();
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Banklord – Web-Update</title>
<style>body{margin:0;background:#0e1726;color:#edf4ff;font:16px/1.5 system-ui,Arial,sans-serif}main{max-width:790px;margin:25px auto;padding:20px}section{background:#172338;border:1px solid #334866;padding:22px;border-radius:15px;margin-bottom:18px}h1{font-size:27px}h2{font-size:19px}label{display:block;margin:13px 0 5px;font-weight:600}input[type=email],input[type=password],input[type=file]{background:#0c1828;border:1px solid #55739a;border-radius:8px;color:white;padding:12px;box-sizing:border-box;width:100%}input[type=checkbox]{width:auto;margin-right:8px}button{background:#3981c4;border:0;padding:12px 18px;color:white;border-radius:9px;font-weight:700;cursor:pointer;margin-top:18px}.notice{color:#c5d9eb}.error{background:#592833;padding:15px;border-radius:9px}.ok{background:#204b3d;padding:15px;border-radius:9px}small{color:#b8c7d8}strong{color:#fff}code{font-family:ui-monospace,monospace;overflow-wrap:anywhere}
/* Universal Classic update installer UI: visual parity with Banklord Web */
body{background:radial-gradient(ellipse at 45% -10%,#383228,#101010 63%,#090908);color:#eee5d1;font:14px/1.48 Arial,"Trebuchet MS",sans-serif}
main{max-width:950px;margin:14px auto;padding:15px}
section{background:linear-gradient(#191816,#0a0a0a);border:4px ridge #655f56;border-radius:1px;padding:18px 20px;margin-bottom:12px;box-shadow:inset 0 0 0 1px #110e09}
h1{font-size:22px;font-weight:500;color:#f6cf7c;border-bottom:3px groove #72614e;padding:10px 0 16px;text-shadow:0 1px 3px #000}
h2{color:#d4b36a;font-size:16px;font-weight:500}
label{color:#e9d9bc}
input[type=email],input[type=password],input[type=file]{background:#090909;border:1px solid #70624d;color:#fff4df;border-radius:1px;padding:10px}
button{background:linear-gradient(#7c1009,#4d0804 70%,#260905);border:2px ridge #8a6d52;color:#f9df9c;border-radius:2px;font-weight:600;box-shadow:inset 0 1px #b34f2d}
button:hover{border-color:#caa15b;background:linear-gradient(#962210,#590904)}
.notice{color:#cfc3ac}.ok{background:#173322;border:1px solid #5e936a}.error{background:#4d171a;border:1px solid #ad5959}
small{color:#c0b49f}strong{color:#f3dfa9}
@media(max-width:650px){main{padding:8px;margin:0}section{padding:12px}h1{font-size:19px}}
</style></head><body><main>
<h1>Banklord · Browser-Update <?=h(BANKLORD_VERSION)?></h1>
<p class="notice">Nur für eine <strong>bestehende</strong> Installation. Keine SSH-Verbindung erforderlich. Die Datenbank wird nicht neu angelegt.</p>
<?php if($error!==''):?><p class="error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if($notice!==''):?><p class="ok" role="status"><?=h($notice)?></p><?php endif;?>
<?php if(!$authenticated):?>
<section><h2>1. Als Administrator anmelden</h2><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['banklord_upgrade_csrf'])?>"><input type="hidden" name="action" value="login"><label for="email">Administrator-E-Mail</label><input id="email" name="email" type="email" required autocomplete="username"><label for="pwd">Passwort</label><input id="pwd" name="password" type="password" required autocomplete="current-password"><button>Weiter</button></form></section>
<?php elseif($result===null):?>
<section><h2>2. Geprüftes Projektpaket hochladen</h2><p>Verwende ausschließlich <code>Banklord-Web-Payload-<?=h(BANKLORD_VERSION)?>.zip</code> – es wird vollständig anhand fester Prüfsummen geprüft.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h($_SESSION['banklord_upgrade_csrf'])?>"><input type="hidden" name="action" value="upload"><label for="archive">Webanwendung-Archiv</label><input id="archive" name="payload" type="file" accept=".zip,application/zip" required><button>Prüfen und vorbereiten</button></form></section>
<?php if(isset($_SESSION['banklord_upgrade_zip'])&&is_string($_SESSION['banklord_upgrade_zip'])&&is_file($_SESSION['banklord_upgrade_zip'])):?>
<section><h2>3. Gesichertes Update durchführen</h2><p>Der Assistent erstellt vor Änderungen automatisch ein komprimiertes SQL-Backup sowie Dateisicherungen <strong>außerhalb von public/</strong>. Bei zu großer Datenbank wird das Update abgebrochen.</p><p class="notice">Für die Wiederherstellung nach teilweise ausgeführten SQL-Migrationen muss der private SQL-Dump gegebenenfalls über KAS eingespielt werden. Ein vollständiger Datenbank-Rollback kann nicht garantiert werden.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['banklord_upgrade_csrf'])?>"><input type="hidden" name="action" value="apply"><label><input type="checkbox" name="confirm_backup" required value="1">Ich habe außerdem eine unabhängige Datenbanksicherung außerhalb des Webspaces.</label><label><input type="checkbox" name="confirm_maintenance" required value="1">Ich bestätige das kurze Wartungsfenster und habe die aktuelle Anwendung gesichert.</label><label for="pass2">Administratorpasswort erneut eingeben</label><input id="pass2" type="password" name="password" required autocomplete="current-password"><button>Geprüftes Update installieren</button></form></section>
<?php endif;?>
<?php endif;?>
<section><h2>Schutzmaßnahmen</h2><p><small>HTTPS-Pflicht · Administratorrolle und Passwort · CSRF-Schutz · geprüfte ZIP-Prüfsummen · keine externe Datenbankverbindung · Dateisperre gegen Parallelupdates · SQL- und Dateisicherungen außerhalb des Webroots · kein Ausführen fremder ZIP-Inhalte</small></p><p><small>Nach erfolgreicher Aktualisierung bitte die Installerdatei kontrollieren und löschen, falls sie sich nicht selbst entfernen konnte.</small></p></section></main></body></html>
