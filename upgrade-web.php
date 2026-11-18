<?php
declare(strict_types=1);
/**
 * Banklord browser update assistant - existing installations only.
 * Upload this file to public/; it authenticates against the existing DB,
 * requires an unmodified release archive and backs up SQL + files outside webroot.
 * This is NOT a remotely self-updating endpoint.
 */
const BANKLORD_VERSION='1.8.1';
const BANKLORD_PAYLOAD_SHA256 = 'be4a5dd701d90edb280ab1fe336807ea84284970782fe8455686a435f4ab54de';
const BANKLORD_FILE_DIGESTS = [
    'backend/bin/bootstrap.php' => 'fecb24898873e1e77cd01f2a2827416c6f45db47dc32f03f17f0f07be5fe661d',
    'backend/public/app.css' => 'c44ef847ff396929250bb5b0a67217019f123ce3a56cbaea24ed689dceb59ac6',
    'backend/public/app.html' => '9fb0e0169261542ba5fd827b245b253856c8b3e00e6dae0cd1e307735c5e323d',
    'backend/public/app.js' => 'c514d2df507bc7c9b84ce598871c7343a2c447eedb676ec5faac2e87d8337d12',
    'backend/public/import.css' => '102ab8535ade1eb3e2f2dff92c1a2b5288e011527b00094d08a5aafc2b35e83c',
    'backend/public/import.html' => 'f98c790170cd69d00295ea1050da6b3e3d67958dfd0b63e81059081f822c3c76',
    'backend/public/import.js' => 'ad7b389f19845a459403cf6ef170293fb39879f17fda6ab3c429c97249e0fc09',
    'backend/public/index.php' => 'f8292bff122b81a212c0da203b6f8fb1f6c7d08ad204083e1fd5e27323a1c954',
    'backend/public/locales.js' => '4328f5ba480de4aedb1fc632b76651dc712ecd1daa2520a9793956f305e2df33',
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
    'backend/sql/017_order_rules.sql' => '0c12a6544eca7f15e6c887cd47f1d4499fb962abcbc4acfd5dcff5144677c7fd',
    'backend/sql/018_ledger_discord.sql' => 'e36c19042277a568ebf3ad4b0f5a082421935260d968e03f8b777799081d1f13',
    'backend/src/BankLedger.php' => '86d037b8af9a9a83ffb2f7a7b86addf398233544dc7c2cc188140d6c5678ea84',
    'backend/src/BridgeTokens.php' => 'ef3313ad7a78c0545f25ad67dbb0390638d9ddd5fea397674b6c07420a51f3df',
    'backend/src/Claims.php' => '93a7b5773064e143921815d7997073b83d676f9916a5c21d557d4d8e4afb9dad',
    'backend/src/Community.php' => '1dcd0277b91cde9bc5af946d402ad5533f7bad82848435cdc964410161a6e003',
    'backend/src/Config.php' => '74e8f8d8fdb38adf056143feab09372dac4f9752267fdb7a3617d03d13fe5823',
    'backend/src/Database.php' => '99ba9f3f26bc11c1185f517f20ded2407e8cec0e55cdff28938de3b51f117df8',
    'backend/src/Demo.php' => '661031720f32155b8a497268861ab9fb164b23d0505036cb99a84f973b35f91d',
    'backend/src/Discord.php' => '2fb26226dc518eb403f049e4316f954a1167c5b797fe23eecdadcd1d51eb1c2c',
    'backend/src/DocsPage.php' => '2268244f09f00e7c25dd7737fd25050a89112152f4a7ede16b9a241c5bd0b82c',
    'backend/src/Domain.php' => '91192f72ec033de0e76c5c003e1aa055968cad6476371a43e61dc91d6e7b1dd2',
    'backend/src/Feedback.php' => 'a9f43165405fbdea53119df4041ae8509f0995d6c9e0a99b8c7de639b8c98ef6',
    'backend/src/GameFlavor.php' => 'e0db150979873e71fc6f0f67085007f70df1c7d104194586209d45f25151b289',
    'backend/src/Guilds.php' => '8b6379fae90ca93e86904e536382bae94f471f77397d566692a3027d70e26faf',
    'backend/src/IconMap.php' => '5ede1c33b8f14f41ad757633e07b4538c0d43f45d41b8755feb0bc1dcd2b97c3',
    'backend/src/IngameOrders.php' => 'dc873748df8b85320e9ff42755d6dab1b2aaa09481ca15df3359881254b7b945',
    'backend/src/Installer.php' => 'e2dff693b85d4d56fa22307ff78be0c0665153bd884430d500902d2458074337',
    'backend/src/ItemAppearance.php' => '7d8f231f7bad301aae7d527569da2a3c55056478279a44e13453d57f2c5d2169',
    'backend/src/ItemMetadata.php' => 'b636475f3ebf4b4d278bfeb9dfbba58f63e37534b477c3c7523ccf617372a252',
    'backend/src/OrderRules.php' => '9528281240b1f79bf71f7e1207832e48d42560ce7feb01c08b2f51b48b29122f',
    'backend/src/Orders.php' => '02e701ea540ef23c5929472819bf16448da8181691fc37f8edef3ca19cb0a032',
    'backend/src/PeerRelay.php' => '5d23b7d9c537ec55ba9cbc5353352feed255e390409483d9b19b55bb89204cf3',
    'backend/src/Preferences.php' => 'fbb9030c3c6e2150e7e9ef32568f2f2b7e3aa6bc498ace2f1d7b999ecc403d33',
    'backend/src/Procurement.php' => '7b4eb0e1f41042ecfcef89ac17f178fbdee9c78eeb95d5396934fdbbc4bf64ac',
    'backend/src/ReleasePublisher.php' => '4375c989ec35d69d3bcfff211b49919d7c0ac105d0db90b532fe66ad0a7f1939',
    'backend/src/SavedVariablesParser.php' => '32ab57281622909d17a6b724c7e5158e38338adce8dc7ae4d950f5bb10b84642',
    'backend/src/ScanImport.php' => 'cbe190313c916d85f4e14143d8812f08d51c7e4f42fbcf5c67a3d618c08cd343',
    'backend/src/ScanStaging.php' => 'a0cde139466e12a55e6618622fdff5522b030cdf78dd2cdde94d77764920ed03',
    'backend/src/StockReconciliation.php' => 'e3cd7f85b0328e47efc7938e5a594c85cbda06fba49513436b858f6093c9cc81',
    'backend/src/UpstreamUpdater.php' => '7af1aa325687c972101781b7b1b83cd8545563bdec0a7c084266a6d6e37dae80',
    'backend/src/Version.php' => 'e4f39e7eb8a87ac75ce736ac15be73c3aea3f18cf425d7a9cc58137076aa55ea',
    'backend/src/WebUpdater.php' => '60c07a88cffc83b1670a249d012a07f67db7841025c8a124d2811087d3e8632d',
    'backend/src/doc_README.en.md' => '2462f8e40c6e88961bcc3081aecc83d5f70b97c2d64c746e5f3106e5cb43fb5d',
    'backend/src/doc_README.md' => 'd016deeea07cfb4d893c77e459c781c76fdf5dc5a6cc0d189a59b51268c95547',
    'backend/src/doc_bankverwalter.en.md' => 'c90725e8a4f0c1c1f36d3a479ba621f736e1ffafe1d6887ee6e821fd8b6ddfa0',
    'backend/src/doc_bankverwalter.md' => 'd33ad5d883513503baa29222337affa9ebdcc5f9ac25ed934b30df0c4d6db1aa',
    'backend/src/doc_entwicklung.en.md' => 'fe59ef2f568b76efab67d832d1e35bc06de69a3ae8f25f5ea0e6c2c781530942',
    'backend/src/doc_entwicklung.md' => 'e0607c97d632f5b6645dbdb4ac5ebe85c3c1cfd11119d3ca7635596f17ab1b0a',
    'backend/src/doc_faq.en.md' => '5538acfcc0dd2f8eb8895c8fb14e07ebe48fed8744ddb21cec454d5570bbb50e',
    'backend/src/doc_faq.md' => '55c207898a64b38b43c96987658a1de209405b4641bc02dc087c7f3404910a9b',
    'backend/src/doc_installation.en.md' => '647e2d5838614494e12108d83adc097f0496b9e19a69be7470f74020adf6fef7',
    'backend/src/doc_installation.md' => 'c50bdead90d914d83ba87753c7ff79a6281a0e3e007a58d667a1eab0df92edc9',
    'backend/src/doc_mitglieder.en.md' => '15cf12ef4e1adef18bf26f0db43915e3219df90f01d0d9610c0f8413a31fa7e1',
    'backend/src/doc_mitglieder.md' => '401a8d87f7f3787f264cb0fb2651211e65fc926861dc85bd58d36b25ee4280a5',
    'backend/src/doc_neuigkeiten.en.md' => '479befef6be7dd8e996b015851a3f0cb0a06b98a578e5c50464b0a391777ae8c',
    'backend/src/doc_neuigkeiten.md' => 'f5de9c4de438a7c1ae38e862b49c47808c48175e13f886d018d8fc6876ffa3cf',
    'backend/src/doc_selbst-hosten.en.md' => '8a774660075d11dc90e90ace5d8f3f6552f0834f195258147ad03b558e0999b1',
    'backend/src/doc_selbst-hosten.md' => '0fa2a6b950de0fbc422ef3a370df53470b52be021a5434e947298b96c491bb04',
    'backend/src/doc_sicherheit.en.md' => 'ca3d216d62eaa250fed4a08ea4dd0dfe400d0f3456cca37b8cf7becd1038a3b0',
    'backend/src/doc_sicherheit.md' => '9d4dc010bb179a2f5e08758ab73ef7e72a43368797b3eeac3d075667da034ff5',
    'backend/src/docimg_banklord-logo.png' => '5c2363c0158d41c5c39b3f6af8d156f80869f38e1fde884c805327294bb707c1',
    'backend/src/docimg_companion-bereit.webp' => '910096f29a473aba8c5ab84176f12c274db2886783238e5f2c60f52de76f7ae3',
    'backend/src/docimg_companion-freischalten.webp' => '800f306e41c0af42f7b19755c9cc522017f0542c02b44da5ac8710923b1fe0c2',
    'backend/src/docimg_setup-installieren.webp' => 'd6fbefbc4f9f8a306cbca2c5f226cfd199fb6d34ecb8d59128f80ef45c9a25c1',
    'backend/src/docimg_skizze-bank.webp' => '21c7d2cc472ae336cd18ec02f0d81542154ba7b35c44324a0f9287b75f37300b',
    'backend/src/docimg_skizze-mail.webp' => '1af36d998299483912dd396ea12a76aba38941354f5e0d90bc1e754b1532fef8',
    'backend/src/docimg_skizze-order.webp' => '589b554db3467dfc60d2d05c90d37a6643f5a1357b85bab045923ddd1111255c',
    'backend/src/docimg_skizze-popup-manager.webp' => '71beaa5451bc17e41748b569678e6d11940dd25467640543be6564804950352b',
    'backend/src/docimg_skizze-popup-member.webp' => 'c9692c56c315ac07088c08f3c9365c8482cd971d66e8f4f353d385dbb61dd843',
    'backend/src/docimg_skizze-smartscreen.webp' => '14495e165f4fea531051afec2c72091dc834ddda36b8b9dcffc9221c658fa8d9',
    'backend/src/docimg_website-auftraege.webp' => 'e623f4febd0fd3a8f2015e360fd46422a9f0a4f4c9f10fa2c0bbbcfa6b9b3612',
    'backend/src/docimg_website-gegenstand.webp' => 'ad8d7b7b67712875fa0c2a0c3627c57b09b54baafcdc8391d3c625bd7d86e9cf',
    'backend/src/docimg_website-kassenbuch.webp' => 'c7971324564f8eae09c487dfda659c10cdfe675f737f02a729f430158b84aa3d',
    'backend/src/docimg_website-meine-bestellungen.webp' => '3cd24023fc25db6b8fea9d646b0ee781f94b029430fd9003713a8584c9878f50',
    'backend/src/docimg_website-mitglieder.webp' => 'e448f57c908f0fa6fdcc7777c5829ac3fc77558b063b56d7cde02a16c0df6c5c',
    'backend/src/docimg_website-nachkauf.webp' => '7bc2eef72426ddbe6bbbc11b4304d9dd3820e09e93fb86582b2670c07ff62bed',
    'backend/src/docimg_website-start.webp' => 'c8df1fe5f09b20fa6146e584638f80c21cecec8d4b13d35ccd956b3362ade9bc',
    'backend/src/docimg_website-warenkorb.webp' => '96cb2007ddf0f4d2477973978359d51d0bfe1080cd10c3f7436b41ea5dd6c936',
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
