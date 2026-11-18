<?php
declare(strict_types=1);
use Banklord\Database;
use Banklord\Orders;
use Banklord\DomainError;
use Banklord\ScanImport;
use Banklord\ScanStaging;
use Banklord\BridgeTokens;
use Banklord\IngameOrders;
use Banklord\Claims;
use Banklord\Feedback;
use Banklord\WebUpdater;
use Banklord\GameFlavor;
use Banklord\BankLedger;
use Banklord\Guilds;
use Banklord\ReleasePublisher;
use Banklord\UpstreamUpdater;
use Banklord\Demo;
use Banklord\StockReconciliation;
use Banklord\Community;
use Banklord\Preferences;
use Banklord\Procurement;
use Banklord\PeerRelay;
use Banklord\OrderRules;

require dirname(__DIR__) . '/src/Config.php';
\Banklord\Config::load();
require dirname(__DIR__) . '/src/Domain.php';
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Orders.php';
require dirname(__DIR__) . '/src/OrderRules.php';
require dirname(__DIR__) . '/src/BankLedger.php';
require dirname(__DIR__) . '/src/Guilds.php';

require dirname(__DIR__) . '/src/SavedVariablesParser.php';
require dirname(__DIR__) . '/src/ScanImport.php';
require dirname(__DIR__) . '/src/ScanStaging.php';
require dirname(__DIR__) . '/src/BridgeTokens.php';
require dirname(__DIR__) . '/src/IngameOrders.php';
require dirname(__DIR__) . '/src/Feedback.php';
require dirname(__DIR__) . '/src/Claims.php';
require dirname(__DIR__) . '/src/WebUpdater.php';
require dirname(__DIR__) . '/src/ReleasePublisher.php';
require dirname(__DIR__) . '/src/UpstreamUpdater.php';
require dirname(__DIR__) . '/src/Installer.php';
require dirname(__DIR__) . '/src/Demo.php';
require dirname(__DIR__) . '/src/StockReconciliation.php';
require dirname(__DIR__) . '/src/Community.php';
require dirname(__DIR__) . '/src/Preferences.php';
require dirname(__DIR__) . '/src/Procurement.php';
require dirname(__DIR__) . '/src/PeerRelay.php';
require dirname(__DIR__) . '/src/ItemAppearance.php';
require dirname(__DIR__) . '/src/ItemMetadata.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

if (getenv('APP_HTTPS') !== 'off' && (($_SERVER['HTTPS'] ?? '') !== 'on')) {
    http_response_code(400); echo json_encode(['error'=>'HTTPS_REQUIRED']); exit;
}
if (!\Banklord\Installer::configured(dirname(__DIR__))) \Banklord\Installer::handle(dirname(__DIR__));
// Public signed update channel: no session, no database. Clients verify the
// Ed25519 signature themselves; the server only hands out stored files.
$updatePath=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if (is_string($updatePath) && str_starts_with($updatePath,'/updates/preview/')) {
    $releaseDir=dirname(__DIR__).'/private/releases';
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {http_response_code(405);exit;}
    if ($updatePath==='/updates/preview/manifest.json' || $updatePath==='/updates/preview/events.php') {
        $manifest=@file_get_contents($releaseDir.'/manifest.json');
        if (!is_string($manifest) || $manifest==='') {http_response_code(404);echo json_encode(['error'=>'NO_RELEASE']);exit;}
        header('Cache-Control: no-store, max-age=0');
        echo $updatePath==='/updates/preview/events.php' ? json_encode(['digest'=>hash('sha256',$manifest)]) : $manifest;
        exit;
    }
    if (preg_match('~^/updates/preview/files/([a-f0-9]{64})\.(zip|exe)$~D',$updatePath,$fm) && is_file($releaseDir.'/files/'.$fm[1].'.'.$fm[2])) {
        $file=$releaseDir.'/files/'.$fm[1].'.'.$fm[2];
        header('Content-Type: application/octet-stream');
        header('Content-Length: '.filesize($file));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($file);exit;
    }
    http_response_code(404);echo json_encode(['error'=>'NOT_FOUND']);exit;
}
// Stable download link for the newest Windows setup of the signed release channel.
if ($updatePath==='/api/v1/download/setup') {
    $manifest=@file_get_contents(dirname(__DIR__).'/private/releases/manifest.json');
    $env=is_string($manifest)?json_decode($manifest,true):null;
    $payload=is_array($env)&&is_string($env['payload']??null)?json_decode((string)base64_decode($env['payload'],true),true):null;
    // ?os=mac-arm64|mac-intel|linux-x64|linux-arm64, default Windows.
    $targets=['mac-arm64'=>['darwin-arm64','macOS-AppleSilicon'],'mac-intel'=>['darwin-amd64','macOS-Intel'],'linux-x64'=>['linux-amd64','Linux-x64'],'linux-arm64'=>['linux-arm64','Linux-ARM64']];
    $os=(string)($_GET['os']??'windows');
    if (isset($targets[$os])) {
        $entry=$payload['apps'][$targets[$os][0]]??[];$ext='zip';$label=$targets[$os][1];
    } else {
        $entry=$payload['updater']??[];$ext='exe';$label='Windows-x64';
    }
    $sha=$entry['sha256']??'';$version=$entry['version']??'';
    $file=dirname(__DIR__).'/private/releases/files/'.$sha.'.'.$ext;
    if (!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D',$sha) || !is_string($version)
        || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]{1,40})?$/D',$version) || !is_file($file)) {
        http_response_code(404);echo json_encode(['error'=>'NO_SETUP_PUBLISHED']);exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.($ext==='exe'?'Banklord-Setup-'.$version.'-Windows-x64.exe':'Banklord-'.$version.'-'.$label.'.zip').'"');
    header('Content-Length: '.filesize($file));
    header('Cache-Control: no-store');
    header('X-BL-SHA256: '.$sha);
    readfile($file);exit;
}
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
ini_set('session.cookie_httponly','1');
$https=getenv('APP_HTTPS') !== 'off';
session_name('banklord_session');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));

function reply(int $status, array $data): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);exit;}
function body(): array {
    if ((int)($_SERVER['CONTENT_LENGTH']??0) > 65536) reply(413,['error'=>'BODY_TOO_LARGE']);
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')) reply(415,['error'=>'JSON_REQUIRED']);
    $decoded=json_decode(file_get_contents('php://input'),true,32);
    if (!is_array($decoded) || array_is_list($decoded)) reply(400,['error'=>'INVALID_JSON_OBJECT']);
    return $decoded;
}
function mustLogin(): int {
    if (!isset($_SESSION['uid']) || !is_int($_SESSION['uid'])) reply(401,['error'=>'LOGIN_REQUIRED']);
    return $_SESSION['uid'];
}
function csrf(): void {
    $token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'],$token)) reply(403,['error'=>'CSRF_INVALID']);
}
function siteOrigin(): string { return (getenv('APP_HTTPS')==='off'?'http':'https').'://'.preg_replace('/[^A-Za-z0-9.:\-]/','',(string)($_SERVER['HTTP_HOST']??'')); }
function id(string $value): int { if (!preg_match('/^[1-9][0-9]{0,17}$/D',$value)) reply(400,['error'=>'INVALID_ID']); return (int)$value; }
/**
 * Catalog icons: one bounded, guild-scoped lookup for the entire visible page.
 * The only trusted rendering URLs are constructed from safe icon names.
 * Item availability is NEVER derived from this optional metadata.
 */
function catalogIcons(PDO $db,int $guild,array &$rows,string $locale): void {
    if (!$rows || !in_array($locale,\Banklord\ItemMetadata::LOCALES,true)) return;
    $requested=[];
    foreach ($rows as &$row) {
        $row['icon_url']=null;$row['icon_status']='unavailable';
        $row['icon_quality']=null;$row['category']='Weitere Gegenstände';$row['subcategory']=null;
        $key=hash('sha256',(string)$row['variant_key']);
        $requested[$key]=true;
    }
    unset($row);
    $hashes=array_keys($requested);
    $holders=implode(',',array_fill(0,count($hashes),'?'));
    $s=$db->prepare("SELECT wow_item_id,variant_hash,icon_name,icon_file_id,quality,item_type,item_sub_type FROM item_client_metadata
        WHERE guild_id=? AND client_flavor=(SELECT wow_flavor FROM guilds WHERE id=guild_id)
        AND locale=? AND variant_hash IN ($holders) ORDER BY captured_at DESC, client_build DESC, id DESC");
    $s->execute(array_merge([$guild,$locale],$hashes));
    $known=[];
    while ($m=$s->fetch(PDO::FETCH_ASSOC)) {
        $key=$m['variant_hash'].':'.(int)$m['wow_item_id'];
        if (!array_key_exists($key,$known)) $known[$key]=$m;
    }
    foreach ($rows as &$row) {
        $key=hash('sha256',(string)$row['variant_key']).':'.(int)$row['wow_item_id'];
        $meta=$known[$key]??null;
        if ($meta===null) continue;
        $category=$meta['item_type'];$subcategory=$meta['item_sub_type'];
        if (is_string($category) && $category!=='' && mb_strlen($category)<=100) $row['category']=$category;
        if (is_string($subcategory) && $subcategory!=='' && mb_strlen($subcategory)<=100 && $subcategory!==$row['category']) $row['subcategory']=$subcategory;
        $url=\Banklord\ItemMetadata::iconUrl($meta['icon_name'], $meta['icon_file_id']);
        if ($url!==null) {
            $row['icon_url']=$url;
            $row['icon_status']=\Banklord\IconMap::name($meta['icon_file_id'])!==null?'from-bank-texture':'from-client-snapshot';
        } elseif ($meta['icon_file_id']!==null) {
            $row['icon_status']='file-id-unresolved';
        }
        $q=$meta['quality'];
        if ($q!==null && ctype_digit((string)$q) && (int)$q<=7) $row['icon_quality']=(int)$q;
    }
    unset($row);
}

function hasMember(PDO $db,int $guild,int $user): string {
    $s=$db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'");$s->execute([$guild,$user]);
    $role=$s->fetchColumn(); if(!$role) reply(403,['error'=>'FORBIDDEN']); return (string)$role;
}

try {
    $db=Database::connect();
    register_shutdown_function(static fn() => UpstreamUpdater::autoAfterResponse($db, dirname(__DIR__)));
    // Discord messages queued during this request go out after the response.
    register_shutdown_function(static function() use ($db) { try { \Banklord\Discord::flush($db); } catch (\Throwable $e) {} });
    $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    // If hosted in subdirectory, set rewrite base and route into this script's PATH_INFO.
    $path=preg_replace('~^/api/v1~','',$path,1,$matched);
    if ($matched===0) reply(404,['error'=>'NOT_FOUND']);
    // Public demo: read-only session on a separate sample guild.
    $isDemo=!empty($_SESSION['demo']);
    if ($isDemo && $method!=='GET' && $path!=='/logout') reply(403,['error'=>'DEMO_READ_ONLY']);
    if ($isDemo && preg_match('~/(?:release-tokens|upstream-update|bridge-tokens|demo-setting)~',$path)) reply(403,['error'=>'DEMO_HIDDEN']);
    if ($method==='GET' && $path==='/demo') reply(200,['enabled'=>(new Demo($db,dirname(__DIR__)))->enabled(),'active'=>$isDemo]);
    if ($method==='POST' && $path==='/demo/start') {
        csrf();
        $demoUser=(new Demo($db,dirname(__DIR__)))->start();
        session_regenerate_id(true);$_SESSION['uid']=$demoUser;$_SESSION['demo']=true;$_SESSION['csrf']=bin2hex(random_bytes(32));
        reply(200,['ok'=>true]);
    }
    if ($method==='GET' && $path==='/session') reply(200,['demo'=>$isDemo,'csrf'=>$_SESSION['csrf'],'user_id'=>$_SESSION['uid']??null]);
    // Probe companion credentials without uploading any inventory. GET is still
    // bearer-only and the token grants no authority beyond scan staging.
    if ($method==='GET' && preg_match('~^/bridge/guilds/([1-9][0-9]{0,17})/status$~D',$path,$bm)) {
        $guildId=id($bm[1]);
        $bearer=$_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        if ($bearer==='' && function_exists('getallheaders')) {
            foreach (getallheaders() as $headerName=>$value) if (strcasecmp($headerName,'Authorization')===0) $bearer=$value;
        }
        if (!preg_match('/^Bearer (bl1_[a-f0-9]{64})$/D',$bearer,$match)) reply(401,['error'=>'BRIDGE_TOKEN_REQUIRED']);
        try {$auth=(new BridgeTokens($db))->authorizeScoped($guildId,$match[1]);}
        catch (DomainError $e) {reply(401,['error'=>'BRIDGE_TOKEN_INVALID']);}
        $caps=['scan.auto'=>['scan_stage','auto_reconcile_when_safe','order_sync'],'scan.stage'=>['scan_stage','order_sync'],'member.orders'=>['order_sync']];
        $roleQ=$db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'");
        $roleQ->execute([$guildId,$auth['actor']]);
        $cap=in_array($roleQ->fetchColumn(),['admin','bank_manager','officer'],true)?$caps['scan.auto']:$caps['member.orders'];
        reply(200,['ok'=>true,'scope'=>$auth['scope'],'guild_id'=>$guildId,'capabilities'=>$cap]);
    }
    // Version heartbeat from a companion: device name + installed versions only.
    if ($method==='POST' && preg_match('~^/bridge/guilds/([1-9][0-9]{0,17})/heartbeat$~D',$path,$bm)) {
        $guildId=id($bm[1]);
        $bearer=$_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        if ($bearer==='' && function_exists('getallheaders')) {
            foreach (getallheaders() as $headerName=>$value) if (strcasecmp($headerName,'Authorization')===0) $bearer=$value;
        }
        if (!preg_match('/^Bearer (bl1_[a-f0-9]{64})$/D',$bearer,$match)) reply(401,['error'=>'BRIDGE_TOKEN_REQUIRED']);
        try {$auth=(new BridgeTokens($db))->authorizeScoped($guildId,$match[1]);}
        catch (DomainError $e) {reply(401,['error'=>'BRIDGE_TOKEN_INVALID']);}
        $b=json_decode((string)file_get_contents('php://input',false,null,0,2048),true);
        $clean=static fn($v,$pattern)=>is_string($v)&&preg_match($pattern,$v)?$v:'';
        $device=$clean($b['device']??null,'/^[A-Za-z0-9 ._-]{1,60}$/D')?:'Unbekannter PC';
        $ver='/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]{1,30})?$/D';
        $db->prepare('INSERT INTO device_heartbeats (token_id,guild_id,user_id,device,companion_version,addon_version,updater_version,last_seen) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE device=VALUES(device),companion_version=VALUES(companion_version),addon_version=VALUES(addon_version),updater_version=VALUES(updater_version),last_seen=VALUES(last_seen)')
            ->execute([$auth['token_id'],$guildId,$auth['actor'],$device,$clean($b['companion']??null,$ver),$clean($b['addon']??null,$ver),$clean($b['updater']??null,$ver)]);
        reply(200,['ok'=>true]);
    }
    // Own order status for the in-game view; same bearer rules, read-only.
    if ($method==='GET' && preg_match('~^/bridge/guilds/([1-9][0-9]{0,17})/state$~D',$path,$bm)) {
        $guildId=id($bm[1]);
        $bearer=$_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        if ($bearer==='' && function_exists('getallheaders')) {
            foreach (getallheaders() as $headerName=>$value) if (strcasecmp($headerName,'Authorization')===0) $bearer=$value;
        }
        if (!preg_match('/^Bearer (bl1_[a-f0-9]{64})$/D',$bearer,$match)) reply(401,['error'=>'BRIDGE_TOKEN_REQUIRED']);
        try {$auth=(new BridgeTokens($db))->authorizeScoped($guildId,$match[1]);}
        catch (DomainError $e) {reply(401,['error'=>'BRIDGE_TOKEN_INVALID']);}
        $st=(new IngameOrders($db))->state($guildId,$auth['actor']);
        $st['feedback']=(new Feedback($db))->stateView($guildId,$auth['actor'],siteOrigin());
        reply(200,['ingame_state'=>$st]);
    }
    // Release publishing from the maintainer's machine: bearer publish token + Ed25519 signature.
    if (str_starts_with($path,'/releases/')) {
        $bearer=$_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        if ($bearer==='' && function_exists('getallheaders')) {
            foreach (getallheaders() as $headerName=>$value) if (strcasecmp($headerName,'Authorization')===0) $bearer=$value;
        }
        $root=dirname(__DIR__);$publisher=new ReleasePublisher($db,$root);
        try {$tokenId=$publisher->authorize($bearer);} catch (DomainError $e) {reply(401,['error'=>'PUBLISH_TOKEN_INVALID']);}
        if ($method==='GET' && $path==='/releases/status') {
            reply(200,['channel'=>$publisher->currentStatus(),'web_installed'=>(new WebUpdater($db,$root))->installedVersion(),
                'sodium'=>function_exists('sodium_crypto_sign_verify_detached'),'post_max_size'=>ini_get('post_max_size')]);
        }
        if ($method==='POST' && preg_match('~^/releases/files/([a-f0-9]{64})\.(zip|exe)$~D',$path,$rf)) {
            $offset=(string)($_SERVER['HTTP_X_BL_OFFSET']??'');
            if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$offset)) reply(400,['error'=>'OFFSET_REQUIRED']);
            $chunk=file_get_contents('php://input',false,null,0,8_388_609);
            if ($chunk===false) reply(400,['error'=>'CHUNK_UNREADABLE']);
            reply(200,$publisher->putChunk($rf[1],$rf[2],(int)$offset,$chunk,($_SERVER['HTTP_X_BL_FINAL']??'')==='1'));
        }
        if ($method==='POST' && $path==='/releases/publish') {
            $manifest=file_get_contents('php://input',false,null,0,16385);
            if (!is_string($manifest)) reply(400,['error'=>'MANIFEST_INVALID']);
            reply(200,$publisher->publish($tokenId,$manifest,new WebUpdater($db,$root)));
        }
        reply(404,['error'=>'NOT_FOUND']);
    }
    // Separate bearer-only bridge route; never accepts session cookies as authentication.
    // Native WoW addons cannot call this route; only the Windows companion can.
    if ($method==='POST' && preg_match('~^/bridge/guilds/([1-9][0-9]{0,17})/imports$~D',$path,$bm)) {
        $guildId=id($bm[1]);
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>4_194_304) reply(413,['error'=>'IMPORT_TOO_LARGE']);
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'text/plain')) reply(415,['error'=>'TEXT_REQUIRED']);
        // Some CGI hosts may expose the header through an alternate server variable.
        $bearer=$_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        if ($bearer==='' && function_exists('getallheaders')) {
            foreach (getallheaders() as $headerName=>$value) if (strcasecmp($headerName,'Authorization')===0) $bearer=$value;
        }
        if (!preg_match('/^Bearer (bl1_[a-f0-9]{64})$/D',$bearer,$match)) reply(401,['error'=>'BRIDGE_TOKEN_REQUIRED']);
        try {$auth=(new BridgeTokens($db))->authorizeScoped($guildId,$match[1]);$actor=$auth['actor'];}
        catch (DomainError $e) {reply(401,['error'=>'BRIDGE_TOKEN_INVALID']);}
        $file=file_get_contents('php://input',false,null,0,4_194_305);
        if ($file===false || strlen($file)>4_194_304 || $file==='') reply(413,['error'=>'INVALID_BRIDGE_PAYLOAD']);
        $g=$db->prepare('SELECT name,realm,wow_flavor FROM guilds WHERE id=?');$g->execute([$guildId]);$record=$g->fetch();
        if (!$record) reply(404,['error'=>'GUILD_NOT_FOUND']);
        // A file from another game version (e.g. a Retail guild of the same name) is never mixed in.
        $fileFlavor=GameFlavor::ofFile($file);
        if ($fileFlavor!==null && $fileFlavor!==$record['wow_flavor']) reply(409,['error'=>'GAME_VERSION_MISMATCH','guild_version'=>$record['wow_flavor'],'file_version'=>$fileFlavor]);
        $ingame=new IngameOrders($db);
        // Manager handovers first: they release reservations before a new scan lowers the stock.
        $managed=$ingame->processManage($guildId,$actor,$file,$record);
        $ingame->syncCharacters($guildId,$actor,$file,$record);
        (new Claims($db))->record($guildId,$actor,$file,$record);
        $feedback=new Feedback($db);
        $fbResults=$feedback->processIngame($guildId,$actor,$file,siteOrigin(),[]);
        $withFeedback=function(array $st) use ($feedback,$guildId,$actor,$fbResults): array {
            $st['feedback']=$feedback->stateView($guildId,$actor,siteOrigin());
            $st['feedback_results']=(object)$fbResults;
            return $st;
        };
        // Scans count for every active bank manager, officer or admin, however the
        // companion was connected. The role is checked on every upload, so a
        // demoted member's scans are ignored immediately.
        $roleQ=$db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'");
        $roleQ->execute([$guildId,$actor]);
        $scanner=in_array($roleQ->fetchColumn(),['admin','bank_manager','officer'],true);
        // Bank log, gold and prices read in game by a bank manager (kept beyond WoW's 25 entries).
        if ($scanner) { try { (new BankLedger($db))->process($guildId,$actor,$file,$record); } catch (\Throwable $e) { error_log('Banklord ledger: '.get_class($e)); } }
        if (!$scanner) {
            // Members: orders only. Scans and peer drafts in the file are ignored.
            $results=$ingame->processOutbox($guildId,$actor,$file,$record);
            reply(200,['ingame_orders'=>(object)$results,'ingame_state'=>$withFeedback($ingame->state($guildId,$actor,$results,$managed))]);
        }
        try { $candidate=ScanImport::fromSavedVariables($file,$record['name'],$record['realm']); }
        catch (\InvalidArgumentException $e) {
            // No complete scan in this file (e.g. only orders or a mail handover): nothing to stage.
            $results=$ingame->processOutbox($guildId,$actor,$file,$record);
            reply(200,['scan_skipped'=>substr($e->getMessage(),0,60),'ingame_orders'=>(object)$results,'ingame_state'=>$withFeedback($ingame->state($guildId,$actor,$results,$managed))]);
        }
        // Automatic reconciliation for all managers once an admin enabled it for the guild.
        $autoQ=$db->prepare('SELECT enabled FROM guild_scan_automation WHERE guild_id=?');
        $autoQ->execute([$guildId]);
        $auto=(int)$autoQ->fetchColumn()===1;
        $staged=(new ScanStaging($db))->stage($guildId,$actor,$candidate,$auto);
        $peers=(new PeerRelay($db))->stage($guildId,$actor,$file);
        $autoResult=['status'=>'not_enabled'];
        if ($auto && $staged['status']==='archived') {
            $autoResult=['status'=>'already_archived'];
        } elseif ($auto && $staged['status']==='reconciled') {
            $autoResult=['status'=>'already_reconciled'];
        } elseif ($auto) {
            // Never skip central authorization/row locks/conflict checks. The
            // result is independent of upload success; rejected scans stay pending.
            try {
                $reconciled=(new StockReconciliation($db))->confirm($guildId,$actor,(int)$staged['id'],true);
                $autoResult=['status'=>'reconciled','changed_stocks'=>$reconciled['changed_stocks']];
            } catch (DomainError $e) {
                $autoResult=['status'=>'review_required','reason'=>$e->getMessage()];
            }
        }
        $results=$ingame->processOutbox($guildId,$actor,$file,$record);
        reply($staged['created']?201:200,['import'=>$staged,'auto_reconcile'=>$autoResult,'new_peer_drafts'=>$peers,
            'ingame_orders'=>(object)$results,'ingame_state'=>$withFeedback($ingame->state($guildId,$actor,$results,$managed))]);
    }
    // Joining by in-game confirmation (see Claims.php). No session; the key signature is the proof.
    $origin=(getenv('APP_HTTPS')==='off'?'http':'https').'://'.preg_replace('/[^A-Za-z0-9.:\-]/','',(string)($_SERVER['HTTP_HOST']??''));
    if ($method==='GET' && $path==='/claims/challenge') reply(200,(new Claims($db))->challenge((string)($_GET['key']??'')));
    if ($method==='POST' && $path==='/claims/redeem') {
        $b=body();
        reply(200,['grants'=>(new Claims($db))->redeem((string)($b['key']??''),(string)($b['signature']??''),$origin)]);
    }
    // Central feedback inbox (official site): guild websites forward reports here.
    if ($method==='POST' && ($path==='/feedback/submit' || $path==='/feedback/status')) {
        if (!UpstreamUpdater::isPublisher() || !Feedback::enabled()) reply(404,['error'=>'NOT_FOUND']);
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>65536) reply(413,['error'=>'FEEDBACK_TOO_LARGE']);
        $b=body();
        if ($path==='/feedback/status') reply(200,['reports'=>(object)(new Feedback($db))->lookup(is_array($b['receipts']??null)?$b['receipts']:[])]);
        if (!is_array($b['report']??null)) reply(422,['error'=>'FEEDBACK_INVALID']);
        reply(201,(new Feedback($db))->accept($b['report'],(string)($b['site']??''),'ip|'.($_SERVER['REMOTE_ADDR']??'')));
    }
    if (in_array($method,['POST','PUT','PATCH','DELETE'],true)) csrf();

    // Invite-only registration: untrusted codes never grant rights by themselves.
    if ($method==='POST' && $path==='/register') {
        // Registration may be anonymously accessed but must not be brute-forceable.
        $registrationKey=hash('sha256','register|'.($_SERVER['REMOTE_ADDR']??'unknown'));
        $limit=$db->prepare('INSERT INTO login_attempts (key_hash,attempts,window_started_at) VALUES (?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE key_hash=key_hash');
        $limit->execute([$registrationKey]);
        $limit=$db->prepare('SELECT attempts,window_started_at FROM login_attempts WHERE key_hash=?');
        $limit->execute([$registrationKey]);$rl=$limit->fetch();
        if($rl && time()-strtotime($rl['window_started_at'].' UTC')<900 && (int)$rl['attempts']>=10)reply(429,['error'=>'REGISTRATION_RATE_LIMIT']);
        $s=$db->prepare('UPDATE login_attempts SET attempts=IF(TIMESTAMPDIFF(SECOND,window_started_at,UTC_TIMESTAMP()) >= 900,1,attempts+1),window_started_at=IF(TIMESTAMPDIFF(SECOND,window_started_at,UTC_TIMESTAMP()) >= 900,UTC_TIMESTAMP(),window_started_at) WHERE key_hash=?');
        $s->execute([$registrationKey]);
        $b=body();$result=(new Community($db))->redeem(
            (string)($b['invitation']??''),(string)($b['email']??''),
            (string)($b['display_name']??''),(string)($b['password']??''));
        session_regenerate_id(true);$_SESSION['uid']=$result['user_id'];$_SESSION['csrf']=bin2hex(random_bytes(32));
        reply(201,['user_id'=>$result['user_id'],'csrf'=>$_SESSION['csrf']]);
    }
    if ($method==='POST' && $path==='/login') {
        $b=body(); $email=mb_strtolower(trim((string)($b['email']??''))); $password=(string)($b['password']??'');
        if (strlen($email)>254 || strlen($password)>1024 || $email==='' || $password==='') reply(400,['error'=>'INVALID_LOGIN']);
        $rateKey=hash('sha256',strtolower($email).'|'.($_SERVER['REMOTE_ADDR']??''));
        $s=$db->prepare('INSERT INTO login_attempts (key_hash,attempts,window_started_at) VALUES (?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE key_hash=key_hash');$s->execute([$rateKey]);
        $s=$db->prepare('SELECT attempts,window_started_at FROM login_attempts WHERE key_hash=?');$s->execute([$rateKey]);$limit=$s->fetch();
        $age=time()-strtotime($limit['window_started_at'].' UTC');
        if ($age>=0 && $age<900 && (int)$limit['attempts']>=8) reply(429,['error'=>'TOO_MANY_ATTEMPTS']);
        $s=$db->prepare('SELECT id,password_hash FROM users WHERE email=?');$s->execute([$email]);$user=$s->fetch();
        if (!$user || !password_verify($password,$user['password_hash'])) {
            $s=$db->prepare('UPDATE login_attempts SET attempts=IF(TIMESTAMPDIFF(SECOND,window_started_at,UTC_TIMESTAMP()) >= 900,1,attempts+1),window_started_at=IF(TIMESTAMPDIFF(SECOND,window_started_at,UTC_TIMESTAMP()) >= 900,UTC_TIMESTAMP(),window_started_at) WHERE key_hash=?');$s->execute([$rateKey]);
            reply(401,['error'=>'LOGIN_FAILED']);
        }
        $s=$db->prepare('DELETE FROM login_attempts WHERE key_hash=?');$s->execute([$rateKey]);
        session_regenerate_id(true); unset($_SESSION['demo']); $_SESSION['uid']=(int)$user['id'];$_SESSION['csrf']=bin2hex(random_bytes(32));
        reply(200,['user_id'=>$_SESSION['uid'],'csrf'=>$_SESSION['csrf']]);
    }
    if ($method==='POST' && $path==='/logout') {$_SESSION=[];session_destroy();reply(200,['ok'=>true]);}
    if ($method==='POST' && $path==='/claims/complete') {
        $b=body();
        $uid=(new Claims($db))->complete((string)($b['welcome']??''),(string)($b['email']??''),(string)($b['password']??''),(string)($b['display_name']??''));
        session_regenerate_id(true);unset($_SESSION['demo']);$_SESSION['uid']=$uid;$_SESSION['csrf']=bin2hex(random_bytes(32));
        reply(200,['user_id'=>$uid,'csrf'=>$_SESSION['csrf']]);
    }
    $user=mustLogin();
    if (isset($_SESSION['last_seen']) && time()-(int)$_SESSION['last_seen']>28800) {
        $_SESSION=[];session_destroy();reply(401,['error'=>'SESSION_EXPIRED']);
    }
    $_SESSION['last_seen']=time();
    if ($method==='POST' && $path==='/guilds') reply(201,['guild'=>(new Guilds($db))->create($user,body())]);
    if ($method==='GET' && $path==='/guilds') {
        $s=$db->prepare("SELECT g.id,g.name,g.realm,g.region,g.wow_flavor,m.role FROM guild_memberships m JOIN guilds g ON g.id=m.guild_id WHERE m.user_id=? AND m.status='active'");$s->execute([$user]);reply(200,['guilds'=>$s->fetchAll()]);
    }
    if (!preg_match('~^/guilds/([1-9][0-9]{0,17})/(.+)$~D',$path,$m)) reply(404,['error'=>'NOT_FOUND']);
    $guild=id($m[1]);$tail=$m[2];$role=hasMember($db,$guild,$user);
    // Help & feedback: every member reports; the inbox exists only where reports arrive.
    if ($tail==='feedback' || str_starts_with($tail,'feedback/')) {
        $fb=new Feedback($db);
        $inbox=$role==='admin' && Feedback::centralUrl()===null;
        if ($method==='GET' && $tail==='feedback') reply(200,['enabled'=>Feedback::enabled(),'inbox'=>$inbox,'mine'=>$fb->mine($guild,$user,siteOrigin())]);
        if ($method==='POST' && $tail==='feedback') {
            $b=body();
            reply(201,['report'=>$fb->submit($guild,$user,$b,'web',null,siteOrigin())]);
        }
        if (!$inbox) reply(403,['error'=>'FORBIDDEN']);
        if ($method==='GET' && $tail==='feedback/inbox') reply(200,$fb->inbox((string)($_GET['status']??''),(string)($_GET['kind']??'')));
        if ($method==='PATCH' && preg_match('~^feedback/inbox/([1-9][0-9]{0,17})$~D',$tail,$fm)) {
            $b=body();$fb->answer(id($fm[1]),(string)($b['status']??''),(string)($b['reply']??''));
            reply(200,['ok'=>true]);
        }
        reply(404,['error'=>'NOT_FOUND']);
    }
    // Guild-scoped restock rules and manually executed shipment worklists.
    if ($method==='GET' && $tail==='procurement/rules') {
        $rules=(new Procurement($db))->rules($guild,$user);
        $o=$db->prepare('SELECT gs.id,i.name FROM guild_stock gs JOIN items i ON i.id=gs.item_id WHERE gs.guild_id=? ORDER BY i.name LIMIT 3000');$o->execute([$guild]);
        reply(200,['rules'=>$rules,'options'=>$o->fetchAll()]);
    }
    if ($method==='PUT' && preg_match('~^procurement/rules/([1-9][0-9]{0,17})$~D',$tail,$rr)) {
        $b=body();
        if (!is_int($b['minimum']??null)||!is_int($b['target']??null)) reply(422,['error'=>'INVALID_RESTOCK_RULE']);
        (new Procurement($db))->configure($guild,$user,id($rr[1]),$b['minimum'],$b['target']);
        reply(200,['ok'=>true]);
    }
    if ($method==='GET' && $tail==='procurement/export.csv') {
        $rows=(new Procurement($db))->rules($guild,$user);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bl-nachbeschaffung.csv"');
        echo Procurement::csv($rows);exit;
    }
    if ($method==='GET' && $tail==='fulfillment/manifest') {
        reply(200,['manifest'=>(new Procurement($db))->manifest($guild,$user)]);
    }
    // Member & account management: guild scoping applies even to admins.
    if ($method==='POST' && $tail==='invitations') {
        if ($role!=='admin')reply(403,['error'=>'FORBIDDEN']);
        $b=body();reply(201,['invitation'=>(new Community($db))->issueInvitation($guild,$user,(string)($b['role']??'member'))]);
    }
    if ($method==='GET' && $tail==='members') {
        if ($role!=='admin')reply(403,['error'=>'FORBIDDEN']);
        reply(200,['members'=>(new Community($db))->members($guild,$user)]);
    }
    if ($method==='PATCH' && preg_match('~^members/([1-9][0-9]{0,17})$~D',$tail,$mm)) {
        if ($role!=='admin')reply(403,['error'=>'FORBIDDEN']);
        $b=body();(new Community($db))->setMembership($guild,$user,id($mm[1]),(string)($b['role']??''),(string)($b['status']??''));
        reply(200,['ok'=>true]);
    }
    if ($method==='GET' && $tail==='characters') {
        reply(200,['characters'=>(new Community($db))->characters($guild,$user,$role==='admin')]);
    }
    if ($method==='POST' && $tail==='characters') {
        $b=body();$character=(new Community($db))->addCharacter($guild,$user,(string)($b['name']??''),(string)($b['realm']??''));
        reply(201,['character_id'=>$character,'verification_status'=>'unverified']);
    }
    if ($method==='POST' && preg_match('~^characters/([1-9][0-9]{0,17})/review$~D',$tail,$cm)) {
        if ($role!=='admin')reply(403,['error'=>'FORBIDDEN']);
        $b=body();(new Community($db))->verifyCharacter($guild,$user,id($cm[1]),(string)($b['status']??''));
        reply(200,['ok'=>true]);
    }
    $preferences=new Preferences($db);
    if ($method==='GET' && $tail==='favorites')reply(200,['favorites'=>$preferences->favorites($guild,$user)]);
    if (preg_match('~^favorites/([1-9][0-9]{0,17})$~D',$tail,$fm) && in_array($method,['PUT','DELETE'],true)) {
        $preferences->setFavorite($guild,$user,id($fm[1]),$method==='PUT');reply(200,['ok'=>true]);
    }
    if ($method==='GET' && $tail==='wishlist')reply(200,['wishlist'=>$preferences->wishlist($guild,$user)]);
    if (preg_match('~^wishlist/([1-9][0-9]{0,17})$~D',$tail,$wm) && in_array($method,['PUT','DELETE'],true)) {
        $quantity=null;
        if($method==='PUT'){$b=body();if(!is_int($b['quantity']??null))reply(422,['error'=>'INVALID_QUANTITY']);$quantity=$b['quantity'];}
        $preferences->setWishlist($guild,$user,id($wm[1]),$quantity);reply(200,['ok'=>true]);
    }
    if ($method==='GET' && $tail==='templates')reply(200,['templates'=>$preferences->templates($guild,$user)]);
    if ($method==='POST' && $tail==='templates') {
        $b=body();$template=$preferences->createTemplate($guild,$user,(string)($b['title']??''),$b['lines']??null);reply(201,['id'=>$template]);
    }
    if ($method==='DELETE' && preg_match('~^templates/([1-9][0-9]{0,17})$~D',$tail,$tm)) {
        $preferences->removeTemplate($guild,$user,id($tm[1]));reply(200,['ok'=>true]);
    }
    if ($method==='GET' && $tail==='ledger') {
        if (!in_array($role,['bank_manager','admin'],true))reply(403,['error'=>'FORBIDDEN']);
        $before=(string)($_GET['before']??'');
        if($before!==''&&!preg_match('/^[1-9][0-9]{0,17}$/D',$before))reply(400,['error'=>'INVALID_CURSOR']);
        $params=[$guild];$where='l.guild_id=?';
        if($before!==''){$where.=' AND l.id<?';$params[]=(int)$before;}
        $sql="SELECT l.id,l.event_type,l.physical_delta,l.reserved_delta,l.created_at,l.order_line_id,
          i.name,i.wow_item_id,u.display_name AS actor_name,gs.id AS stock_id
          FROM stock_ledger l JOIN guild_stock gs ON gs.id=l.stock_id AND gs.guild_id=l.guild_id
          JOIN items i ON i.id=gs.item_id LEFT JOIN users u ON u.id=l.actor_user_id
          WHERE $where ORDER BY l.id DESC LIMIT 101";
        $s=$db->prepare($sql);$s->execute($params);$rows=$s->fetchAll();$more=count($rows)>100;
        if($more)array_pop($rows);
        reply(200,['entries'=>$rows,'next_before'=>$more ? end($rows)['id'] : null]);
    }
    if ($method==='GET' && $tail==='activity') {
        if(!in_array($role,['officer','bank_manager','admin'],true))reply(403,['error'=>'FORBIDDEN']);
        $s=$db->prepare("SELECT a.id,a.action,a.entity_type,a.entity_id,a.created_at,a.details_json,u.display_name AS actor,ou.display_name AS owner FROM audit_events a LEFT JOIN users u ON u.id=a.actor_user_id LEFT JOIN orders o ON a.entity_type='order' AND o.id=a.entity_id AND o.guild_id=a.guild_id LEFT JOIN users ou ON ou.id=o.user_id WHERE a.guild_id=? ORDER BY a.id DESC LIMIT 150");$s->execute([$guild]);
        reply(200,['events'=>$s->fetchAll()]);
    }
    if ($method==='GET' && $tail==='dashboard') {
        if(!in_array($role,['officer','bank_manager','admin'],true))reply(403,['error'=>'FORBIDDEN']);
        $s=$db->prepare('SELECT status,COUNT(*) AS total FROM orders WHERE guild_id=? GROUP BY status');$s->execute([$guild]);$counts=$s->fetchAll();
        $s=$db->prepare("SELECT COUNT(*) AS items,COALESCE(SUM(reserved_qty),0) AS reserved,COALESCE(SUM(physical_qty),0) AS physical FROM guild_stock WHERE guild_id=?");$s->execute([$guild]);
        reply(200,['orders'=>$counts,'stock'=>$s->fetch()]);
    }
    if ($method==='GET' && preg_match('~^stock/([1-9][0-9]{0,17})/history$~D',$tail,$hm)) {
        $stock=id($hm[1]);$s=$db->prepare('SELECT l.id,l.event_type,l.physical_delta,l.reserved_delta,l.created_at FROM stock_ledger l WHERE l.guild_id=? AND l.stock_id=? ORDER BY l.id DESC LIMIT 50');$s->execute([$guild,$stock]);
        reply(200,['events'=>$s->fetchAll()]);
    }
    // Authenticated, CSRF-protected manual SavedVariables staging.
    // An imported file is an untrusted observation, NEVER an authoritative stock update.
    if ($method==='POST' && $tail==='imports') {
        if (!in_array($role,['admin','bank_manager'],true)) reply(403,['error'=>'FORBIDDEN']);
        if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 4_500_000) {
            reply(413,['error'=>'IMPORT_TOO_LARGE']);
        }
        if (!isset($_FILES['savedvars']) || !is_array($_FILES['savedvars'])) reply(422,['error'=>'IMPORT_FILE_REQUIRED']);
        $f=$_FILES['savedvars'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
            !is_string($f['name'] ?? null) || basename($f['name']) !== 'Banklord.lua' ||
            !is_int($f['size'] ?? null) || $f['size'] <= 0 || $f['size'] > 4_194_304 ||
            !is_string($f['tmp_name'] ?? null) || !is_uploaded_file($f['tmp_name'])) {
            reply(422,['error'=>'IMPORT_FILE_INVALID']);
        }
        $raw=file_get_contents($f['tmp_name']);
        if ($raw===false) reply(422,['error'=>'IMPORT_FILE_UNREADABLE']);
        $s=$db->prepare('SELECT name,realm,wow_flavor FROM guilds WHERE id=?');$s->execute([$guild]);
        $expected=$s->fetch();
        if (!$expected) reply(404,['error'=>'GUILD_NOT_FOUND']);
        $fileFlavor=GameFlavor::ofFile($raw);
        if ($fileFlavor!==null && $fileFlavor!==$expected['wow_flavor']) reply(409,['error'=>'GAME_VERSION_MISMATCH']);
        $candidate=ScanImport::fromSavedVariables($raw,$expected['name'],$expected['realm']);
        $staged=(new ScanStaging($db))->stage($guild,$user,$candidate);
        $peers=(new PeerRelay($db))->stage($guild,$user,$raw);
        reply($staged['created'] ? 201 : 200, ['import'=>$staged,'new_peer_drafts'=>$peers]);
    }
    if ($method==='GET' && $tail==='peer-drafts') {
        if (!in_array($role,['admin','bank_manager'],true)) reply(403,['error'=>'FORBIDDEN']);
        $s=$db->prepare("SELECT id,sender,message_id,transfer_code,received_at,imported_at,status,sender_claim_verified FROM peer_order_drafts WHERE guild_id=? ORDER BY id DESC LIMIT 50");
        $s->execute([$guild]);reply(200,['drafts'=>$s->fetchAll(),'server_confirmed_orders'=>false]);
    }
    if ($method==='GET' && $tail==='imports') {
        if (!in_array($role,['admin','bank_manager'],true)) reply(403,['error'=>'FORBIDDEN']);
        $s=$db->prepare("SELECT id,source,source_key,observed_at,imported_at,quality,status FROM stock_snapshots WHERE guild_id=? ORDER BY id DESC LIMIT 20");
        $s->execute([$guild]);reply(200,['imports'=>$s->fetchAll()]);
    }
    if ($method==='GET' && preg_match('~^imports/([1-9][0-9]{0,17})/review$~D',$tail,$imp)) {
        if (!in_array($role,['admin','bank_manager'],true)) reply(403,['error'=>'FORBIDDEN']);
        $snapshot=id($imp[1]);
        $s=$db->prepare('SELECT id,source,observed_at,quality,status FROM stock_snapshots WHERE guild_id=? AND id=?');
        $s->execute([$guild,$snapshot]);$head=$s->fetch();
        if (!$head) reply(404,['error'=>'IMPORT_NOT_FOUND']);
        $page=(string)($_GET['page']??'1');
        if (!preg_match('/^[1-9][0-9]{0,3}$/D',$page)) reply(400,['error'=>'INVALID_PAGE']);
        $offset=(((int)$page)-1)*100;
        $s=$db->prepare("SELECT l.wow_item_id,l.variant_key,MAX(l.item_name) AS item_name,SUM(l.quantity) AS scanned_qty,COUNT(*) AS stacks,
              s.physical_qty AS known_qty,s.reserved_qty,s.confidence
              FROM snapshot_source_lines l
              LEFT JOIN items i ON i.wow_flavor=(SELECT wow_flavor FROM guilds WHERE id=?) AND i.wow_item_id=l.wow_item_id AND i.variant_key=l.variant_key
              LEFT JOIN guild_stock s ON s.guild_id=? AND s.item_id=i.id
              WHERE l.snapshot_id=?
              GROUP BY l.wow_item_id,l.variant_key,s.physical_qty,s.reserved_qty,s.confidence
              ORDER BY item_name,l.wow_item_id,l.variant_key LIMIT 101 OFFSET ?");
        $s->bindValue(1,$guild,PDO::PARAM_INT);$s->bindValue(2,$guild,PDO::PARAM_INT);$s->bindValue(3,$snapshot,PDO::PARAM_INT);
        $s->bindValue(4,$offset,PDO::PARAM_INT);$s->execute();
        $rows=$s->fetchAll();$more=count($rows)>100;
        if ($more) array_pop($rows);
        $preflight=(new StockReconciliation($db))->inspect($guild,$snapshot);
        // This is an ICON COVERAGE preview, not a second stock snapshot.
        $locale=(string)($_GET['locale']??'deDE');
        if (!in_array($locale,\Banklord\ItemMetadata::LOCALES,true)) $locale='deDE';
        $ids=array_values(array_unique(array_map(static fn(array $r):int=>(int)$r['wow_item_id'],$rows)));
        $metaByVariant=[];
        if ($ids) {
            $holders=implode(',',array_fill(0,count($ids),'?'));
            $q=$db->prepare("SELECT wow_item_id,variant_hash,icon_name,icon_file_id,completeness FROM item_client_metadata
                WHERE guild_id=? AND client_flavor=(SELECT wow_flavor FROM guilds WHERE id=guild_id) AND locale=? AND wow_item_id IN ($holders)
                ORDER BY captured_at DESC,id DESC LIMIT 5000");
            $q->execute(array_merge([$guild,$locale],$ids));
            while ($m=$q->fetch(PDO::FETCH_ASSOC)) {
                $key=((int)$m['wow_item_id']).':'.$m['variant_hash'];
                if (!array_key_exists($key,$metaByVariant)) $metaByVariant[$key]=$m;
            }
        }
        $coverage=['icon_available'=>0,'file_id_only'=>0,'missing'=>0];
        foreach($rows as &$row) {
            $m=$metaByVariant[((int)$row['wow_item_id']).':'.hash('sha256',(string)$row['variant_key'])]??null;
            $icon=$m['icon_name']??null;
            if (is_string($icon) && preg_match('/^[A-Za-z][A-Za-z0-9_]{1,79}$/D',$icon)) {
                $row['preview_icon']=\Banklord\ItemMetadata::iconUrl($icon, $m['icon_file_id']);
                $row['icon_status']='icon_available';$coverage['icon_available']++;
            } elseif ($m && $m['icon_file_id']!==null) {
                $row['preview_icon']=\Banklord\ItemMetadata::iconUrl(null, $m['icon_file_id']);$row['icon_status']='file_id_only';$coverage['file_id_only']++;
            } else {
                $row['preview_icon']=null;$row['icon_status']='missing';$coverage['missing']++;
            }
        }
        unset($row);
        reply(200,['import'=>$head,'page'=>(int)$page,'has_next'=>$more,
            'items'=>$rows,'icon_coverage'=>$coverage,'icon_locale'=>$locale,
            'preflight'=>$preflight,'stock_changed'=>false]);
    }
    // Admin-only token management. The raw token is returned once and never recoverable.
    // The configured policy is read-only for managers. Only administrators may
    // opt in to automatic reconciliation with fresh password confirmation.
    if ($method==='GET' && $tail==='scan-automation') {
        if (!in_array($role,['admin','bank_manager'],true)) reply(403,['error'=>'FORBIDDEN']);
        $q=$db->prepare('SELECT enabled,enabled_at FROM guild_scan_automation WHERE guild_id=?');
        $q->execute([$guild]);$cfg=$q->fetch(PDO::FETCH_ASSOC);
        reply(200,['enabled'=>!empty($cfg['enabled']),'enabled_at'=>$cfg['enabled_at']??null]);
    }
    if ($method==='POST' && $tail==='scan-automation') {
        if ($role!=='admin') reply(403,['error'=>'FORBIDDEN']);
        $b=body();
        if (($b['confirmation']??null)!=='AUTOMATISCHER_BANKABGLEICH' ||
            !is_bool($b['enabled']??null) || !is_string($b['password']??null) ||
            strlen($b['password'])>1024 || !is_string($b['label']??null))
            reply(422,['error'=>'EXPLICIT_AUTO_CONFIRMATION_REQUIRED']);
        // Bound expensive password rechecks and online guessing, independently
        // of CSRF/session renewal. Attempts persist on failure.
        $limitKey=hash('sha256','auto_scan_reauth|'.$guild.'|'.$user.'|'.($_SERVER['REMOTE_ADDR']??'unknown'));
        $lim=$db->prepare('INSERT INTO login_attempts (key_hash,attempts,window_started_at) VALUES (?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE key_hash=key_hash');
        $lim->execute([$limitKey]);
        $lim=$db->prepare('SELECT attempts,window_started_at FROM login_attempts WHERE key_hash=?');
        $lim->execute([$limitKey]);$count=$lim->fetch(PDO::FETCH_ASSOC);
        if ($count && time()-strtotime($count['window_started_at'].' UTC')<900 && (int)$count['attempts']>=8)
            reply(429,['error'=>'AUTO_SCAN_REAUTH_RATE_LIMIT']);
        $lim=$db->prepare('UPDATE login_attempts SET attempts=IF(TIMESTAMPDIFF(SECOND,window_started_at,UTC_TIMESTAMP()) >= 900,1,attempts+1),window_started_at=IF(TIMESTAMPDIFF(SECOND,window_started_at,UTC_TIMESTAMP()) >= 900,UTC_TIMESTAMP(),window_started_at) WHERE key_hash=?');
        $lim->execute([$limitKey]);
        $result=(new BridgeTokens($db))->configureAutomation(
            $guild,$user,$b['password'],$b['enabled'],$b['label']);
        reply(200,['automation'=>$result]);
    }
    // Public demo switch (site administrators).
    if ($tail==='demo-setting') {
        if ($role!=='admin') reply(403,['error'=>'FORBIDDEN']);
        $demo=new Demo($db,dirname(__DIR__));
        if ($method==='POST') {$b=body();$demo->setEnabled(($b['enabled']??false)===true);}
        reply(200,['enabled'=>$demo->enabled(),'guild'=>$demo->guildId()]);
    }
    // Updates from the official release channel (self-hosted websites).
    if ($tail==='upstream-update') {
        if ($role!=='admin') reply(403,['error'=>'FORBIDDEN']);
        $up=new UpstreamUpdater($db,dirname(__DIR__));
        if ($method==='GET') reply(200,$up->status());
        if ($method==='POST') reply(200,$up->run()+$up->status());
    }
    // Release publish tokens: site administrators only (admin role in this guild).
    if ($tail==='release-tokens' || preg_match('~^release-tokens/([1-9][0-9]{0,17})$~D',$tail,$rt)) {
        if ($role!=='admin') reply(403,['error'=>'FORBIDDEN']);
        if ($method==='POST' && $tail==='release-tokens') {
            $b=body();reply(201,['credential'=>(new ReleasePublisher($db,dirname(__DIR__)))->issueToken($user,(string)($b['label']??''))]);
        }
        if ($method==='GET' && $tail==='release-tokens') {
            $s=$db->query('SELECT id,label,created_at,last_used_at,revoked_at FROM release_publish_tokens ORDER BY id DESC LIMIT 20');
            $p=$db->query('SELECT web_version,addon_version,companion_version,updater_version,web_applied,published_at FROM release_publications ORDER BY id DESC LIMIT 10');
            $d=$db->prepare('SELECT d.device,u.display_name,d.companion_version,d.addon_version,d.updater_version,d.last_seen FROM device_heartbeats d JOIN users u ON u.id=d.user_id WHERE d.guild_id=? ORDER BY d.last_seen DESC LIMIT 100');
            $d->execute([$guild]);
            $current=['published'=>false];
            try {$current=(new ReleasePublisher($db,dirname(__DIR__)))->currentStatus();} catch (DomainError $e) {}
            reply(200,['tokens'=>$s->fetchAll(),'publications'=>$p->fetchAll(),'devices'=>$d->fetchAll(),'current'=>$current]);
        }
        if ($method==='DELETE' && isset($rt[1])) {
            $s=$db->prepare('UPDATE release_publish_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND revoked_at IS NULL');$s->execute([(int)$rt[1]]);
            reply(200,['revoked'=>$s->rowCount()===1]);
        }
    }
    if ($method==='POST' && $tail==='bridge-tokens') {
        $b=body();$tokens=new BridgeTokens($db);
        $issued=($b['scope']??'')==='member.orders'
            ? $tokens->issueMemberOrders($guild,$user,(string)($b['label']??''))
            : $tokens->issue($guild,$user,(string)($b['label']??''));
        reply(201,['credential'=>$issued]);
    }
    if ($method==='GET' && $tail==='bridge-tokens') {
        $manager=in_array($role,['admin','bank_manager'],true);
        // Managers see all tokens of the guild, members only their own personal ones.
        $s=$db->prepare('SELECT id,label,scope,created_at,expires_at,last_used_at,revoked_at FROM bridge_tokens WHERE guild_id=?'.($manager?'':' AND created_by=?').' ORDER BY id DESC LIMIT 30');
        $s->execute($manager?[$guild]:[$guild,$user]);reply(200,['tokens'=>$s->fetchAll()]);
    }
    if ($method==='DELETE' && preg_match('~^bridge-tokens/([1-9][0-9]{0,17})$~D',$tail,$tk)) {
        $revoked=(new BridgeTokens($db))->revoke($guild,$user,id($tk[1]));reply(200,['revoked'=>$revoked]);
    }
    if ($method==='POST' && preg_match('~^imports/([1-9][0-9]{0,17})/confirm$~D',$tail,$imp)) {
        if (!in_array($role,['admin','bank_manager'],true)) reply(403,['error'=>'FORBIDDEN']);
        $b=body();
        if (($b['confirmation']??null)!=='BANKSTAND_MANUELL_GEPRUEFT' || !is_string($b['password']??null)) reply(422,['error'=>'EXPLICIT_CONFIRMATION_REQUIRED']);
        $s=$db->prepare('SELECT password_hash FROM users WHERE id=?');$s->execute([$user]);$hash=$s->fetchColumn();
        if (!$hash || !password_verify($b['password'],$hash)) reply(403,['error'=>'REAUTH_FAILED']);
        $result=(new StockReconciliation($db))->confirm($guild,$user,id($imp[1]));
        reply(200,['reconciliation'=>$result]);
    }
    if ($method==='GET' && $tail==='notifications') {
        $s=$db->prepare('SELECT id,kind,order_id,created_at,read_at FROM notifications WHERE guild_id=? AND user_id=? ORDER BY id DESC LIMIT 50');
        $s->execute([$guild,$user]);reply(200,['notifications'=>$s->fetchAll()]);
    }
    if ($method==='POST' && preg_match('~^notifications/([1-9][0-9]{0,17})/read$~D',$tail,$nr)) {
        $s=$db->prepare('UPDATE notifications SET read_at=UTC_TIMESTAMP() WHERE guild_id=? AND user_id=? AND id=?');
        $s->execute([$guild,$user,id($nr[1])]);reply(200,['ok'=>true]);
    }
    // Purely visual public item data; never changes guild stock or orders.
    // All caller access is already gated by membership above this branch.
    if ($method==='GET' && preg_match('~^items/([1-9][0-9]{0,17})/appearance$~D',$tail,$im)) {
        $stock=id($im[1]);
        $s=$db->prepare('SELECT i.wow_item_id,i.variant_key FROM guild_stock s JOIN items i ON i.id=s.item_id WHERE s.guild_id=? AND s.id=? LIMIT 1');
        $s->execute([$guild,$stock]);$itemRecord=$s->fetch(PDO::FETCH_ASSOC);
        if ($itemRecord===false) reply(404,['error'=>'STOCK_NOT_FOUND']);
        // Cache outside public/. No user-controlled destination or URL.
        $locale=(string)($_GET['locale']??'deDE');
        if (!in_array($locale,\Banklord\ItemMetadata::LOCALES,true)) reply(400,['error'=>'UNSUPPORTED_LOCALE']);
        $appearance=\Banklord\ItemMetadata::resolve($db,$guild,(int)$itemRecord['wow_item_id'],(string)$itemRecord['variant_key'],$locale);
        if ($appearance===null) {
            // Legacy scans: external provider is fallback only, not an item-data source of truth.
            // No invented translations: a base tooltip is never represented as verified equipment.
            $fallback=in_array($locale,['deDE','enUS'],true)
                ? \Banklord\ItemAppearance::resolve((int)$itemRecord['wow_item_id'], (string)$itemRecord['variant_key'], dirname(__DIR__,2).'/private/item-visual-cache', $locale, \Banklord\GameFlavor::of($db,$guild))
                : ['icon'=>null,'quality'=>null];
            // A foreign provider is allowed to help identify an icon, not to invent
            // localized, variant-correct equipment descriptions.
            $appearance=['available'=>false,'icon'=>$fallback['icon']??null,'quality'=>$fallback['quality']??null,
                'tooltip_html'=>null,'tooltip_lines'=>[], 'provider'=>'external-icon-fallback',
                'tooltip_locale'=>$locale,'variant_status'=>'unverified',
                'variant_notes'=>'Noch keine lokalisierten Tooltip-Zeilen für diese Itemvariante importiert.'];
        }
        // Even a current client snapshot can contain only a numeric FileDataID.
        // Such an ID is NOT a Wowhead image filename. Let the existing safe,
        // bounded and cached public item resolver fill ONLY the missing icon;
        // never replace verified locale/variant tooltip or stock data.
        if ($appearance!==null && empty($appearance['icon']) && in_array($locale,['deDE','enUS'],true)) {
            $fallback=\Banklord\ItemAppearance::resolve(
                (int)$itemRecord['wow_item_id'],(string)$itemRecord['variant_key'],
                dirname(__DIR__,2).'/private/item-visual-cache',$locale,\Banklord\GameFlavor::of($db,$guild));
            if (!empty($fallback['icon'])) {
                $appearance['icon']=$fallback['icon'];
                $appearance['icon_fallback']='public-item-database';
            }
        }
        reply(200,['appearance'=>$appearance]);
    }
    if ($method==='GET' && preg_match('~^items/([1-9][0-9]{0,17})$~D',$tail,$im)) {
        $stock=id($im[1]);
        $s=$db->prepare("SELECT s.id AS stock_id,i.wow_item_id,i.variant_key,i.name,s.physical_qty,s.reserved_qty,CASE WHEN s.confidence='verified' AND s.observed_at>=UTC_TIMESTAMP()-INTERVAL 30 MINUTE THEN GREATEST(s.physical_qty-s.reserved_qty-s.viewonly_qty,0) ELSE NULL END AS available_qty,s.viewonly_qty,s.unit_price,s.price_source,s.confidence,s.observed_at FROM guild_stock s JOIN items i ON i.id=s.item_id WHERE s.guild_id=? AND s.id=?");
        $s->execute([$guild,$stock]);$row=$s->fetch();if(!$row)reply(404,['error'=>'STOCK_NOT_FOUND']);
        reply(200,['item'=>$row]);
    }
    if ($method==='GET' && $tail==='items') {
        $query=trim((string)($_GET['q']??''));if(mb_strlen($query)>100) reply(400,['error'=>'SEARCH_TOO_LONG']);
        $page=(string)($_GET['page']??'1');if(!preg_match('/^[1-9][0-9]{0,2}$/D',$page)|| (int)$page>100)reply(400,['error'=>'INVALID_PAGE']);
        $offset=((int)$page-1)*100;
        $q=str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$query);
        // Only suppress zero-balance rows in the browser catalog, not in the stock ledger.
        // The control is informational and never affects authorization or order checks.
        $showEmpty=(string)($_GET['show_empty']??'0');
        if (!in_array($showEmpty,['0','1'],true)) reply(400,['error'=>'INVALID_STOCK_FILTER']);
        $locale=(string)($_GET['locale']??'deDE');
        if (!in_array($locale,\Banklord\ItemMetadata::LOCALES,true)) reply(400,['error'=>'UNSUPPORTED_LOCALE']);
        $s=$db->prepare("SELECT s.id AS stock_id,i.wow_item_id,i.variant_key,i.name,s.physical_qty,s.reserved_qty, CASE WHEN s.confidence='verified' AND s.observed_at >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE THEN GREATEST(s.physical_qty-s.reserved_qty-s.viewonly_qty,0) ELSE NULL END AS available_qty,s.viewonly_qty,s.unit_price,s.price_source,s.confidence,s.observed_at FROM guild_stock s JOIN items i ON i.id=s.item_id WHERE s.guild_id=? AND (i.name LIKE ? OR CAST(i.wow_item_id AS CHAR) = ?) AND (?='1' OR s.physical_qty>0 OR s.reserved_qty>0) ORDER BY i.name,s.id LIMIT 101 OFFSET ?");
        $s->bindValue(1,$guild,PDO::PARAM_INT);$s->bindValue(2,'%'.$q.'%');$s->bindValue(3,$query);$s->bindValue(4,$showEmpty,PDO::PARAM_STR);$s->bindValue(5,$offset,PDO::PARAM_INT);
        $s->execute();$rows=$s->fetchAll();$hasNext=count($rows)>100;if($hasNext)array_pop($rows);
        catalogIcons($db,$guild,$rows,$locale);
        reply(200,['items'=>$rows,'page'=>(int)$page,'has_next'=>$hasNext]);
    }
    if ($method==='POST' && $tail==='orders') {
        $b=body(); $key=$_SERVER['HTTP_IDEMPOTENCY_KEY']??'';
        if (!is_array($b['lines']??null) || !is_string($b['note']??'')) reply(422,['error'=>'INVALID_ORDER_PAYLOAD']);
        $c=$db->prepare("SELECT CONCAT(name,'-',realm) FROM characters WHERE id=? AND guild_id=? AND user_id=? AND verification_status<>'revoked'");
        $c->execute([is_int($b['character_id']??null)?$b['character_id']:0,$guild,$user]);$recipient=$c->fetchColumn();
        if ($recipient===false) reply(422,['error'=>'CHARACTER_REQUIRED']);
        $order=(new Orders($db))->create($guild,$user,$key,$b['lines'],$b['note']??'',($b['urgent']??false)===true);
        $db->prepare('UPDATE orders SET recipient=? WHERE id=? AND recipient IS NULL')->execute([$recipient,$order]);
        $s=$db->prepare('SELECT status FROM orders WHERE guild_id=? AND id=?');$s->execute([$guild,$order]);
        reply(200,['id'=>$order,'status'=>$s->fetchColumn()]);
    }
    if ($tail==='order-rules') {
        $rules=new OrderRules($db);
        if ($method==='PUT') reply(200,['rules'=>$rules->save($guild,$user,body())]);
        if ($method!=='GET') reply(405,['error'=>'METHOD_NOT_ALLOWED']);
        $r=$rules->get($guild);$limit=OrderRules::limitFor($r,$role);
        reply(200,['rules'=>$r,'my_limit'=>$limit,'my_used'=>$limit>0?$rules->weeklyUsed($guild,$user):0]);
    }
    // Bank ledger (Kassenbuch): bank managers, officers and admins.
    if ($method==='GET' && in_array($tail,['bank-log','bank-log/export.csv','bank-log/summary'],true)) {
        $ledger=new BankLedger($db);
        if ($tail==='bank-log/summary') {
            $sum=$ledger->summary($guild,(int)($_GET['days']??30));
            // Members see the bank's gold and value, not who paid in or took out.
            if (!in_array($role,['officer','bank_manager','admin'],true)) $sum['players']=[];
            reply(200,$sum);
        }
        if (!in_array($role,['officer','bank_manager','admin'],true)) reply(403,['error'=>'FORBIDDEN']);
        $rows=$ledger->entries($guild,(string)($_GET['kind']??''),mb_substr((string)($_GET['player']??''),0,80),(int)($_GET['days']??90),$tail==='bank-log'?300:2000);
        if ($tail==='bank-log') reply(200,['entries'=>$rows]);
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="bl-kassenbuch.csv"');header('Cache-Control: no-store');
        echo BankLedger::csv($rows);exit;
    }
    if ($tail==='discord' || $tail==='discord/test') {
        if ($role!=='admin') reply(403,['error'=>'FORBIDDEN']);
        $discord=new \Banklord\Discord($db);
        if ($tail==='discord/test') { if ($method!=='POST') reply(405,['error'=>'METHOD_NOT_ALLOWED']); $discord->test($guild,$user); reply(200,['queued'=>true]); }
        if ($method==='PUT') reply(200,$discord->save($guild,$user,body()));
        if ($method!=='GET') reply(405,['error'=>'METHOD_NOT_ALLOWED']);
        reply(200,$discord->view($guild));
    }
    if ($tail==='game-version') {
        if ($method==='PUT') {$b=body();GameFlavor::change($db,$guild,$user,$b['wow_flavor']??null);}
        elseif ($method!=='GET') reply(405,['error'=>'METHOD_NOT_ALLOWED']);
        $c=$db->prepare('SELECT COUNT(*) FROM guild_stock WHERE guild_id=?');$c->execute([$guild]);
        reply(200,['wow_flavor'=>GameFlavor::of($db,$guild),'locked'=>(int)$c->fetchColumn()>0]);
    }
    if ($method==='POST' && preg_match('~^orders/([1-9][0-9]{0,17})/approve$~D',$tail,$o)) {
        $state=(new Orders($db))->approve($guild,$user,id($o[1]));reply(200,['status'=>$state]);
    }
    if ($method==='GET' && $tail==='orders') {
        (new OrderRules($db))->expire($guild);
        if (in_array($role,['officer','bank_manager','admin'],true)) {
            $s=$db->prepare('SELECT o.id,o.user_id,u.display_name,o.status,o.needs_approval,o.priority,o.created_at,o.note,(SELECT GROUP_CONCAT(CONCAT(ol.requested_qty,\'× \',i.name) ORDER BY ol.id SEPARATOR \' · \') FROM order_lines ol JOIN guild_stock gs ON gs.id=ol.stock_id JOIN items i ON i.id=gs.item_id WHERE ol.order_id=o.id) AS summary FROM orders o JOIN users u ON u.id=o.user_id WHERE o.guild_id=? ORDER BY o.id DESC LIMIT 200');$s->execute([$guild]);
        } else {
            $s=$db->prepare('SELECT o.id,o.user_id,u.display_name,o.status,o.needs_approval,o.priority,o.created_at,o.note,(SELECT GROUP_CONCAT(CONCAT(ol.requested_qty,\'× \',i.name) ORDER BY ol.id SEPARATOR \' · \') FROM order_lines ol JOIN guild_stock gs ON gs.id=ol.stock_id JOIN items i ON i.id=gs.item_id WHERE ol.order_id=o.id) AS summary FROM orders o JOIN users u ON u.id=o.user_id WHERE o.guild_id=? AND o.user_id=? ORDER BY o.id DESC LIMIT 100');$s->execute([$guild,$user]);
        }
        reply(200,['orders'=>$s->fetchAll()]);
    }
    if ($method==='GET' && preg_match('~^orders/([1-9][0-9]{0,17})$~D',$tail,$o)) {
        $order=id($o[1]);
        $s=$db->prepare('SELECT id,user_id,status,needs_approval,priority,note,created_at FROM orders WHERE guild_id=? AND id=?');$s->execute([$guild,$order]);$head=$s->fetch();
        if (!$head || ($head['user_id']!=$user && !in_array($role,['officer','bank_manager','admin'],true))) reply(404,['error'=>'NOT_FOUND']);
        $s=$db->prepare('SELECT l.id,l.stock_id,l.requested_qty,l.approved_qty,l.fulfilled_qty,l.decision,i.name,i.wow_item_id FROM order_lines l JOIN guild_stock gs ON gs.id=l.stock_id AND gs.guild_id=? JOIN items i ON i.id=gs.item_id WHERE l.order_id=? ORDER BY l.id');$s->execute([$guild,$order]);
        reply(200,['order'=>$head,'lines'=>$s->fetchAll()]);
    }
    if ($method==='POST' && preg_match('~^orders/([1-9][0-9]{0,17})/cancel$~D',$tail,$o)) {
        $state=(new Orders($db))->cancel($guild,$user,id($o[1]));reply(200,['status'=>$state]);
    }
    if ($method==='POST' && preg_match('~^orders/([1-9][0-9]{0,17})/decide$~D',$tail,$o)) {
        $b=body();$input=$b['decisions']??null;if(!is_array($input)) reply(400,['error'=>'INVALID_DECISIONS']);
        $decisions=[];foreach($input as $k=>$v) {if(!preg_match('/^[1-9][0-9]*$/D',(string)$k)) reply(400,['error'=>'INVALID_LINE_ID']);$decisions[(int)$k]=$v;}
        $state=(new Orders($db))->decide($guild,$user,id($o[1]),$decisions);reply(200,['status'=>$state]);
    }
    if ($method==='POST' && preg_match('~^orders/([1-9][0-9]{0,17})/fulfill$~D',$tail,$o)) {
        $b=body();if(!isset($b['line_id']) || !is_int($b['line_id']) || !is_int($b['quantity']??null)) reply(422,['error'=>'INVALID_FULFILL_PAYLOAD']);
        $key=$_SERVER['HTTP_IDEMPOTENCY_KEY']??'';
        $state=(new Orders($db))->fulfill($guild,$user,id($o[1]),$b['line_id'],$b['quantity']??null,$key);
        reply(200,['status'=>$state]);
    }
    reply(404,['error'=>'NOT_FOUND']);
} catch (\InvalidArgumentException $e) {
    // Do not leak raw uploaded file contents into an error.
    $reason=preg_replace('/ at byte [0-9]+$/','',$e->getMessage());
    reply($reason==='SV_FILE_TOO_LARGE'?413:422,['error'=>$reason]);
} catch (DomainError $e) {
    $bad=['INVALID_GAME_VERSION','INVALID_GUILD','INVALID_DISCORD','DEMO_DISABLED','UPDATE_SOURCE_INVALID','MANIFEST_INVALID','MANIFEST_SIGNATURE_INVALID','RELEASE_FILE_NAME','RELEASE_FILE_HASH','RELEASE_FILE_MISSING','RELEASE_FILE_TOO_LARGE','RELEASE_CHUNK_TOO_LARGE','WEB_PAYLOAD_INVALID','WEB_PAYLOAD_INCOMPLETE','SODIUM_MISSING','SCHEMA_MIGRATIONS_MISSING','WEB_TARGET_NOT_WRITABLE','INVALID_QUANTITY','BAD_REQUEST_KEY','INVALID_LINES','NOTE_TOO_LONG','BAD_LINE','FOREIGN_OR_UNKNOWN_STOCK','BAD_APPROVAL','MISSING_DECISION','DECISION_MISMATCH','INVALID_FULFILLMENT','OVERFULFILL','BAD_OPERATION_KEY','LINE_NOT_APPROVED','EXPLICIT_CONFIRMATION_REQUIRED','INVALID_TOKEN_LABEL','INVALID_ORDER_RULES','BAD_PARTIAL_LINES'];
    $conflict=['RELEASE_NOT_NEWER','UPDATE_ALREADY_RUNNING','INSUFFICIENT_OR_UNVERIFIED_STOCK','STOCK_CONFLICT','ORDER_ALREADY_DECIDED','ORDER_NOT_FULFILLABLE','IDEMPOTENCY_KEY_REUSED','IMPORT_QUOTA_REACHED','BRIDGE_TOKEN_LIMIT','SNAPSHOT_NOT_PENDING','SNAPSHOT_TOO_OLD','SNAPSHOT_NOT_LATEST','MOVEMENTS_AFTER_SCAN','LEGACY_SCAN_REFRESH_REQUIRED','RESERVATION_EXCEEDS_SCAN','SNAPSHOT_LIMIT','SNAPSHOT_QUANTITY','ORDER_NOT_CANCELLABLE','VIEW_ONLY_STOCK','APPROVAL_REQUIRED','GAME_VERSION_LOCKED','GAME_VERSION_MISMATCH','GUILD_EXISTS','GUILD_LIMIT'];
    if (in_array($e->getMessage(),['FEEDBACK_UNREACHABLE','FEEDBACK_DISABLED'],true)) reply(503,['error'=>$e->getMessage()]);
    if ($e->getMessage()==='FEEDBACK_RATE_LIMIT') reply(429,['error'=>$e->getMessage()]);
    if (str_starts_with($e->getMessage(),'FEEDBACK_') || $e->getMessage()==='INVALID_EMAIL') reply(422,['error'=>$e->getMessage()]);
    reply($e->getMessage()==='FORBIDDEN'?403:(in_array($e->getMessage(),$conflict,true)?409:(in_array($e->getMessage(),$bad,true)?422:404)),['error'=>$e->getMessage()]);
} catch (\Throwable $e) {
    error_log('Banklord error: '.get_class($e));
    reply(500,['error'=>'INTERNAL_ERROR']);
}
