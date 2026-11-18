<?php
// Order rules: weekly limit + officer approval, partial delivery, member cancel,
// auto-expiry, view-only tabs. Needs a migrated MariaDB; creates its own guild.
declare(strict_types=1);
foreach (['Config','Domain','Database','Orders','OrderRules','StockReconciliation','SavedVariablesParser','PeerRelay','Community','IngameOrders'] as $c) require __DIR__."/../backend/src/$c.php";
use Banklord\{Database,Orders,OrderRules,StockReconciliation,IngameOrders,DomainError};
$db=Database::connect();$fail=0;
$ok=function(bool $c,string $m) use(&$fail){ if(!$c){$fail++; echo "FAIL: $m\n";} };
$err=function(callable $f){ try{$f();return 'OK';}catch(DomainError $e){return $e->getMessage();} };
$db->exec("INSERT INTO guilds (name,realm) VALUES ('Rules','Everlook')");$g=(int)$db->lastInsertId();
$user=function(string $role) use($db,$g){
  static $n=0;$n++;
  $db->prepare("INSERT INTO users (email,password_hash,display_name) VALUES (?,?,?)")->execute(["r$g-$n@x.invalid",'x',"U$n"]);$u=(int)$db->lastInsertId();
  $db->prepare("INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,?)")->execute([$g,$u,$role]);return $u;
};
$admin=$user('admin');$officer=$user('officer');$bm=$user('bank_manager');$member=$user('member');
$rules=new OrderRules($db);$orders=new Orders($db);
$ok($err(fn()=>$rules->save($g,$bm,['expiry_days'=>3]))==='FORBIDDEN','only admins set rules');
$ok($err(fn()=>$rules->save($g,$admin,['viewonly_tabs'=>[9]]))==='INVALID_ORDER_RULES','tab range checked');
$rules->save($g,$admin,['expiry_days'=>14,'member_weekly_limit'=>20,'manager_weekly_limit'=>0,'viewonly_tabs'=>[2]]);
$ok($rules->get($g)['viewonly_tabs']===[2] && $rules->get($g)['member_weekly_limit']===20,'rules stored');

// Stock from a scan: tab 1 has 30 ore, tab 2 (view-only) has 5 ore and 4 gems.
$db->prepare("INSERT INTO stock_snapshots (guild_id,source,source_key,observed_at) VALUES (?,'banklord-savedvariables-v2',?,?)")->execute([$g,"window-v1-rules-$g",gmdate('Y-m-d H:i:s',time()-60)]);
$snap=(int)$db->lastInsertId();
foreach ([[1,1,2589,30],[2,1,2589,5],[2,2,52190,4]] as [$tab,$slot,$item,$qty])
  $db->prepare("INSERT INTO snapshot_source_lines (snapshot_id,tab_no,slot_no,wow_item_id,variant_key,item_link,item_name,quantity) VALUES (?,?,?,?,?,?,?,?)")->execute([$snap,$tab,$slot,$item,"item:$item",'link',"Item $item",$qty]);
(new StockReconciliation($db))->confirm($g,$bm,$snap);
$stock=function(int $item) use($db,$g){$q=$db->prepare("SELECT s.id,s.physical_qty,s.viewonly_qty FROM guild_stock s JOIN items i ON i.id=s.item_id WHERE s.guild_id=? AND i.wow_item_id=?");$q->execute([$g,$item]);return $q->fetch();};
$ore=$stock(2589);$gem=$stock(52190);
$ok((int)$ore['physical_qty']===35 && (int)$ore['viewonly_qty']===5,'view-only share recorded');
$ok($err(fn()=>$orders->create($g,$member,'rules-gem-000001',[['stock_id'=>(int)$gem['id'],'quantity'=>1]]))==='VIEW_ONLY_STOCK','view-only item not orderable');
$ok($err(fn()=>$orders->create($g,$member,'rules-ore-000009',[['stock_id'=>(int)$ore['id'],'quantity'=>31]]))==='VIEW_ONLY_STOCK','only the orderable share can be ordered');

// Weekly limit: 15 ok, the next 10 exceed 20 and need an officer.
$o1=$orders->create($g,$member,'rules-ore-000001',[['stock_id'=>(int)$ore['id'],'quantity'=>15]]);
$o2=$orders->create($g,$member,'rules-ore-000002',[['stock_id'=>(int)$ore['id'],'quantity'=>10]]);
$na=fn(int $id)=>(int)$db->query("SELECT needs_approval FROM orders WHERE id=$id")->fetchColumn();
$ok($na($o1)===0 && $na($o2)===1,'order above weekly limit needs approval');
$ok($err(fn()=>$orders->deliver($g,$bm,$o2,'ma-100000-aaaaaa'))==='APPROVAL_REQUIRED','manager cannot deliver before approval');
$ok($err(fn()=>$orders->approve($g,$bm,$o2))==='FORBIDDEN','bank manager cannot approve over limit');
$ok($err(fn()=>$orders->approve($g,$officer,$o2))==='OK' && $na($o2)===0,'officer approves');

// Partial delivery: 6 of 15 sent, the rest stays open; the same upload books once.
$ok($orders->deliver($g,$bm,$o1,'ma-100001-bbbbbb',[2589=>6])==='partially_fulfilled','partial delivery leaves order open');
$ok($orders->deliver($g,$bm,$o1,'ma-100001-bbbbbb',[2589=>6])==='partially_fulfilled','repeated upload is booked once');
$f=fn(int $id)=>(int)$db->query("SELECT SUM(fulfilled_qty) FROM order_lines WHERE order_id=$id")->fetchColumn();
$ok($f($o1)===6,'six delivered');
$view=(new IngameOrders($db))->manageView($g,$bm);$open=null;
foreach ($view as $row) if ($row['id']===$o1) $open=$row['lines'][0]['qty'];
$ok($open===9,'manager list shows the open rest');
// Urgent orders from the game: the outbox flag is read and the manager list puts them first.
$t=time();$sv="BanklordDB = {[\"orderOutbox\"]={{[\"clientKey\"]=\"ig-$t-urgen1\",[\"code\"]=\"BL1|2589,1,item:2589:0:0:0:0:0:0:0:0\",[\"createdAt\"]=$t,[\"urgent\"]=true},{[\"clientKey\"]=\"ig-$t-norm01\",[\"code\"]=\"BL1|2589,1,item:2589:0:0:0:0:0:0:0:0\",[\"createdAt\"]=$t}}}";
$ex=IngameOrders::extract($sv);
$ok($ex[0]['urgent']===true && $ex[1]['urgent']===false,'outbox urgent flag read');
$bad=false;try { IngameOrders::extract(str_replace('=true','="ja"',$sv)); } catch (\InvalidArgumentException $e) { $bad=true; }
$ok($bad,'urgent must be a boolean');
$ou=$orders->create($g,$officer,'rules-urgent-0001',[['stock_id'=>(int)$ore['id'],'quantity'=>1]],'',true);
$view=(new IngameOrders($db))->manageView($g,$bm);
$ok($view[0]['id']===$ou && $view[0]['urgent']===true && count(array_filter($view,fn($r)=>$r['urgent']))===1,'urgent order first and flagged for bank managers');
$orders->cancel($g,$officer,$ou);
$ok($orders->deliver($g,$bm,$o1,'ma-100002-cccccc')==='fulfilled','rest delivered later');

// Member cancels own order in game; another member may not.
$o3=$orders->create($g,$officer,'rules-ore-000003',[['stock_id'=>(int)$ore['id'],'quantity'=>1]]);
$ok($err(fn()=>$orders->cancel($g,$member,$o3))==='FORBIDDEN','foreign order not cancellable');
$sv="BanklordDB = { manageOutbox = { { key = \"ma-100003-dddddd\", order = $o2, action = \"cancel\" } } }";
$res=(new IngameOrders($db))->processManage($g,$member,$sv);
$ok(($res['ma-100003-dddddd']['status']??'')==='cancelled','member cancels own order via outbox');

// Auto-expiry: old untouched order is cancelled, partly delivered keeps its part.
$o4=$orders->create($g,$officer,'rules-ore-000004',[['stock_id'=>(int)$ore['id'],'quantity'=>4]]);
$o5=$orders->create($g,$officer,'rules-ore-000005',[['stock_id'=>(int)$ore['id'],'quantity'=>4]]);
$orders->deliver($g,$bm,$o5,'ma-100004-eeeeee',[2589=>1]);
$db->exec("UPDATE orders SET created_at=UTC_TIMESTAMP()-INTERVAL 20 DAY WHERE id IN ($o4,$o5)");
$expired=$rules->expire($g);
$st=fn(int $id)=>(string)$db->query("SELECT status FROM orders WHERE id=$id")->fetchColumn();
$ok(in_array($o4,$expired,true) && $st($o4)==='cancelled','old order expired');
$ok($st($o5)==='fulfilled' && $f($o5)===1,'partly delivered order keeps its delivered part');
$ok($st($o3)==='submitted','young order untouched');

// Review fixes: decide the rest of a partly mailed order, book mails for expired orders, flag cleanup.
$o6=$orders->create($g,$officer,'rules-ore-000006',[['stock_id'=>(int)$ore['id'],'quantity'=>5]]);
$orders->deliver($g,$bm,$o6,'ma-100005-ffffff',[2589=>2]);
$line6=(int)$db->query("SELECT id FROM order_lines WHERE order_id=$o6")->fetchColumn();
$ok($err(fn()=>$orders->decide($g,$bm,$o6,[$line6=>1]))==='BAD_APPROVAL','cannot approve less than already mailed');
$ok($err(fn()=>$orders->decide($g,$bm,$o6,[$line6=>5]))==='OK' && $st($o6)==='partially_fulfilled','rest of a partly mailed order can be decided');
$o7=$orders->create($g,$officer,'rules-ore-000007',[['stock_id'=>(int)$ore['id'],'quantity'=>2]]);
$db->exec("UPDATE orders SET created_at=UTC_TIMESTAMP()-INTERVAL 20 DAY WHERE id=$o7");$rules->expire($g);
$ok($st($o7)==='cancelled','expired before the mail was booked');
$ok($orders->deliver($g,$bm,$o7,'ma-100006-gggggg')==='fulfilled' && $f($o7)===2,'mail sent for an expired order is still booked');
$o8=$orders->create($g,$member,'rules-ore-000008',[['stock_id'=>(int)$ore['id'],'quantity'=>25]]);
$orders->cancel($g,$member,$o8);
$ok($na($o8)===0 && $err(fn()=>$orders->approve($g,$officer,$o8))==='ORDER_NOT_FULFILLABLE','closed orders lose the approval flag');

// The in-game state carries rules and the restock list for managers.
$db->prepare("INSERT INTO guild_restock_rules (guild_id,stock_id,minimum_qty,target_qty,updated_by) VALUES (?,?,50,80,?)")->execute([$g,(int)$ore['id'],$bm]);
$state=(new IngameOrders($db))->state($g,$bm);
$ok(($state['rules']['viewonly_tabs']??null)===[2],'state has view-only tabs');
$ok(($state['restock'][0]['item_id']??0)===2589 && $state['restock'][0]['missing']>0,'state has shopping list');
$mstate=(new IngameOrders($db))->state($g,$member);
$ok(!isset($mstate['restock']) && $mstate['rules']['weekly_limit']===20,'members get their limit, no shopping list');

echo $fail?"FAIL: order rules ($fail)\n":"PASS: order rules (limit, approval, partial, cancel, expiry, view-only)\n";
if ($fail) exit(1);
