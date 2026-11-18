<?php
// A self-hosted guild website forwards reports to the official inbox and fetches the status.
declare(strict_types=1);
putenv('UPDATE_SOURCE=https://central.example.org/updates/preview/manifest.json');
foreach (['Config','Domain','Database','SavedVariablesParser','ReleasePublisher','UpstreamUpdater','Feedback'] as $c) require_once __DIR__."/../backend/src/$c.php";
use Banklord\{Database,Feedback};
$db=Database::connect();$fail=0;$ok=function(bool $c,string $m) use(&$fail){ if(!$c){$fail++;echo "FAIL: $m\n";} };
$db->exec("INSERT INTO guilds (name,realm) VALUES ('Forward Guild','Everlook')");$g=(int)$db->lastInsertId();
$db->prepare("INSERT INTO users (email,password_hash,display_name) VALUES (?,?,?)")->execute(["fw$g@private.example",'x','Geheim']);$u=(int)$db->lastInsertId();
$sent=[];$receipt=str_repeat('ab',32);$down=false;
Feedback::$transport=function(string $url,array $payload) use(&$sent,$receipt,&$down){
  if($down) throw new Banklord\DomainError('FEEDBACK_UNREACHABLE');
  $sent[]=[$url,$payload];
  return str_ends_with($url,'/submit')?['id'=>77,'receipt'=>$receipt]:['reports'=>[$receipt=>['status'=>'done','reply'=>'Behoben in 1.3.']]];
};
$ok(Feedback::centralUrl()==='https://central.example.org/api/v1/feedback','central address from the update channel');
$r=(new Feedback($db))->submit($g,$u,['kind'=>'bug','title'=>'Absturz beim Öffnen','text'=>'Das Fenster stürzt beim Öffnen ab.','contact'=>''],'web',null,'https://gilde.example.org');
$ok(($r['number']??0)===77 && count($sent)===1 && $sent[0][0]==='https://central.example.org/api/v1/feedback/submit','report forwarded');
$json=json_encode($sent[0][1]);
$ok(!str_contains($json,'private.example') && !str_contains($json,'Geheim') && !str_contains($json,'Forward Guild'),'no personal or guild data forwarded');
$db->prepare("UPDATE feedback_reports SET checked_at=NULL WHERE guild_id=?")->execute([$g]);
$list=(new Feedback($db))->mine($g,$u,'https://gilde.example.org');
$ok(($list[0]['status']??'')==='done' && ($list[0]['reply']??'')==='Behoben in 1.3.','status and answer fetched');
$down=true;
try{(new Feedback($db))->submit($g,$u,['kind'=>'idea','title'=>'Noch eine Idee','text'=>'Etwas Neues für alle.'],'web',null,'https://gilde.example.org');$ok(false,'web submit while central is down must fail');}
catch(Banklord\DomainError $e){$ok($e->getMessage()==='FEEDBACK_UNREACHABLE','member sees that the project is unreachable');}
$c=$db->prepare('SELECT COUNT(*) FROM feedback_reports WHERE guild_id=?');$c->execute([$g]);
$ok((int)$c->fetchColumn()===1,'failed web report not kept');
$r=(new Feedback($db))->submit($g,$u,['kind'=>'idea','title'=>'Aus dem Spiel','text'=>'Gesendet aus dem Spiel heraus.'],'ingame','fb-1700000000-zzz999','https://gilde.example.org');
$ok($r['status']==='pending','in-game report waits while central is down');
$down=false;
$r=(new Feedback($db))->submit($g,$u,['kind'=>'idea','title'=>'Aus dem Spiel','text'=>'Gesendet aus dem Spiel heraus.'],'ingame','fb-1700000000-zzz999','https://gilde.example.org');
$ok(($r['number']??0)===77,'in-game report sent on the next upload');
echo $fail?"FAILED ($fail)\n":"PASS: guild website forwards feedback to the official inbox\n";exit($fail?1:0);
