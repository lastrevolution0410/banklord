<?php
/** Deterministic import-clock regression: no DB connection required. */
declare(strict_types=1);
require __DIR__.'/../backend/src/SavedVariablesParser.php';
require __DIR__.'/../backend/src/ItemMetadata.php';
require __DIR__.'/../backend/src/ScanImport.php';
use Banklord\ScanImport;
$now=2000000000;
$key='item:12345:0:0:0:0:0:0:0:0';
$entry=['slot'=>1,'itemID'=>12345,'count'=>10,'itemKey'=>$key,'itemLink'=>'|cffaaaaaa|H'.$key.'|h[Testgegenstand]|h|r'];
$scan=[
 'formatVersion'=>2,'source'=>'mop-classic-guildbank','guild'=>'Testgilde','realm'=>'Everlook',
 'mode'=>'all','quality'=>'unverified','serverConfirmed'=>false,'capturedAt'=>$now-2,
 'totalTabs'=>2,'eligibleTabs'=>2,'scannedTabs'=>2,'skippedTabs'=>0,'failedTabs'=>0,
 'tabs'=>[
  1=>['tab'=>1,'tabName'=>'Eins','status'=>'captured','quality'=>'unverified','expectedSlots'=>98,'capturedAt'=>$now-180,'items'=>[$entry]],
  2=>['tab'=>2,'tabName'=>'Zwei','status'=>'captured','quality'=>'unverified','expectedSlots'=>98,'capturedAt'=>$now-3,'items'=>[]],
 ],
 'errors'=>[],'occupiedSlots'=>1,'totalItemCount'=>10,'items'=>[['itemKey'=>$key,'count'=>10]],
];
$first=ScanImport::normalize($scan,'Testgilde','Everlook',$now);
assert($first['observed_at']===$now-180);
assert(count($first['lines'])===1);
$scan['tabs'][1]['capturedAt']=$now-8;
$second=ScanImport::normalize($scan,'Testgilde','Everlook',$now);
assert($second['observed_at']===$now-8);
assert($second['source_key']===$first['source_key']); // identical scan-content fingerprint
$scan['quality']='partial';
try {ScanImport::normalize($scan,'Testgilde','Everlook',$now);throw new Exception('Partial scan allowed');}
catch (InvalidArgumentException $e) {if($e->getMessage()!=='IMPORT_COMPLETE_FULL_SCAN_REQUIRED')throw $e;}
echo "Scanimport-Zeitschranke / unvollstaendige Scans: OK\n";
$scan['quality']='unverified';
$scan['scanStartedAt']=$now-220;
$third=ScanImport::normalize($scan,'Testgilde','Everlook',$now);
if($third['observed_at']!==$now-220)throw new Exception('Scan start time ignored');
$scan['scanStartedAt']=$now+20;
try {ScanImport::normalize($scan,'Testgilde','Everlook',$now);throw new Exception('Future scan start accepted');}
catch (InvalidArgumentException $e) {if($e->getMessage()!=='IMPORT_SCAN_START_RANGE')throw $e;}
echo "Autoscanner-Zeitschrankentest: OK\n";
