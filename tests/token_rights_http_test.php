<?php
// Session-level rights for bridge tokens (dev/docker-compose.yml).
declare(strict_types=1);
foreach (['Config','Domain','Database'] as $c) require __DIR__."/../backend/src/$c.php";
$db=Banklord\Database::connect(); $fail=0; $base=getenv('BANKLORD_TEST_BASE')?:'http://127.0.0.1:8080';
$ok=function(bool $c,string $m) use(&$fail){ if(!$c){$fail++; echo "FAIL: $m\n";} };
$client=function() use($base){ $jar=''; $csrf='';
  return function(string $method,string $path,?array $body=null) use(&$jar,&$csrf,$base){
    $h="Content-Type: application/json\r\nAccept: application/json\r\n".($jar?"Cookie: $jar\r\n":'').($csrf?"X-CSRF-Token: $csrf\r\n":'');
    $ctx=stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'header'=>$h,'content'=>$body===null?'':json_encode($body)]]);
    $raw=file_get_contents($base.'/api/v1'.$path,false,$ctx);
    foreach($http_response_header as $line) if(preg_match('/^Set-Cookie: ([^;]+)/i',$line,$m)) $jar=$m[1];
    preg_match('~ (\d{3}) ~',$http_response_header[0],$m); $j=json_decode((string)$raw,true);
    if(isset($j['csrf'])) $csrf=$j['csrf']; return [(int)$m[1],$j]; }; };
$db->exec("INSERT INTO guilds (name,realm) VALUES ('Rechte','Everlook')"); $g=(int)$db->lastInsertId();
$users=[];
foreach (['member','bank_manager'] as $role) {
  $mail="rights$g-$role@x.invalid";
  $db->prepare("INSERT INTO users (email,password_hash,display_name) VALUES (?,?,?)")->execute([$mail,password_hash('test-password-123',PASSWORD_DEFAULT),$role]);
  $u=(int)$db->lastInsertId(); $db->prepare("INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,?)")->execute([$g,$u,$role]);
  $c=$client(); $c('GET','/session'); [$st]=$c('POST','/login',['email'=>$mail,'password'=>'test-password-123']);
  $ok($st===200,"login $role ($st)"); $users[$role]=$c;
}
$m=$users['member']; $b=$users['bank_manager'];
[$st,$r]=$m('POST',"/guilds/$g/bridge-tokens",['label'=>'PC']);
$ok($st===403,"member cannot create scan token ($st)");
[$st,$r]=$m('POST',"/guilds/$g/bridge-tokens",['label'=>'PC','scope'=>'member.orders']);
$ok($st===201 && ($r['credential']['scope']??'')==='member.orders',"member creates personal token ($st)");
$memberTokenId=$r['credential']['id']??0;
[$st,$r]=$b('POST',"/guilds/$g/bridge-tokens",['label'=>'Bank-PC']);
$ok($st===201 && ($r['credential']['scope']??'')==='scan.stage',"manager creates scan token ($st)");
$scanId=$r['credential']['id']??0;
[$st,$r]=$m('GET',"/guilds/$g/bridge-tokens");
$ids=array_column($r['tokens']??[],'id');
$ok($st===200 && $ids===[$memberTokenId],'member sees only own token');
[$st,$r]=$m('DELETE',"/guilds/$g/bridge-tokens/$scanId");
$ok(($r['revoked']??null)===false,'member cannot revoke manager token');
[$st,$r]=$m('DELETE',"/guilds/$g/bridge-tokens/$memberTokenId");
$ok(($r['revoked']??null)===true,'member revokes own token');
[$st,$r]=$m('POST',"/guilds/$g/bridge-tokens",['label'=>'A','scope'=>'member.orders']);
[$st,$r]=$m('POST',"/guilds/$g/bridge-tokens",['label'=>'B','scope'=>'member.orders']);
[$st,$r]=$m('POST',"/guilds/$g/bridge-tokens",['label'=>'C','scope'=>'member.orders']);
$ok($st===409,"personal token limit ($st)");
// One account, several guilds: a signed-in member redeems an invitation of another guild.
$db->exec("INSERT INTO guilds (name,realm) VALUES ('Zweitgilde','Everlook')"); $g2=(int)$db->lastInsertId();
$inv=function(string $role) use($db,$g2){ $t='wgi1_'.bin2hex(random_bytes(32));
  $db->prepare('INSERT INTO guild_invitations (guild_id,created_by,token_hash,role,expires_at) VALUES (?,(SELECT MIN(id) FROM users),?,?,UTC_TIMESTAMP()+INTERVAL 48 HOUR)')->execute([$g2,hash('sha256',$t),$role]); return $t; };
$t1=$inv('bank_manager');
[$st,$r]=$m('POST','/invitations/redeem',['invitation'=>$t1]);
$ok($st===200 && ($r['guild']['guild_id']??0)===$g2 && ($r['guild']['name']??'')==='Zweitgilde','member joins a second guild ('.$st.' '.json_encode($r).')');
[$st,$r]=$m('GET','/guilds');$roles=[];foreach($r['guilds']??[] as $x)$roles[(int)$x['id']]=$x['role'];
$ok(($roles[$g]??'')==='member' && ($roles[$g2]??'')==='bank_manager','role per guild after joining '.json_encode($roles));
[$st,$r]=$m('POST','/invitations/redeem',['invitation'=>$t1]);
$ok(($r['error']??'')==='INVITE_EXPIRED_OR_USED','invitation works only once ('.$st.')');
$t2=$inv('member');[$st,$r]=$m('POST','/invitations/redeem',['invitation'=>$t2]);
$ok(($r['error']??'')==='ALREADY_MEMBER','already a member ('.$st.')');
$left=$db->prepare('SELECT redeemed_at FROM guild_invitations WHERE token_hash=?');$left->execute([hash('sha256',$t2)]);
$ok($left->fetchColumn()===null,'unused invitation stays valid when already a member');
$db->prepare("UPDATE guild_memberships SET status='disabled' WHERE guild_id=? AND user_id=(SELECT id FROM users WHERE email=?)")->execute([$g2,"rights$g-member@x.invalid"]);
[$st,$r]=$m('POST','/invitations/redeem',['invitation'=>$t2]);
$ok(($r['error']??'')==='MEMBERSHIP_DISABLED','a disabled member cannot re-enable himself ('.$st.')');
[$st,$r]=$m('POST','/invitations/redeem',['invitation'=>'quatsch']);
$ok(($r['error']??'')==='INVITE_EXPIRED_OR_USED','bad code rejected ('.$st.')');
$anon=$client();$anon('GET','/session');[$st]=$anon('POST','/invitations/redeem',['invitation'=>$t2]);
$ok($st===401,'not signed in: no joining ('.$st.')');
echo $fail?"FAILED ($fail)\n":"PASS: token rights over HTTP session\n"; exit($fail?1:0);
