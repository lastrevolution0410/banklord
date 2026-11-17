<?php
declare(strict_types=1);

namespace Banklord;

use PDO;

/**
 * Keeps a self-hosted website up to date from the official signed release channel.
 *
 * The website fetches the signed manifest from UPDATE_SOURCE, verifies it with the
 * built-in release key, downloads the web payload, checks its SHA-256 and applies it
 * through WebUpdater (database and file backup first). Desktop apps keep updating from
 * the official channel directly, so nothing else is mirrored here.
 *
 * Config: UPDATE_SOURCE = manifest URL, or "off". UPDATE_AUTO = "off" disables the
 * automatic check (the admin button keeps working).
 */
final class UpstreamUpdater {
    public const DEFAULT_SOURCE = 'https://wow-bank.mgc-nom.net/updates/preview/manifest.json';
    private const INTERVAL = 6 * 3600;

    public function __construct(private readonly PDO $db, private readonly string $root) {}

    public static function source(): ?string {
        $s = trim((string)(getenv('UPDATE_SOURCE') ?: self::DEFAULT_SOURCE));
        if ($s === '' || strtolower($s) === 'off') return null;
        if (!preg_match('~^https://([a-z0-9.-]{1,253}(?::[0-9]{1,5})?)/[A-Za-z0-9/._-]{1,200}$~D', $s)) throw new DomainError('UPDATE_SOURCE_INVALID');
        return $s;
    }

    /** The official server publishes releases itself and never pulls. */
    public static function isPublisher(): bool {
        $s = self::source();
        return $s === null || strtolower((string)parse_url($s, PHP_URL_HOST)) === explode(':', ReleasePublisher::updateHost())[0];
    }

    private function statusFile(): string { return $this->root.'/private/upstream-status.json'; }

    public function status(): array {
        $raw = @file_get_contents($this->statusFile());
        $st = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        return $st + ['source' => self::source(), 'publisher' => self::isPublisher(),
            'auto' => strtolower((string)getenv('UPDATE_AUTO')) !== 'off', 'installed' => (new WebUpdater($this->db, $this->root))->installedVersion()];
    }

    private function save(array $st): void {
        @mkdir(dirname($this->statusFile()), 0700, true);
        @file_put_contents($this->statusFile(), json_encode($st), LOCK_EX);
    }

    /** @var null|callable(string,int):string Test hook replacing the HTTPS download. */
    public static $fetcher = null;

    private static function fetch(string $url, int $max): string {
        if (self::$fetcher !== null) return (self::$fetcher)($url, $max);
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 0, 'header' => "User-Agent: Banklord-Updater\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $h = @fopen($url, 'rb', false, $ctx);
        if ($h === false) throw new DomainError('UPSTREAM_UNREACHABLE');
        $data = stream_get_contents($h, $max + 1);
        fclose($h);
        if (!is_string($data) || $data === '') throw new DomainError('UPSTREAM_UNREACHABLE');
        if (strlen($data) > $max) throw new DomainError('UPSTREAM_TOO_LARGE');
        return $data;
    }

    /** Checks the official channel and installs a newer website version. */
    public function run(): array {
        $source = self::source();
        if ($source === null || self::isPublisher()) return ['checked' => false, 'reason' => 'publisher-or-disabled'];
        $st = ['checked_at' => gmdate('Y-m-d H:i:s'), 'ok' => false];
        try {
            $manifest = self::fetch($source, 16384);
            [$p] = ReleasePublisher::verify($manifest, (string)parse_url($source, PHP_URL_HOST));
            $web = new WebUpdater($this->db, $this->root);
            $st['latest'] = $p['web']['version'];
            $plain = static fn(string $v): string => (string)preg_replace('/-.*$/', '', $v);
            if (!version_compare($plain($p['web']['version']), $plain($web->installedVersion()), '>')) {
                $st['ok'] = true; $st['result'] = 'up-to-date';
            } else {
                $zip = self::fetch($p['web']['url'], 5 << 20);
                if (!hash_equals($p['web']['sha256'], hash('sha256', $zip))) throw new DomainError('UPSTREAM_HASH_MISMATCH');
                $tmp = $this->root.'/private/upstream-web-'.bin2hex(random_bytes(6)).'.zip';
                if (file_put_contents($tmp, $zip) !== strlen($zip)) throw new DomainError('RELEASE_STORAGE');
                try { $res = $web->apply(WebUpdater::readPayload($tmp), $p['web']['version']); }
                finally { @unlink($tmp); }
                $st['ok'] = true; $st['result'] = 'installed'; $st['installed_now'] = $p['web']['version'];
                $st['backup'] = $res['backup'] ?? null;
            }
        } catch (DomainError $e) {
            $st['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $st['error'] = 'UPSTREAM_FAILED';
        }
        $this->save($st);
        return $st;
    }

    /** Runs at most every six hours, after the response has been sent. */
    public static function autoAfterResponse(PDO $db, string $root): void {
        try {
            if (strtolower((string)getenv('UPDATE_AUTO')) === 'off' || self::isPublisher()) return;
            $self = new self($db, $root);
            $last = strtotime((string)($self->status()['checked_at'] ?? '').' UTC') ?: 0;
            if (time() - $last < self::INTERVAL) return;
            $lock = @fopen($root.'/private/upstream.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) return;
            $self->save(['checked_at' => gmdate('Y-m-d H:i:s'), 'ok' => false, 'result' => 'running']);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            ignore_user_abort(true);
            @set_time_limit(300);
            $self->run();
            flock($lock, LOCK_UN);
        } catch (\Throwable $e) {
            // Never break a normal request because of the background check.
        }
    }
}
