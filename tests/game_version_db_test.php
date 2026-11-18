<?php
// Game versions: one guild per version, items and scans never mixed across versions.
declare(strict_types=1);
foreach (['Config','Domain','Database','Orders','OrderRules','StockReconciliation','ScanStaging','ItemMetadata'] as $c) require_once __DIR__."/../backend/src/$c.php";
use Banklord\{Database,GameFlavor,StockReconciliation,ScanStaging,DomainError};
$db=Database::connect();$fail=0;
$ok=function(bool $c,string $m) use(&$fail){ if(!$c){$fail++; echo "FAIL: $m\n";} };
$err=function(callable $f){ try{$f();return 'OK';}catch(DomainError $e){return $e->getMessage();} };
$db->exec("INSERT INTO guilds (name,realm,wow_flavor) VALUES ('Twins','Everlook','mop_classic')");$g=(int)$db->lastInsertId();
$n=0;$user=function(string $role) use($db,$g,&$n){$n++;
  $db->prepare("INSERT INTO users (email,password_hash,display_name) VALUES (?,?,?)")->execute(["gv$g-$n@x.invalid",'x',"G$n"]);$u=(int)$db->lastInsertId();
  $db->prepare("INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,?)")->execute([$g,$u,$role]);return $u;};
$admin=$user('admin');$member=$user('member');

$ok($err(fn()=>GameFlavor::change($db,$g,$member,'retail'))==='FORBIDDEN','only admins switch the game version');
$ok($err(fn()=>GameFlavor::change($db,$g,$admin,'wotlk'))==='INVALID_GAME_VERSION','unknown versions rejected');
$ok(GameFlavor::change($db,$g,$admin,'retail')==='retail' && GameFlavor::of($db,$g)==='retail','admin switches an empty guild');
$ok(GameFlavor::ofFile("BanklordDB = {\n[\"gameFlavor\"] = \"retail\",\n}")==='retail' && GameFlavor::ofFile('BanklordDB = {}')===null,'file version read');
$ok(GameFlavor::wowheadItem('retail',19019)==='https://www.wowhead.com/item=19019' && GameFlavor::wowheadItem('classic_era',19019)==='https://www.wowhead.com/classic/item=19019','wowhead links per version');

// A scan from another version is refused before anything is stored.
$import=['guild'=>'Twins','realm'=>'Everlook','flavor'=>'mop_classic','source'=>'banklord-savedvariables-v2','source_key'=>'x'];
$ok($err(fn()=>(new ScanStaging($db))->stage($g,$admin,$import))==='GAME_VERSION_MISMATCH','foreign game version scan refused');

// Stock of a Retail guild creates Retail items, then the version is locked.
$db->prepare("INSERT INTO stock_snapshots (guild_id,source,source_key,observed_at) VALUES (?,'banklord-savedvariables-v2',?,?)")->execute([$g,"window-v1-gv-$g",gmdate('Y-m-d H:i:s',time()-60)]);
$snap=(int)$db->lastInsertId();
$db->prepare("INSERT INTO snapshot_source_lines (snapshot_id,tab_no,slot_no,wow_item_id,variant_key,item_link,item_name,quantity) VALUES (?,1,1,2589,'item:2589','link','Leinenstoff',7)")->execute([$snap]);
(new StockReconciliation($db))->confirm($g,$admin,$snap);
$f=$db->prepare("SELECT i.wow_flavor FROM guild_stock s JOIN items i ON i.id=s.item_id WHERE s.guild_id=?");$f->execute([$g]);
$ok($f->fetchColumn()==='retail','retail guild stores retail items');
$ok($err(fn()=>GameFlavor::change($db,$g,$admin,'mop_classic'))==='GAME_VERSION_LOCKED','version locked once stock exists');

echo $fail?"FAIL: game versions ($fail)\n":"PASS: game versions (switch, lock, file check, items per version)\n";
if ($fail) exit(1);
