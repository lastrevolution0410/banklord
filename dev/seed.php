<?php
// Local demo data: admin@demo.invalid / demo-password-123 (bank admin), member@demo.invalid / demo-password-123.
declare(strict_types=1);
foreach (['Config','Domain','Database','Orders','OrderRules','StockReconciliation'] as $c) require __DIR__."/../backend/src/$c.php";
use Banklord\{Database,StockReconciliation,Orders};
$db=Database::connect();
if ($db->query("SELECT COUNT(*) FROM users WHERE email='admin@demo.invalid'")->fetchColumn()) { echo "already seeded\n"; exit; }
$db->exec("INSERT INTO guilds (name,realm) VALUES ('Demo Guild','Everlook')"); $g=(int)$db->lastInsertId();
$u=[];
foreach ([['admin@demo.invalid','Admin','admin'],['member@demo.invalid','Member','member']] as [$mail,$name,$role]) {
  $db->prepare('INSERT INTO users (email,password_hash,display_name) VALUES (?,?,?)')->execute([$mail,password_hash('demo-password-123',PASSWORD_DEFAULT),$name]);
  $u[$role]=(int)$db->lastInsertId();
  $db->prepare('INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,?)')->execute([$g,$u[$role],$role]);
}
$items=[[2589,'Leinenstoff',120,'Handwerkswaren','Stoff','inv_fabric_linen_01'],[2592,'Wollstoff',64,'Handwerkswaren','Stoff','inv_fabric_wool_01'],[72988,'Windwollstoff',200,'Handwerkswaren','Stoff','inv_fabric_windwool'],
 [72092,'Geisterhaftes Eisenerz',85,'Handwerkswaren','Metall & Stein','inv_ore_ghostiron'],[72093,'Kyparitbrocken',22,'Handwerkswaren','Metall & Stein','inv_ore_ghostiron'],
 [76061,'Geist der Harmonie',9,'Handwerkswaren','Elementar','inv_misc_gem_x4_rare_uncut_red'],[74249,'Geisterstaub',140,'Handwerkswaren','Verzauberkunst','inv_fabric_windwool'],
 [76084,'Fläschchen der Frühlingsblüte',30,'Verbrauchbar','Fläschchen','inv_alchemy_endlessflask_04'],[76085,'Fläschchen der Erde',18,'Verbrauchbar','Fläschchen','inv_alchemy_endlessflask_06'],
 [76093,'Trank der Jadeschlange',40,'Verbrauchbar','Trank','trade_alchemy_potione3'],[74650,'Mogu-Fischeintopf',60,'Verbrauchbar','Essen & Trinken','inv_alchemy_endlessflask_04'],
 [38938,'Rolle: Armschiene – Werte',3,'Verbrauchbar','Verzauberung','inv_scroll_03'],[76131,'Primordialer Rubin',12,'Edelstein','Rot','inv_misc_gem_x4_rare_uncut_red'],[89112,'Mote der Harmonie',55,'Handwerkswaren','Elementar','inv_misc_gem_x4_rare_uncut_red']];
$db->prepare("INSERT INTO stock_snapshots (guild_id,source,source_key,observed_at) VALUES (?,'banklord-savedvariables-v2','window-v1-seed',?)")->execute([$g,gmdate('Y-m-d H:i:s',time()-600)]);
$sid=(int)$db->lastInsertId();$slot=0;
$meta=$db->prepare("INSERT INTO item_client_metadata (guild_id,wow_item_id,variant_hash,variant_key,client_flavor,client_version,client_build,locale,localized_name,quality,item_type,item_sub_type,icon_name,completeness,captured_at) VALUES (?,?,?,?,'mop_classic','5.5.4','0','deDE',?,1,?,?,?,'basic',UTC_TIMESTAMP())");
foreach ($items as [$id,$name,$qty,$type,$sub,$icon]) {
  $key="item:$id:0:0:0:0:0:0:0:0";
  $db->prepare('INSERT INTO snapshot_source_lines (snapshot_id,tab_no,slot_no,wow_item_id,variant_key,item_link,item_name,quantity) VALUES (?,1,?,?,?,?,?,?)')->execute([$sid,++$slot,$id,$key,'l',$name,$qty]);
  try { $meta->execute([$g,$id,hash('sha256',$key),$key,$name,$type,$sub,$icon]); } catch (\Throwable $e) { echo "meta skipped: ".$e->getMessage()."\n"; break; }
}
(new StockReconciliation($db))->confirm($g,$u['admin'],$sid);
$stock=fn($id)=>(int)$db->query("SELECT gs.id FROM guild_stock gs JOIN items i ON i.id=gs.item_id WHERE gs.guild_id=$g AND i.wow_item_id=$id")->fetchColumn();
$o=new Orders($db);
$o->create($g,$u['member'],'seed-order-0001',[['stock_id'=>$stock(76084),'quantity'=>5],['stock_id'=>$stock(74650),'quantity'=>20]],'Für den Raid am Donnerstag');
$o->create($g,$u['member'],'seed-order-0002',[['stock_id'=>$stock(72988),'quantity'=>40]],'');
echo "seeded guild $g\n";
