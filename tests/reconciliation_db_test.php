<?php
// Needs a migrated MariaDB (dev/docker-compose.yml). Creates its own guild, leaves data behind.
declare(strict_types=1);
foreach (['Config','Domain','Database','StockReconciliation'] as $c) require __DIR__."/../backend/src/$c.php";
use Banklord\{Database,StockReconciliation,DomainError};
$db=Database::connect(); $r=new StockReconciliation($db); $fail=0;
$ok=function(bool $c,string $m) use(&$fail){ if(!$c){$fail++; echo "FAIL: $m\n";} };
$err=function(callable $f){ try{$f();return 'OK';}catch(DomainError $e){return $e->getMessage();} };
$db->exec("INSERT INTO guilds (name,realm) VALUES ('Test','Everlook')"); $g=(int)$db->lastInsertId();
$db->prepare("INSERT INTO users (email,password_hash,display_name) VALUES (?,?,?)")->execute(["t$g@x.invalid",'x','T']); $u=(int)$db->lastInsertId();
$db->prepare("INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,'bank_manager')")->execute([$g,$u]);
$snap=function(int $ageSec,array $lines) use($db,$g){
  static $n=0; $n++;
  $db->prepare("INSERT INTO stock_snapshots (guild_id,source,source_key,observed_at) VALUES (?,'banklord-savedvariables-v2',?,?)")
     ->execute([$g,"window-v1-test-$g-$n",gmdate('Y-m-d H:i:s',time()-$ageSec)]);
  $id=(int)$db->lastInsertId(); $slot=0;
  foreach($lines as $item=>$qty) $db->prepare("INSERT INTO snapshot_source_lines (snapshot_id,tab_no,slot_no,wow_item_id,variant_key,item_link,item_name,quantity) VALUES (?,1,?,?,?,?,?,?)")
     ->execute([$id,++$slot,$item,"item:$item",'link',"Item $item",$qty]);
  return $id;
};
// Two scans from one session, uploaded together at logout; the older one gets confirmed first.
$a=$snap(600,[2589=>20,38938=>3]);
$b=$snap(300,[2589=>18,38938=>3]);
$ok($err(fn()=>$r->confirm($g,$u,$a))==='SNAPSHOT_NOT_LATEST','older scan is not latest');
$ok($err(fn()=>$r->confirm($g,$u,$b))==='OK','latest scan confirms');
// Next session: earlier reconciliation entries must not count as movement.
$c=$snap(60,[2589=>15,38938=>2]);
$ok(($r->inspect($g,$c)['reason']??'')==='READY_FOR_RECHECK','inspect ready after previous reconcile');
$ok($err(fn()=>$r->confirm($g,$u,$c))==='OK','scan after reconcile confirms (was MOVEMENTS_AFTER_SCAN)');
$q=$db->prepare("SELECT s.physical_qty FROM guild_stock s JOIN items i ON i.id=s.item_id WHERE s.guild_id=? AND i.wow_item_id=2589");$q->execute([$g]);
$ok((int)$q->fetchColumn()===15,'stock follows latest scan');
// A real issue booked after the scan still blocks.
$d=$snap(30,[2589=>15,38938=>2]);
$sid=(int)$db->query("SELECT id FROM guild_stock WHERE guild_id=$g LIMIT 1")->fetchColumn();
$db->prepare("INSERT INTO stock_ledger (guild_id,stock_id,event_type,physical_delta,reserved_delta,actor_user_id,operation_key) VALUES (?,?,'fulfill',-1,0,?,?)")->execute([$g,$sid,$u,"t-$g-f"]);
$ok($err(fn()=>$r->confirm($g,$u,$d))==='MOVEMENTS_AFTER_SCAN','real issue after scan blocks');
// A scan observed before the accepted stock state never regresses it.
$db->prepare("DELETE FROM stock_ledger WHERE operation_key=?")->execute(["t-$g-f"]);
$e=$snap(3000,[2589=>99]);
$ok(($r->inspect($g,$e)['reason']??'')==='SCAN_OLDER_THAN_STOCK','inspect: older than stock');
$x=$err(fn()=>$r->confirm($g,$u,$e));$ok($x==='SCAN_OLDER_THAN_STOCK','confirm: older than stock, got '.$x);
// Manual confirmation accepts a scan from a long session, automation does not.
// Automation accepts a scan from a long play session (logout hours later), but never older than 12 hours.
$db->prepare("UPDATE guild_stock SET observed_at=? WHERE guild_id=?")->execute([gmdate('Y-m-d H:i:s',time()-90000),$g]);
$db->prepare("INSERT INTO guild_scan_automation (guild_id,enabled,enabled_by,enabled_at) VALUES (?,1,?,UTC_TIMESTAMP())")->execute([$g,$u]);
$f=$snap(46800,[2589=>1]);
$ok($err(fn()=>$r->confirm($g,$u,$f,true))==='SNAPSHOT_TOO_OLD','automation: 13h old scan rejected');
$f=$snap(7200,[2589=>1]);
$ok($err(fn()=>$r->confirm($g,$u,$f,true))==='OK','automation: 2h old scan accepted');
// Officers count as bank managers.
$db->prepare("UPDATE guild_memberships SET role='officer' WHERE guild_id=? AND user_id=?")->execute([$g,$u]);
$h=$snap(60,[2589=>4]);
$ok($err(fn()=>$r->confirm($g,$u,$h,true))==='OK','officer scan reconciles');
$db->prepare("UPDATE guild_memberships SET role='member' WHERE guild_id=? AND user_id=?")->execute([$g,$u]);
$h=$snap(30,[2589=>3]);
$ok($err(fn()=>$r->confirm($g,$u,$h,true))==='FORBIDDEN','member scan never reconciles');
echo $fail?"FAILED ($fail)\n":"PASS: reconciliation scenarios\n"; exit($fail?1:0);
