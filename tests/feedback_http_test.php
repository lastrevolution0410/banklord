<?php
// Help & feedback over HTTP: web and in-game reports, central inbox, status for the reporter.
declare(strict_types=1);
foreach (['Config','Domain','Database','BridgeTokens'] as $c) require __DIR__."/../backend/src/$c.php";
use Banklord\{Database,BridgeTokens};
$db=Database::connect(); $fail=0; $base=getenv('BANKLORD_TEST_BASE')?:'http://127.0.0.1:8080';
$ok=function(bool $c,string $m) use(&$fail){ if(!$c){$fail++; echo "FAIL: $m\n";} };
$db->exec("INSERT INTO guilds (name,realm) VALUES ('Feedback Guild','Everlook')"); $g=(int)$db->lastInsertId();
$mk=function(string $role,string $pw) use($db,$g){ static $n=0;$n++;
  $db->prepare("INSERT INTO users (email,password_hash,display_name) VALUES (?,?,?)")->execute(["fb$g-$n@x.invalid",password_hash($pw,PASSWORD_DEFAULT),"F$n"]); $u=(int)$db->lastInsertId();
  $db->prepare("INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,?)")->execute([$g,$u,$role]); return [$u,"fb$g-$n@x.invalid"]; };
[$member,$memberMail]=$mk('member','member-password-1');[$admin,$adminMail]=$mk('admin','admin-password-12');
$session=function() use($base){ $jar='';$csrf='';
  return function(string $method,string $path,?array $body=null) use(&$jar,&$csrf,$base){
    $h="Content-Type: application/json\r\n".($jar?"Cookie: $jar\r\n":'').($csrf?"X-CSRF-Token: $csrf\r\n":'');
    $raw=file_get_contents($base.'/api/v1'.$path,false,stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'header'=>$h,'content'=>$body===null?'':json_encode($body)]]));
    foreach($http_response_header as $line) if(preg_match('/^Set-Cookie: ([^;]+)/i',$line,$m)) $jar=$m[1];
    preg_match('~ (\d{3}) ~',$http_response_header[0],$m);$j=json_decode((string)$raw,true);if(isset($j['csrf']))$csrf=$j['csrf'];return [(int)$m[1],$j];}; };
$m=$session();$m('GET','/session');$m('POST','/login',['email'=>$memberMail,'password'=>'member-password-1']);
$a=$session();$a('GET','/session');$a('POST','/login',['email'=>$adminMail,'password'=>'admin-password-12']);

// Web: validation per kind.
[$st,$r]=$m('POST',"/guilds/$g/feedback",['kind'=>'bug','title'=>'x','text'=>'kurz']);
$ok($st===422 && ($r['error']??'')==='FEEDBACK_TITLE_REQUIRED','too short report rejected ('.$st.')');
[$st,$r]=$m('POST',"/guilds/$g/feedback",['kind'=>'language','title'=>'','text'=>'Bitte auf Französisch','language'=>'']);
$ok(($r['error']??'')==='FEEDBACK_LANGUAGE_REQUIRED','language request needs a language');
[$st,$r]=$m('POST',"/guilds/$g/feedback",['kind'=>'bug','title'=>'Warenkorb leer','text'=>"Nach dem Bestellen\nist der Warenkorb leer.",'area'=>'web','steps'=>'1. bestellen','expected'=>'Bestätigung','contact'=>'']);
$ok($st===201 && is_int($r['report']['number']??null) && ($r['report']['status']??'')==='new','web bug report accepted '.json_encode($r));
$number=$r['report']['number']??0;
[$st,$r]=$m('POST',"/guilds/$g/feedback",['kind'=>'language','text'=>'Wir haben viele französische Spieler.','language'=>'Français','help'=>'yes']);
$ok($st===201 && ($r['report']['title']??'')==='Sprache: Français','language request gets a title');

// In game: outbox row from the addon, uploaded by the companion; idempotent.
$tok=(new BridgeTokens($db))->issueMemberOrders($g,$member,'Test-PC')['token'];
$now=time();$key="fb-$now-abc123";
$sv="BanklordDB = {[\"feedbackOutbox\"]={{[\"key\"]=\"$key\",[\"kind\"]=\"idea\",[\"title\"]=\"Sortieren nach Fach\",[\"text\"]=\"Im Spiel nach Bankfach sortieren können.\",[\"area\"]=\"addon\",[\"addon\"]=\"0.9.0\",[\"build\"]=\"5.5.4\",[\"lang\"]=\"deDE\"}}}";
$up=function() use($base,$g,$tok,$sv){
  $raw=file_get_contents("$base/api/v1/bridge/guilds/$g/imports",false,stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'content'=>$sv,
    'header'=>"Authorization: Bearer $tok\r\nContent-Type: text/plain; charset=utf-8\r\n"]]));return json_decode((string)$raw,true);};
$r=$up();
$ok(($r['ingame_state']['feedback_results'][$key]['status']??'')==='new','in-game report forwarded '.json_encode($r['ingame_state']['feedback_results']??$r));
$r=$up();
$c=$db->prepare('SELECT COUNT(*) FROM feedback_reports WHERE guild_id=? AND client_key=?');$c->execute([$g,$key]);
$ok((int)$c->fetchColumn()===1,'re-upload does not duplicate');
$fb=$r['ingame_state']['feedback']??[];
$ok(count($fb)===3 && ($fb[0]['key']??'')===$key,'addon sees own reports');

// Inbox: only the operator of the site that receives reports; guild admins and members never.
[$st]=$m('GET',"/guilds/$g/feedback/inbox");
$ok($st===403,'members have no inbox');
[$st]=$a('GET',"/guilds/$g/feedback/inbox");
$ok($st===403,'a guild admin alone has no inbox (only the site operator)');
$db->prepare('UPDATE users SET is_operator=1 WHERE id=?')->execute([$admin]);
[$st,$r]=$a('GET',"/guilds/$g/feedback/inbox?kind=bug");
$item=null;foreach($r['items']??[] as $i) if($i['id']===$number) $item=$i;
$ok($st===200 && $item && $item['details']['steps']==='1. bestellen' && $item['meta']['source']==='web' && !isset($item['meta']['email']),'admin sees report without personal data');
[$st]=$a('PATCH',"/guilds/$g/feedback/inbox/$number",['status'=>'planned','reply'=>'Kommt in der nächsten Version.']);
$ok($st===200,'admin answers');
[$st,$r]=$m('GET',"/guilds/$g/feedback");
$mine=null;foreach($r['mine']??[] as $x) if(($x['number']??0)===$number) $mine=$x;
$ok($mine && $mine['status']==='planned' && $mine['reply']==='Kommt in der nächsten Version.' && ($r['inbox']??true)===false,'reporter sees status and answer');

// Central endpoint used by other guild websites.
$raw=file_get_contents("$base/api/v1/feedback/submit",false,stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,
  'header'=>"Content-Type: application/json\r\n",'content'=>json_encode(['report'=>['kind'=>'idea','title'=>'Von außen','text'=>'Weitergeleitet von einer Gilde.'],'site'=>'https://gilde.example.org'])]]));
$j=json_decode((string)$raw,true);
$ok(is_int($j['id']??null) && preg_match('/^[a-f0-9]{64}$/',$j['receipt']??''),'central inbox accepts forwarded report');
$raw=file_get_contents("$base/api/v1/feedback/status",false,stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,
  'header'=>"Content-Type: application/json\r\n",'content'=>json_encode(['receipts'=>[$j['receipt']??'',str_repeat('0',64)]])]]));
$s=json_decode((string)$raw,true)['reports']??[];
$ok(count($s)===1 && ($s[$j['receipt']]['status']??'')==='new','status only for known receipts');
echo $fail?"FAILED ($fail)\n":"PASS: help & feedback (web, in game, inbox, status)\n"; exit($fail?1:0);
