<?php
// Public read-only demo over the real HTTP API (dev/docker-compose.yml).
declare(strict_types=1);
foreach (['Config','Domain','Database','StockReconciliation','Orders','OrderRules','Community','Demo'] as $c) require_once __DIR__."/../backend/src/$c.php";
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
$demo=new Banklord\Demo($db,__DIR__.'/../backend');

$demo->setEnabled(false);
$v=$client(); $v('GET','/session');
[$st,$r]=$v('GET','/demo'); $ok($st===200 && $r['enabled']===false,'demo reported off');
[$st,$r]=$v('POST','/demo/start',[]); $ok($st>=400,"demo cannot start while off ($st)");

$demo->setEnabled(true);
$g=$demo->guildId();
$ok(is_int($g),'demo guild seeded');
[$st,$r]=$v('POST','/demo/start',[]); $ok($st===200,"demo starts ($st)");
[$st,$r]=$v('GET','/session'); $ok(($r['demo']??false)===true && $r['user_id'],'session is a demo session');
[$st,$r]=$v('GET','/guilds'); $ids=array_column($r['guilds']??[],'id');
$ok($st===200 && $ids===[$g] || array_map('intval',$ids)===[$g],'demo sees only the demo guild: '.json_encode($ids));
[$st,$r]=$v('GET',"/guilds/$g/items"); $ok($st===200 && count($r['items']??[])>=10,"catalog readable ($st, ".count($r['items']??[]).")");
[$st,$r]=$v('GET',"/guilds/$g/orders"); $ok($st===200 && count($r['orders']??[])>=3,"orders readable ($st)");
[$st,$r]=$v('POST',"/guilds/$g/orders",['client_key'=>'demo-test-0001','lines'=>[]]); $ok($st===403 && ($r['error']??'')==='DEMO_READ_ONLY',"changes refused ($st)");
[$st,$r]=$v('POST',"/guilds/$g/invitations",['role'=>'admin']); $ok($st===403,'no invitations in demo');
[$st,$r]=$v('GET',"/guilds/$g/release-tokens"); $ok($st===403 && ($r['error']??'')==='DEMO_HIDDEN','release data hidden');
[$st,$r]=$v('GET',"/guilds/$g/demo-setting"); $ok($st===403,'demo cannot switch the demo');
$other=(int)$db->query("SELECT id FROM guilds WHERE id<>$g ORDER BY id LIMIT 1")->fetchColumn();
if ($other) { [$st]=$v('GET',"/guilds/$other/items"); $ok($st===403,"real guild not readable from demo ($st)"); }
[$st]=$v('POST','/logout',[]); $ok($st===200,'leaving the demo works');
[$st,$r]=$v('GET','/session'); $ok(empty($r['user_id']) && empty($r['demo']),'logged out after demo');

$demo->setEnabled(false);
echo $fail ? "FAIL: demo ($fail)\n" : "PASS: public demo is read-only and isolated\n";
