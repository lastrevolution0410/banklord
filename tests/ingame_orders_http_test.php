<?php
// End-to-end over HTTP against dev/docker-compose.yml (php -S on :8080, migrated DB incl. 010).
declare(strict_types=1);
foreach (['Config','Domain','Database','StockReconciliation','BridgeTokens'] as $c) require __DIR__."/../backend/src/$c.php";
use Banklord\{Database,StockReconciliation,BridgeTokens};
$db=Database::connect(); $fail=0; $base=getenv('BANKLORD_TEST_BASE')?:'http://127.0.0.1:8080';
$ok=function(bool $c,string $m) use(&$fail){ if(!$c){$fail++; echo "FAIL: $m\n";} };
$http=function(string $method,string $url,string $token,string $body='') {
  $ctx=stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'content'=>$body,
    'header'=>"Authorization: Bearer $token\r\nContent-Type: text/plain; charset=utf-8\r\n"]]);
  $raw=file_get_contents($url,false,$ctx); preg_match('~ (\d{3}) ~',$http_response_header[0]??'',$m);
  return [(int)($m[1]??0), json_decode((string)$raw,true)];
};
$db->exec("INSERT INTO guilds (name,realm) VALUES ('Demo Guild','Everlook')"); $g=(int)$db->lastInsertId();
$mkUser=function(string $role) use($db,$g){ static $n=0;$n++;
  $db->prepare("INSERT INTO users (email,password_hash,display_name) VALUES (?,?,?)")->execute(["io$g-$n@x.invalid",'x',"U$n"]); $u=(int)$db->lastInsertId();
  $db->prepare("INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,?)")->execute([$g,$u,$role]); return $u; };
$manager=$mkUser('bank_manager'); $member=$mkUser('member');
// Stock via a confirmed scan.
$db->prepare("INSERT INTO stock_snapshots (guild_id,source,source_key,observed_at) VALUES (?,'banklord-savedvariables-v2',?,?)")->execute([$g,"window-v1-io-$g",gmdate('Y-m-d H:i:s',time()-60)]);
$sid=(int)$db->lastInsertId();
foreach ([[1,2589,'item:2589:0:0:0:0:0:0:0:0',20],[2,38938,'item:38938:0:0:0:0:0:0:0:0',3]] as [$slot,$item,$key,$qty])
  $db->prepare("INSERT INTO snapshot_source_lines (snapshot_id,tab_no,slot_no,wow_item_id,variant_key,item_link,item_name,quantity) VALUES (?,1,?,?,?,?,?,?)")->execute([$sid,$slot,$item,$key,'l',"Item $item",$qty]);
(new StockReconciliation($db))->confirm($g,$manager,$sid);
$tok=(new BridgeTokens($db))->issueMemberOrders($g,$member,'Test-PC')['token'];
$now=time(); $sv=function(array $outbox) {
  $rows=''; foreach($outbox as $o) $rows.="{[\"clientKey\"]=\"{$o[0]}\",[\"code\"]=\"{$o[1]}\",[\"character\"]=\"Testchar-Everlook\",[\"note\"]=\"\",[\"createdAt\"]={$o[2]},},";
  return "BanklordDB = {\n[\"orderOutbox\"] = {".$rows."},\n}\n"; };
$k1="ig-$now-abc123"; $code='BL1|2589,5,item:2589:0:0:0:0:0:0:0:0|38938,1,item:38938:0:0:0:0:0:0:0:0';
[$st,$r]=$http('GET',"$base/api/v1/bridge/guilds/$g/status",$tok);
$ok($st===200 && ($r['scope']??'')==='member.orders' && ($r['capabilities']??[])===['order_sync'],"status probe ($st)");
[$st,$r]=$http('POST',"$base/api/v1/bridge/guilds/$g/imports",$tok,$sv([[$k1,$code,$now],["ig-$now-zzz999",'BL1|99999,1,item:99999:0:0:0:0:0:0:0:0',$now]]));
$oid=$r['ingame_orders'][$k1]['order_id']??null;
$ok($st===200 && is_int($oid),"order created ($st) ".json_encode($r['ingame_orders']??$r));
$ok(($r['ingame_orders']["ig-$now-zzz999"]['error']??'')==='UNKNOWN_ITEM','unknown item rejected');
$ok(!isset($r['import']),'member token never stages scans');
$ok(($r['ingame_state']['orders'][0]['status']??'')==='submitted' && count($r['ingame_state']['orders'][0]['lines']??[])===2,'state shows order');
[$st,$r]=$http('POST',"$base/api/v1/bridge/guilds/$g/imports",$tok,$sv([[$k1,$code,$now]]));
$ok(($r['ingame_orders'][$k1]['order_id']??0)===$oid,'re-upload is idempotent');
$c=$db->prepare('SELECT COUNT(*) FROM orders WHERE guild_id=? AND user_id=?');$c->execute([$g,$member]);
$ok((int)$c->fetchColumn()===1,'exactly one order stored');
[$st,$r]=$http('GET',"$base/api/v1/bridge/guilds/$g/state",$tok);
$ok($st===200 && ($r['ingame_state']['orders'][0]['id']??0)===$oid,'state endpoint');
[$st,$r]=$http('POST',"$base/api/v1/bridge/guilds/$g/imports",$tok,"BanklordDB = {[\"orderOutbox\"]={{[\"clientKey\"]=\"bad\"}}}");
$ok($st===200 && isset($r['ingame_orders']['_file']['error']),'malformed outbox reported, nothing created');
$ctx=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'header'=>"Authorization: Bearer $tok\r\nContent-Type: application/json\r\n",
  'content'=>json_encode(['device'=>'DEMO-PC','companion'=>'0.5.0','addon'=>'0.6.1','updater'=>'0.9.5','x'=>'<script>'])]]);
$hb=json_decode((string)file_get_contents("$base/api/v1/bridge/guilds/$g/heartbeat",false,$ctx),true);
$row=$db->query("SELECT device,addon_version,updater_version FROM device_heartbeats WHERE guild_id=$g")->fetch();
$ok(($hb['ok']??false)===true && $row && $row['device']==='DEMO-PC' && $row['addon_version']==='0.6.1' && $row['updater_version']==='0.9.5','heartbeat stored');
$db->prepare("UPDATE guild_memberships SET status='disabled' WHERE guild_id=? AND user_id=?")->execute([$g,$member]);
[$st,$r]=$http('GET',"$base/api/v1/bridge/guilds/$g/state",$tok);
$ok($st===401,'disabled member token rejected');
[$st,$r]=$http('GET',"$base/api/v1/bridge/guilds/".($g+1000)."/state",$tok);
$ok($st===401,'token bound to its guild');
echo $fail?"FAILED ($fail)\n":"PASS: in-game orders over HTTP\n"; exit($fail?1:0);
