<?php
require __DIR__.'/../backend/src/ItemAppearance.php';
require __DIR__.'/../backend/src/ItemMetadata.php';
use Banklord\ItemAppearance as A;
use Banklord\ItemMetadata as M;
function same($a,$b):void {if($a!==$b)throw new RuntimeException('Mismatch '.var_export([$a,$b],true));}
same(M::iconUrl('inv_sword_01', 136235), 'https://wow.zamimg.com/images/wow/icons/medium/inv_sword_01.jpg');
same(M::iconUrl(null, 136235), null);
same(A::iconUrl('https://wow.zamimg.com/images/wow/icons/medium/136235.jpg'),null);
same(A::iconUrl('https://mop.warcraftdb.com/static/icons/38938.webp'),'https://mop.warcraftdb.com/static/icons/38938.webp');
same(A::iconUrl('/static/icons/38938.png'),'https://mop.warcraftdb.com/static/icons/38938.png');
same(A::iconUrl('inv_misc_herb_15'),'https://wow.zamimg.com/images/wow/icons/medium/inv_misc_herb_15.jpg');
foreach (['http://evil.com/abc.jpg','https://evil.com/abc.jpg','javascript:alert(1)','https://wow.zamimg.com/x.jpg?key=1','https://wow.zamimg.com/../secret.jpg','<img onerror=alert(1)>'] as $bad) same(A::iconUrl($bad),null);
$r=A::normalize(json_encode(['icon'=>'https://mop.warcraftdb.com/static/icons/38938.webp','quality'=>3]));
same($r['icon'],'https://mop.warcraftdb.com/static/icons/38938.webp');
$r=A::normalize(json_encode(['icon'=>'inv_enchant_shardbrilliantlarge','quality'=>'common']));
same($r['icon'],'https://wow.zamimg.com/images/wow/icons/medium/inv_enchant_shardbrilliantlarge.jpg');
if(A::normalize('{"icon":"https://wow.zamimg.com/images/wow/icons/medium/136235.jpg"}')!==null)throw new RuntimeException('Accepted numeric icon URL');
echo "PASS: PHP icon resolution/host validation/old numeric URL rejection\n";
