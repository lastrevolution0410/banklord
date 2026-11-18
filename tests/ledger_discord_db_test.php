<?php
// Bank ledger (no double entries across uploads), bank gold and value, Discord
// queue, creating further guilds, urgent orders. Needs a migrated MariaDB.
declare(strict_types=1);
foreach (['Config','Domain','Database','Orders','OrderRules','StockReconciliation','SavedVariablesParser','PeerRelay','Community','IngameOrders','BankLedger','Guilds'] as $c) require_once __DIR__."/../backend/src/$c.php";
use Banklord\{Database,Orders,StockReconciliation,BankLedger,Guilds,Discord,DomainError};
$db=Database::connect();$fail=0;
$ok=function(bool $c,string $m) use(&$fail){ if(!$c){$fail++; echo "FAIL: $m\n";} };
$err=function(callable $f){ try{$f();return 'OK';}catch(DomainError $e){return $e->getMessage();} };
$db->exec("INSERT INTO guilds (name,realm) VALUES ('Ledger','Everlook')");$g=(int)$db->lastInsertId();
$n=0;$user=function(string $role) use($db,$g,&$n){$n++;
  $db->prepare("INSERT INTO users (email,password_hash,display_name) VALUES (?,?,?)")->execute(["l$g-$n@x.invalid",'x',"L$n"]);$u=(int)$db->lastInsertId();
  $db->prepare("INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,?)")->execute([$g,$u,$role]);return $u;};
$admin=$user('admin');$bm=$user('bank_manager');$member=$user('member');
$db->prepare("INSERT INTO stock_snapshots (guild_id,source,source_key,observed_at) VALUES (?,'banklord-savedvariables-v2',?,?)")->execute([$g,"window-v1-l-$g",gmdate('Y-m-d H:i:s',time()-60)]);
$snap=(int)$db->lastInsertId();
$db->prepare("INSERT INTO snapshot_source_lines (snapshot_id,tab_no,slot_no,wow_item_id,variant_key,item_link,item_name,quantity) VALUES (?,1,1,2589,'item:2589','link','Leinenstoff',40)")->execute([$snap]);
(new StockReconciliation($db))->confirm($g,$bm,$snap);
$record=['name'=>'Ledger','realm'=>'Everlook'];
$now=time();$h=$now-$now%3600;
$file=function(int $at,int $hourShift) use($h){ $t=$h-3*3600+$hourShift;$t2=$h-26*3600+$hourShift;
  return "BanklordDB = {[\"bankLog\"] = {[\"guild\"]=\"Ledger\",[\"realm\"]=\"Everlook\",[\"capturedAt\"]=$at,[\"money\"]=5000000,
  [\"items\"]={{[\"t\"]=\"deposit\",[\"p\"]=\"Aria\",[\"i\"]=2589,[\"n\"]=\"Leinenstoff\",[\"q\"]=20,[\"tab\"]=1,[\"at\"]=$t},{[\"t\"]=\"deposit\",[\"p\"]=\"Aria\",[\"i\"]=2589,[\"n\"]=\"Leinenstoff\",[\"q\"]=20,[\"tab\"]=1,[\"at\"]=$t}},
  [\"gold\"]={{[\"t\"]=\"withdraw\",[\"p\"]=\"Kaelis\",[\"q\"]=150000,[\"at\"]=$t2},{[\"t\"]=\"deposit\",[\"p\"]=\"Aria\",[\"q\"]=990000,[\"at\"]=$t2}},
  [\"prices\"]={{[\"i\"]=2589,[\"c\"]=1250,[\"s\"]=\"tsm\"}}}}"; };
$ledger=new BankLedger($db);
$r=$ledger->process($g,$bm,$file($now,0),$record);
$ok(($r['added']??0)===4 && $r['priced']===1,'first upload stores all entries: '.json_encode($r));
$r=$ledger->process($g,$bm,$file($now+60,3600),$record);
$ok(($r['added']??-1)===0,'same log an hour later is not stored twice');
$ok(($ledger->process($g,$bm,str_replace('"Ledger"','"Andere"',$file($now,0)),$record)['status'])==='other_guild','log of another guild ignored');
$ok(($ledger->process($g,$bm,str_replace('"withdraw"','"steal"',$file($now,0)),$record)['status'])==='invalid','unknown actions rejected');
$sum=$ledger->summary($g,30);
$ok($sum['money']['copper']===5000000,'bank gold stored');
$ok($sum['stock_value']['copper']===40*1250,'bank value from TSM price');
$aria=array_values(array_filter($sum['players'],fn($p)=>$p['player']==='Aria'))[0]??[];
$ok(($aria['gold_in']??0)===990000 && ($aria['items_in']??0)===40,'per player totals');
$ok(count($ledger->entries($g,'money'))===2 && count($ledger->entries($g,'item','aria'))===2,'filters by kind and player');
$ok(str_contains(BankLedger::csv([['happened_at'=>'x','kind'=>'item','action'=>'deposit','player'=>'=HYPERLINK(1)','wow_item_id'=>1,'item_name'=>'a','quantity'=>1,'tab_no'=>1,'tab_to'=>null]]),"'=HYPERLINK"),'CSV guards formulas');

// Discord: only admins, only real webhook URLs, messages queued per wanted event.
$d=new Discord($db);
$ok($err(fn()=>$d->save($g,$bm,['webhook_url'=>'https://discord.com/api/webhooks/123456/abcdefghijklmnopqrstuvwxyz']))==='FORBIDDEN','only admins set Discord');
$ok($err(fn()=>$d->save($g,$admin,['webhook_url'=>'https://evil.example.org/api/webhooks/123456/abcdefghijklmnopqrstuvwxyz']))==='INVALID_DISCORD','foreign hosts rejected');
$ok($d->save($g,$admin,['webhook_url'=>'https://discord.com/api/webhooks/123456/abcdefghijklmnopqrstuvwxyz','events'=>['order','shipped']])['configured']===true,'webhook saved');
$ok(!array_key_exists('webhook_url',$d->view($g)),'webhook secret never returned');
$stock=(int)$db->query("SELECT id FROM guild_stock WHERE guild_id=$g")->fetchColumn();
$o=(new Orders($db))->create($g,$member,'ledger-order-0001',[['stock_id'=>$stock,'quantity'=>3]],'Raid @everyone',true);
$q=$db->query("SELECT message FROM discord_outbox WHERE guild_id=$g ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$ok(count($q)===1 && str_contains($q[0],'Dringend') && str_contains($q[0],'3× ') && !str_contains($q[0],'@everyone'),'order queued, urgent, mentions neutralised: '.json_encode($q));
$ok((int)$db->query("SELECT priority FROM orders WHERE id=$o")->fetchColumn()===1,'urgent flag stored');
(new Orders($db))->deliver($g,$bm,$o,'ma-200001-zzzzzz');
$ok((int)$db->query("SELECT COUNT(*) FROM discord_outbox WHERE guild_id=$g AND message LIKE '%verschickt%'")->fetchColumn()===1,'shipping queued');
$d->save($g,$admin,['events'=>['order']]);
$ok((int)$db->query("SELECT COUNT(*) FROM guild_discord WHERE guild_id=$g AND events='order'")->fetchColumn()===1,'events changed without resending the webhook');

// Further guilds: guild admins create them and become their admin.
$guilds=new Guilds($db);
$ok($err(fn()=>$guilds->create($member,['name'=>'Neu','realm'=>'Everlook','wow_flavor'=>'retail']))==='FORBIDDEN','members cannot create guilds');
$new=$guilds->create($admin,['name'=>"Retail-Gilde $g",'realm'=>'Blackhand','region'=>'EU','wow_flavor'=>'retail']);
$ok($new['wow_flavor']==='retail' && (string)$db->query("SELECT role FROM guild_memberships WHERE guild_id={$new['id']} AND user_id=$admin")->fetchColumn()==='admin','creator becomes admin');
$ok($err(fn()=>$guilds->create($admin,['name'=>"Retail-Gilde $g",'realm'=>'Blackhand','wow_flavor'=>'retail']))==='GUILD_EXISTS','no duplicate guilds');
$ok($err(fn()=>$guilds->create($admin,['name'=>'<b>x</b>','realm'=>'Blackhand','wow_flavor'=>'retail']))==='INVALID_GUILD','names validated');

echo $fail?"FAIL: ledger & discord ($fail)\n":"PASS: ledger, bank value, Discord, new guilds, urgent orders\n";
if ($fail) exit(1);
