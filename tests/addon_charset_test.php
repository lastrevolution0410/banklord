<?php
// WoW's standard fonts only cover Latin-1 plus the Windows-1252 extras; anything
// else (e.g. "→") shows as an empty box in game.
declare(strict_types=1);
$extra='€‚ƒ„…†‡ˆ‰Š‹ŒŽ‘’“”•–—˜™š›œžŸ';
$allowed=[];foreach (mb_str_split($extra) as $c) $allowed[$c]=true;
$bad=[];
foreach (glob(__DIR__.'/../Banklord/*.lua') as $f) foreach (file($f) as $n=>$line) {
    if (preg_match('/^\s*--/',$line)) continue;
    foreach (mb_str_split($line) as $c) if (mb_ord($c)>0xFF && !isset($allowed[$c])) $bad[]=basename($f).':'.($n+1).' '.$c;
}
if ($bad) { echo "FAIL: characters the WoW font cannot show: ".implode(', ',array_slice($bad,0,10))."\n"; exit(1); }
echo "PASS: addon texts only use characters the WoW font can show\n";
