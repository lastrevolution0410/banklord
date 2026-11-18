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
// Bank manager in game: sees open orders with recipient, delivers and rejects by mail outbox.
[$st,$r]=$http('GET',"$base/api/v1/bridge/guilds/$g/state",$tok);
$ok(!array_key_exists('manage',$r['ingame_state']??[]),'members get no manager view');
$c=$db->prepare("SELECT COUNT(*) FROM characters WHERE guild_id=? AND user_id=? AND name='Testchar' AND realm='Everlook'");$c->execute([$g,$member]);
$ok((int)$c->fetchColumn()===1,'in-game character linked to the account');
[$st,$r]=$http('POST',"$base/api/v1/bridge/guilds/$g/imports",$tok,$sv([["ig-$now-second",'BL1|2589,2,item:2589:0:0:0:0:0:0:0:0',$now]]));
$oid2=$r['ingame_orders']["ig-$now-second"]['order_id']??0;
$mtok=(new BridgeTokens($db))->issueMemberOrders($g,$manager,'Bank-PC')['token'];
[$st,$r]=$http('GET',"$base/api/v1/bridge/guilds/$g/state",$mtok);
$m=$r['ingame_state']['manage']??[];
$ok(count($m)===2 && $m[0]['id']===$oid && $m[0]['recipient']==='Testchar-Everlook' && $m[0]['lines'][0]['qty']===5,'manager sees open orders '.json_encode($m));
$mv="BanklordDB = {[\"manageOutbox\"]={{[\"key\"]=\"ma-$now-aaaaaa\",[\"order\"]=$oid,[\"action\"]=\"deliver\"},{[\"key\"]=\"ma-$now-bbbbbb\",[\"order\"]=$oid2,[\"action\"]=\"reject\"}}}";
[$st,$r]=$http('POST',"$base/api/v1/bridge/guilds/$g/imports",$mtok,$mv);
$res=$r['ingame_state']['manage_results']??[];
$ok(($res["ma-$now-aaaaaa"]['status']??'')==='fulfilled' && ($res["ma-$now-bbbbbb"]['status']??'')==='rejected','deliver + reject '.json_encode($res));
$ok(($r['ingame_state']['manage']??null)===[],'no open orders left');
[$st,$r]=$http('POST',"$base/api/v1/bridge/guilds/$g/imports",$mtok,$mv);
$ok(($r['ingame_state']['manage_results']["ma-$now-aaaaaa"]['status']??'')==='fulfilled','deliver is idempotent');
[$st,$r]=$http('POST',"$base/api/v1/bridge/guilds/$g/imports",$tok,"BanklordDB = {[\"manageOutbox\"]={{[\"key\"]=\"ma-$now-cccccc\",[\"order\"]=$oid2,[\"action\"]=\"deliver\"}}}");
$ok(isset($r['ingame_state']['manage_results']["ma-$now-cccccc"]['error']),'members cannot deliver');
// Several guilds per PC: rows tagged for another guild wait; characters from game data get linked.
$tagged="BanklordDB = {[\"orderOutbox\"]={{[\"clientKey\"]=\"ig-$now-other1\",[\"code\"]=\"BL1|2589,1,item:2589:0:0:0:0:0:0:0:0\",[\"character\"]=\"Alt-Everlook\",[\"note\"]=\"\",[\"createdAt\"]=$now,[\"guild\"]=\"Andere Gilde\",[\"guildRealm\"]=\"Everlook\"}},"
  ."[\"characters\"]={[\"Mainchar-Everlook\"]={[\"guild\"]=\"Demo Guild\",[\"guildRealm\"]=\"Everlook\"},[\"Fremd-Everlook\"]={[\"guild\"]=\"Andere Gilde\",[\"guildRealm\"]=\"Everlook\"}}}";
[$st,$r]=$http('POST',"$base/api/v1/bridge/guilds/$g/imports",$tok,$tagged);
$ok(!isset($r['ingame_orders']["ig-$now-other1"]),'order tagged for another guild is left for that guild');
$ok(($r['ingame_state']['guild']['name']??'')==='Demo Guild','state names its guild');
$c=$db->prepare("SELECT GROUP_CONCAT(name ORDER BY name) FROM characters WHERE guild_id=? AND user_id=?");$c->execute([$g,$member]);
$ok($c->fetchColumn()==='Mainchar,Testchar','only characters of this guild are linked');
// Website order: recipient character is mandatory and must be the member's own.
$db->prepare("INSERT INTO users (email,password_hash,display_name) VALUES (?,?,'Web')")->execute(["web$g@x.invalid",password_hash('test-password-123',PASSWORD_DEFAULT)]);$web=(int)$db->lastInsertId();
$db->prepare("INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,'member')")->execute([$g,$web]);
$jar='';$csrf='';
$s=function(string $method,string $path,?array $body=null,array $extra=[]) use(&$jar,&$csrf,$base){
  $h="Content-Type: application/json\r\n".($jar?"Cookie: $jar\r\n":'').($csrf?"X-CSRF-Token: $csrf\r\n":'').implode('',array_map(fn($k,$v)=>"$k: $v\r\n",array_keys($extra),$extra));
  $raw=file_get_contents($base.'/api/v1'.$path,false,stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'header'=>$h,'content'=>$body===null?'':json_encode($body)]]));
  foreach($http_response_header as $line) if(preg_match('/^Set-Cookie: ([^;]+)/i',$line,$m)) $jar=$m[1];
  preg_match('~ (\d{3}) ~',$http_response_header[0],$m);$j=json_decode((string)$raw,true);if(isset($j['csrf']))$csrf=$j['csrf'];return [(int)$m[1],$j];};
$s('GET','/session');$s('POST','/login',['email'=>"web$g@x.invalid",'password'=>'test-password-123']);
$stock=(int)$db->query("SELECT gs.id FROM guild_stock gs JOIN items i ON i.id=gs.item_id WHERE gs.guild_id=$g AND i.wow_item_id=2589")->fetchColumn();
$foreign=(int)$db->query("SELECT id FROM characters WHERE guild_id=$g AND name='Testchar'")->fetchColumn();
[$st,$r]=$s('POST',"/guilds/$g/orders",['lines'=>[['stock_id'=>$stock,'quantity'=>1]],'note'=>''],['Idempotency-Key'=>"web-$now-order1"]);
$ok($st===422 && ($r['error']??'')==='CHARACTER_REQUIRED','web order without character rejected');
[$st,$r]=$s('POST',"/guilds/$g/orders",['lines'=>[['stock_id'=>$stock,'quantity'=>1]],'note'=>'','character_id'=>$foreign],['Idempotency-Key'=>"web-$now-order2"]);
$ok($st===422,'another member\'s character rejected');
$db->prepare("INSERT INTO characters (guild_id,user_id,name,realm) VALUES (?,?,'Webchar','Everlook')")->execute([$g,$web]);$own=(int)$db->lastInsertId();
[$st,$r]=$s('POST',"/guilds/$g/orders",['lines'=>[['stock_id'=>$stock,'quantity'=>1]],'note'=>'','character_id'=>$own],['Idempotency-Key'=>"web-$now-order3"]);
$rc=$db->prepare('SELECT recipient FROM orders WHERE id=?');$rc->execute([$r['id']??0]);
$ok($st===200 && $rc->fetchColumn()==='Webchar-Everlook','web order stores chosen recipient');
// Joining by in-game confirmation: manager vouches, companion proves its key, welcome link sets the account.
$kp=sodium_crypto_sign_keypair();$pk=bin2hex(sodium_crypto_sign_publickey($kp));$sk=sodium_crypto_sign_secretkey($kp);
$claimFile=function(string $key,string $char,string $guildName) use($now){return "BanklordDB = {[\"claims\"]={[\"$key\"]={[\"character\"]=\"$char\",[\"guild\"]=\"$guildName\",[\"guildRealm\"]=\"Everlook\",[\"seen\"]=$now}}}";};
$json=function(string $method,string $path,?array $body=null) use($base){
  $raw=file_get_contents($base.'/api/v1'.$path,false,stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'header'=>"Content-Type: application/json\r\n",'content'=>$body===null?'':json_encode($body)]]));
  preg_match('~ (\d{3}) ~',$http_response_header[0],$m);return [(int)$m[1],json_decode((string)$raw,true)];};
[$st,$r]=$json('GET',"/claims/challenge?key=$pk");
$ok($st===200 && $r['nonce']===null,'unknown key has no claim yet');
$http('POST',"$base/api/v1/bridge/guilds/$g/imports",$tok,$claimFile($pk,'Neuling-Everlook','Demo Guild'));
[$st,$r]=$json('GET',"/claims/challenge?key=$pk");
$ok($r['nonce']===null,'a plain member cannot vouch');
$http('POST',"$base/api/v1/bridge/guilds/$g/imports",$mtok,$claimFile($pk,'Neuling-Everlook','Andere Gilde'));
[$st,$r]=$json('GET',"/claims/challenge?key=$pk");
$ok($r['nonce']===null,'claims for another guild are ignored');
$http('POST',"$base/api/v1/bridge/guilds/$g/imports",$mtok,$claimFile($pk,'Neuling-Everlook','Demo Guild'));
[$st,$r]=$json('GET',"/claims/challenge?key=$pk");$nonce=$r['nonce']??'';
$ok(is_string($nonce) && strlen($nonce)===64 && ($r['guilds'][0]['guild_id']??0)===$g,'manager vouch recorded '.json_encode($r));
$origin=preg_replace('~/+$~','',$base);
$bad=bin2hex(sodium_crypto_sign_detached("banklord-claim|https://evil.example|$nonce",$sk));
[$st,$r]=$json('POST','/claims/redeem',['key'=>$pk,'signature'=>$bad]);
$ok(($r['error']??'')==='CLAIM_SIGNATURE_INVALID','signature for another site rejected');
[$st,$r]=$json('GET',"/claims/challenge?key=$pk");$nonce=$r['nonce'];
$sig=bin2hex(sodium_crypto_sign_detached("banklord-claim|$origin|$nonce",$sk));
[$st,$r]=$json('POST','/claims/redeem',['key'=>$pk,'signature'=>$sig]);
$grant=$r['grants'][0]??[];
$ok($st===200 && str_starts_with($grant['token']??'','bl1_') && str_contains((string)($grant['welcome']??''),'#welcome='),'redeem grants token and welcome link '.json_encode($r));
[$st,$r]=$json('POST','/claims/redeem',['key'=>$pk,'signature'=>$sig]);
$ok(($r['error']??'')==='CLAIM_SIGNATURE_INVALID','nonce cannot be replayed');
[$st,$r]=$http('GET',"$base/api/v1/bridge/guilds/$g/status",$grant['token']);
$ok($st===200 && ($r['scope']??'')==='member.orders','granted token works');
$welcome=substr((string)$grant['welcome'],strpos((string)$grant['welcome'],'=')+1);
$jar='';$csrf='';$s('GET','/session');
[$st,$r]=$s('POST','/claims/complete',['welcome'=>$welcome,'email'=>"neu$g@example.org",'password'=>'short','display_name'=>'Neuling']);
$ok(($r['error']??'')==='WEAK_PASSWORD','weak password rejected');
[$st,$r]=$s('POST','/claims/complete',['welcome'=>$welcome,'email'=>"neu$g@example.org",'password'=>'a-long-test-password','display_name'=>'Neuling']);
$ok($st===200 && is_int($r['user_id']??null),'registration completed');
[$st,$r]=$s('POST','/claims/complete',['welcome'=>$welcome,'email'=>"neu2$g@example.org",'password'=>'a-long-test-password','display_name'=>'X']);
$ok(($r['error']??'')==='WELCOME_INVALID','welcome link works only once');
$jar='';$csrf='';$s('GET','/session');[$st]=$s('POST','/login',['email'=>"neu$g@example.org",'password'=>'a-long-test-password']);
$ok($st===200,'new member logs in with own password');
$v=$db->prepare("SELECT verification_status FROM characters WHERE guild_id=? AND name='Neuling'");$v->execute([$g]);
$ok($v->fetchColumn()==='verified','character confirmed in game is verified');
$db->prepare("UPDATE guild_memberships SET status='disabled' WHERE guild_id=? AND user_id=?")->execute([$g,$member]);
[$st,$r]=$http('GET',"$base/api/v1/bridge/guilds/$g/state",$tok);
$ok($st===401,'disabled member token rejected');
[$st,$r]=$http('GET',"$base/api/v1/bridge/guilds/".($g+1000)."/state",$tok);
$ok($st===401,'token bound to its guild');
echo $fail?"FAILED ($fail)\n":"PASS: in-game orders over HTTP\n"; exit($fail?1:0);
