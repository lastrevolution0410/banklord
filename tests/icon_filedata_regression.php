<?php
declare(strict_types=1);
require __DIR__.'/../backend/src/IconMap.php';
require __DIR__.'/../backend/src/ItemMetadata.php';
use Banklord\{IconMap,ItemMetadata};
$fail=0; $check=function(bool $ok,string $msg) use (&$fail){ if(!$ok){$fail++;echo "FAIL: $msg\n";} };
$check(IconMap::name(134327)==='inv_misc_note_01','134327 -> inv_misc_note_01');
$check(IconMap::name('136244')==='trade_engraving','string id resolves');
$check(IconMap::name(0)===null && IconMap::name(-5)===null && IconMap::name('abc')===null,'invalid ids');
$check(IconMap::name(999999999)===null,'unknown id');
$check(IconMap::name(136235)===null,'placeholder temp ignored');
$check(ItemMetadata::iconUrl('inv_sword_01',136235)==='https://wow.zamimg.com/images/wow/icons/medium/inv_sword_01.jpg','placeholder falls back to name');
$check(ItemMetadata::iconUrl('inv_sword_04',134327)==='https://wow.zamimg.com/images/wow/icons/medium/inv_misc_note_01.jpg','file id wins over name');
$check(ItemMetadata::iconUrl('INV_Sword_04',null)==='https://wow.zamimg.com/images/wow/icons/medium/inv_sword_04.jpg','name fallback');
$check(ItemMetadata::iconUrl('../x',null)===null && ItemMetadata::iconUrl(null,null)===null,'rejects unsafe');
echo $fail ? "FAILED ($fail)\n" : "PASS: FileDataID icon map\n"; exit($fail?1:0);
