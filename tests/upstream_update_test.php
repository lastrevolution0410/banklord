<?php
// Self-hosted website pulls a signed release from the official channel.
// Uses the locally built and signed release in dist/ (skipped when absent, e.g. in CI).
declare(strict_types=1);
foreach (['Config','Domain','Database','WebUpdater','ReleasePublisher','UpstreamUpdater'] as $c) require __DIR__."/../backend/src/$c.php";
use Banklord\{Database,UpstreamUpdater,WebUpdater};

$dist = glob(__DIR__.'/../dist/Banklord-*/07_SIGNIERT/manifest.json');
if (!$dist) { echo "SKIP: upstream update (no signed release in dist/)\n"; return; }
$manifestFile = end($dist);
$manifest = file_get_contents($manifestFile);
$p = json_decode(base64_decode(json_decode($manifest, true)['payload']), true);
$zip = file_get_contents(dirname($manifestFile).'/files/'.$p['web']['sha256'].'.zip');
// The release in dist/ may be signed for any update host; point the source there.
$src = 'https://'.parse_url($p['web']['url'], PHP_URL_HOST).'/updates/preview/manifest.json';
putenv('UPDATE_SOURCE='.$src);

$fail = 0;
$ok = function (bool $c, string $m) use (&$fail) { if (!$c) { $fail++; echo "FAIL: $m\n"; } };
$root = sys_get_temp_dir().'/bl-upstream-'.bin2hex(random_bytes(4));
mkdir($root.'/private', 0700, true);
foreach (['public', 'src', 'sql', 'bin'] as $d) { mkdir("$root/$d"); foreach (glob(__DIR__."/../backend/$d/*") as $f) if (is_file($f)) copy($f, "$root/$d/".basename($f)); }
$setVersion = fn(string $v) => file_put_contents("$root/private/upgrade-version.json", json_encode(['version' => $v]));
$setVersion('0.1.0');
$db = Database::connect();
$served = ['manifest' => $manifest, 'zip' => $zip];
UpstreamUpdater::$fetcher = function (string $url, int $max) use (&$served, $p): string {
    if ($url === getenv('UPDATE_SOURCE')) return $served['manifest'];
    if ($url === $p['web']['url']) return $served['zip'];
    throw new Banklord\DomainError('UPSTREAM_UNREACHABLE');
};

putenv('UPDATE_HOST='.parse_url($src, PHP_URL_HOST));
$ok(UpstreamUpdater::isPublisher(), 'official server is the publisher and never pulls');
$ok(((new UpstreamUpdater($db, $root))->run()['checked'] ?? null) === false, 'publisher run is a no-op');

putenv('UPDATE_HOST=guild.example.org');
$up = new UpstreamUpdater($db, $root);
$r = $up->run();
$ok(($r['result'] ?? '') === 'installed' && (new WebUpdater($db, $root))->installedVersion() === $p['web']['version'], 'newer version installed: '.json_encode($r));
$ok(count(glob("$root/private/upgrades/backup-*/db-preupgrade.sql.gz")) === 1, 'database backup written before install');
$r = $up->run();
$ok(($r['result'] ?? '') === 'up-to-date', 'second run reports up to date');

$setVersion('0.1.0');
$served['zip'] = $zip.'x';
$r = $up->run();
$ok(($r['error'] ?? '') === 'UPSTREAM_HASH_MISMATCH' && (new WebUpdater($db, $root))->installedVersion() === '0.1.0', 'tampered download rejected');

putenv('UPDATE_SOURCE='.$src);
$served['zip'] = $zip;
$env = json_decode($manifest, true);
$env['payload'] = base64_encode(str_replace('"web"', '"web" ', base64_decode($env['payload'])));
$served['manifest'] = json_encode($env);
$r = $up->run();
$ok(($r['error'] ?? '') === 'MANIFEST_SIGNATURE_INVALID', 'tampered manifest rejected');

putenv('UPDATE_SOURCE=off');
$ok(((new UpstreamUpdater($db, $root))->run()['checked'] ?? null) === false, 'UPDATE_SOURCE=off disables pulling');
putenv('UPDATE_SOURCE'); putenv('UPDATE_HOST');

exec('rm -rf '.escapeshellarg($root));
echo $fail ? "FAIL: upstream update ($fail)\n" : "PASS: self-hosted website updates itself from the signed official channel\n";
