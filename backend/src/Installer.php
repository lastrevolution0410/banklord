<?php
declare(strict_types=1);

namespace Banklord;

use PDO;

/**
 * Browser setup for a fresh self-hosted website. Active only while no
 * configuration exists; it creates the tables, the first guild and admin,
 * and writes private/config.local.php. Afterwards it is unreachable.
 */
final class Installer {
    private const MIN_PASSWORD = 14;
    private const EN = [
        '<html lang="de">' => '<html lang="en">',
        'Banklord einrichten' => 'Set up Banklord',
        'Diese Seite erscheint nur, solange Banklord noch nicht eingerichtet ist. Du brauchst eine leere MySQL- oder MariaDB-Datenbank. Die Zugangsdaten findest du im Kundenmenü deines Webhosters.' => 'This page only appears until Banklord is set up. You need an empty MySQL or MariaDB database. Your web host shows its access data in the customer menu.',
        'Voraussetzungen' => 'Requirements',
        'PHP 8.2 oder neuer' => 'PHP 8.2 or newer',
        'PHP-Erweiterung sodium (signierte Updates)' => 'PHP extension sodium (signed updates)',
        'PHP-Erweiterung zip (Updates)' => 'PHP extension zip (updates)',
        'PHP-Erweiterung pdo_mysql' => 'PHP extension pdo_mysql',
        'Ordner private/ beschreibbar' => 'Folder private/ writable',
        'HTTPS aktiv' => 'HTTPS active',
        '1 · Datenbank' => '1 · Database',
        'Datenbankname' => 'Database name',
        '>Benutzer<' => '>User<',
        '>Passwort<' => '>Password<',
        '2 · Gilde' => '2 · Guild',
        'Gildenname' => 'Guild name',
        '3 · Dein Admin-Konto' => '3 · Your admin account',
        'E-Mail (Anmeldename)' => 'Email (used to sign in)',
        'Anzeigename' => 'Display name',
        'Passwort (mindestens ' => 'Password (at least ',
        ' Zeichen)' => ' characters)',
        'Passwort wiederholen' => 'Repeat password',
        '<strong>Fertig!</strong> Datenbank, Gilde und Admin-Konto sind angelegt.' => '<strong>Done!</strong> Database, guild and admin account are set up.',
        'Melde dich jetzt mit deiner E-Mail und deinem Passwort an. Unter <em>Verwaltung → Mitglieder</em> lädst du deine Gilde ein.' => 'Now sign in with your email and password. Invite your guild under <em>Management → Members</em>.',
        'Updates spielt die Website automatisch ein (alle 6 Stunden, mit Backup). Diese Einrichtungsseite ist ab jetzt gesperrt.' => 'The website installs updates by itself (every 6 hours, with a backup). This setup page is now locked.',
        'Zur Anmeldung' => 'Go to sign-in',
        'Formular abgelaufen, bitte neu laden.' => 'Form expired, please reload.',
        'Bitte zuerst die rot markierten Voraussetzungen beheben.' => 'Please fix the requirements marked in red first.',
        'Spielversion' => 'Game version', 'Spielversion wählen.' => 'Choose a game version.',
        'Retail (aktuelles WoW)' => 'Retail (current WoW)',
        'Datenbank: Verbindung oder Rechte fehlen (' => 'Database: connection or permissions missing (',
        '). Zugangsdaten prüfen.' => '). Check the access data.',
        'Datenbank-Angaben unvollständig.' => 'Database details incomplete.',
        'Gildenname und Realm angeben.' => 'Enter guild name and realm.',
        'Region wählen.' => 'Choose a region.',
        'E-Mail und Anzeigename des Admins angeben.' => "Enter the admin's email and display name.",
        'Das Admin-Passwort braucht mindestens ' => 'The admin password needs at least ',
        ' Zeichen.' => ' characters.',
        'Die Passwörter stimmen nicht überein.' => 'The passwords do not match.',
        'Diese Datenbank enthält bereits Banklord-Tabellen. Bitte eine leere Datenbank verwenden.' => 'This database already contains Banklord tables. Please use an empty database.',
        'Konfiguration konnte nicht gespeichert werden.' => 'Could not save the configuration.',
    ];

    public static function configured(string $root): bool {
        return is_file($root.'/private/config.local.php') || getenv('DB_NAME') !== false;
    }

    public static function handle(string $root): never {
        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (str_contains($path, '/api/')) {
            http_response_code(503);
            echo json_encode(['error' => 'SETUP_REQUIRED']);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
        if (!str_ends_with($path, '/install')) {
            header('Location: '.rtrim(dirname($path.'x'), '/').'/install', true, 302);
            exit;
        }
        session_name('banklord_install');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => ($_SERVER['HTTPS'] ?? '') === 'on']);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
        $checks = self::requirements($root);
        $error = null;
        $done = false;
        $in = [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'guild', 'realm', 'region', 'flavor', 'email', 'name', 'password', 'password2'] as $k) {
                $in[$k] = trim((string)($_POST[$k] ?? ''));
            }
            try {
                if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) throw new \RuntimeException('Formular abgelaufen, bitte neu laden.');
                if (in_array(false, array_column($checks, 'ok'), true)) throw new \RuntimeException('Bitte zuerst die rot markierten Voraussetzungen beheben.');
                self::install($root, $in);
                $done = true;
                session_destroy();
            } catch (\Throwable $e) {
                $error = $e instanceof \PDOException ? 'Datenbank: Verbindung oder Rechte fehlen ('.$e->getCode().'). Zugangsdaten prüfen.' : $e->getMessage();
            }
        }
        ob_start();
        self::page($checks, $in, $error, $done, $_SESSION['csrf'] ?? '');
        $html = (string)ob_get_clean();
        // German devices get German, everyone else English.
        echo preg_match('/^de\b/i', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ? $html : strtr($html, self::EN);
        exit;
    }

    private static function requirements(string $root): array {
        $private = $root.'/private';
        @mkdir($private, 0700, true);
        return [
            ['PHP 8.2 oder neuer', version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['PHP-Erweiterung pdo_mysql', extension_loaded('pdo_mysql')],
            ['PHP-Erweiterung sodium (signierte Updates)', function_exists('sodium_crypto_sign_verify_detached')],
            ['PHP-Erweiterung zip (Updates)', class_exists(\ZipArchive::class)],
            ['Ordner private/ beschreibbar', is_dir($private) && is_writable($private)],
            ['HTTPS aktiv', ($_SERVER['HTTPS'] ?? '') === 'on' || getenv('APP_HTTPS') === 'off'],
        ];
    }

    private static function install(string $root, array $in): void {
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $in['db_name']) || $in['db_host'] === '' || $in['db_user'] === '') throw new \RuntimeException('Datenbank-Angaben unvollständig.');
        if ($in['guild'] === '' || mb_strlen($in['guild']) > 120 || $in['realm'] === '' || mb_strlen($in['realm']) > 100) throw new \RuntimeException('Gildenname und Realm angeben.');
        if (!in_array($in['region'], ['EU', 'US', 'KR', 'TW'], true)) throw new \RuntimeException('Region wählen.');
        require_once __DIR__.'/GameFlavor.php';
        // Scripted setups from before 1.5.0 send no game version: keep the old default.
        if (($in['flavor'] ?? '') === '') $in['flavor'] = GameFlavor::DEFAULT;
        if (!GameFlavor::valid($in['flavor'])) throw new \RuntimeException('Spielversion wählen.');
        if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL) || $in['name'] === '' || mb_strlen($in['name']) > 100) throw new \RuntimeException('E-Mail und Anzeigename des Admins angeben.');
        if (strlen($in['password']) < self::MIN_PASSWORD) throw new \RuntimeException('Das Admin-Passwort braucht mindestens '.self::MIN_PASSWORD.' Zeichen.');
        if ($in['password'] !== $in['password2']) throw new \RuntimeException('Die Passwörter stimmen nicht überein.');

        [$host, $port] = array_pad(explode(':', $in['db_host'], 2), 2, null);
        $dsn = 'mysql:host='.$host.($port ? ';port='.(int)$port : '').';dbname='.$in['db_name'].';charset=utf8mb4';
        $db = new PDO($dsn, $in['db_user'], $in['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
        if ($db->query("SHOW TABLES LIKE 'guilds'")->fetchColumn()) throw new \RuntimeException('Diese Datenbank enthält bereits Banklord-Tabellen. Bitte eine leere Datenbank verwenden.');

        require_once __DIR__.'/WebUpdater.php';
        $files = glob($root.'/sql/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            foreach (WebUpdater::queries((string)file_get_contents($file)) as $q) $db->exec($q);
        }
        $db->prepare("INSERT INTO guilds (name,realm,region,wow_flavor) VALUES (?,?,?,?)")->execute([$in['guild'], $in['realm'], $in['region'], $in['flavor']]);
        $guild = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO users (email,display_name,password_hash,is_operator) VALUES (?,?,?,1)')
            ->execute([strtolower($in['email']), $in['name'], password_hash($in['password'], PASSWORD_DEFAULT)]);
        $user = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,'admin')")->execute([$guild, $user]);

        $config = ['DB_HOST' => $in['db_host'], 'DB_NAME' => $in['db_name'], 'DB_USER' => $in['db_user'], 'DB_PASS' => $in['db_pass'],
            'APP_HTTPS' => (($_SERVER['HTTPS'] ?? '') === 'on') ? 'on' : 'off'];
        $php = "<?php\n// Written by the Banklord setup on ".gmdate('Y-m-d H:i')." UTC. Keep private.\nreturn ".var_export($config, true).";\n";
        $target = $root.'/private/config.local.php';
        if (file_put_contents($target.'.tmp', $php, LOCK_EX) !== strlen($php) || !rename($target.'.tmp', $target)) throw new \RuntimeException('Konfiguration konnte nicht gespeichert werden.');
        @chmod($target, 0600);
        require_once __DIR__.'/Version.php';
        file_put_contents($root.'/private/upgrade-version.json', json_encode(['version' => Version::WEB]));
    }

    private static function page(array $checks, array $in, ?string $error, bool $done, string $csrf): void {
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $v = static fn(string $k, string $d = '') => $h($in[$k] ?? $d);
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Banklord einrichten</title>',
            '<style>body{margin:0;font:16px/1.5 system-ui,sans-serif;background:#14110c;color:#efe6d2}main{max-width:640px;margin:0 auto;padding:24px 16px}',
            'h1{color:#f3c74e;font-size:26px}fieldset{border:1px solid #4a3d24;border-radius:10px;margin:0 0 18px;padding:12px 16px}legend{color:#f3c74e;padding:0 6px}',
            'label{display:block;margin:10px 0 2px;font-size:14px}input,select{width:100%;box-sizing:border-box;padding:9px;border-radius:6px;border:1px solid #6b5a36;background:#211b12;color:#fff;font-size:16px}',
            'button,a.btn{display:inline-block;margin-top:8px;padding:11px 18px;border:0;border-radius:8px;background:#c99a2e;color:#1b1206;font-weight:700;font-size:16px;text-decoration:none;cursor:pointer}',
            '.ok{color:#8fd18f}.bad{color:#ff8a7a}.err{background:#4a1c17;border:1px solid #a0473c;padding:10px;border-radius:8px}.muted{color:#b7a98b;font-size:14px}</style></head><body><main>',
            '<h1>Banklord einrichten</h1>';
        if ($done) {
            echo '<p class="ok"><strong>Fertig!</strong> Datenbank, Gilde und Admin-Konto sind angelegt.</p>',
                '<p>Melde dich jetzt mit deiner E-Mail und deinem Passwort an. Unter <em>Verwaltung → Mitglieder</em> lädst du deine Gilde ein.</p>',
                '<p class="muted">Updates spielt die Website automatisch ein (alle 6 Stunden, mit Backup). Diese Einrichtungsseite ist ab jetzt gesperrt.</p>',
                '<a class="btn" href="./">Zur Anmeldung</a></main></body></html>';
            return;
        }
        echo '<p class="muted">Diese Seite erscheint nur, solange Banklord noch nicht eingerichtet ist. Du brauchst eine leere MySQL- oder MariaDB-Datenbank. Die Zugangsdaten findest du im Kundenmenü deines Webhosters.</p>';
        echo '<fieldset><legend>Voraussetzungen</legend>';
        foreach ($checks as [$label, $ok]) echo '<div class="', $ok ? 'ok' : 'bad', '">', $ok ? '✓ ' : '✗ ', $h($label), '</div>';
        echo '</fieldset>';
        if ($error !== null) echo '<p class="err">', $h($error), '</p>';
        echo '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="', $h($csrf), '">',
            '<fieldset><legend>1 · Datenbank</legend>',
            '<label for="db_host">Server</label><input id="db_host" name="db_host" required value="', $v('db_host', 'localhost'), '">',
            '<label for="db_name">Datenbankname</label><input id="db_name" name="db_name" required value="', $v('db_name'), '">',
            '<label for="db_user">Benutzer</label><input id="db_user" name="db_user" required value="', $v('db_user'), '">',
            '<label for="db_pass">Passwort</label><input id="db_pass" name="db_pass" type="password"></fieldset>',
            '<fieldset><legend>2 · Gilde</legend>',
            '<label for="guild">Gildenname</label><input id="guild" name="guild" required maxlength="120" value="', $v('guild'), '">',
            '<label for="realm">Realm</label><input id="realm" name="realm" required maxlength="100" value="', $v('realm'), '">',
            '<label for="region">Region</label><select id="region" name="region">';
        foreach (['EU', 'US', 'KR', 'TW'] as $r) echo '<option', ($in['region'] ?? 'EU') === $r ? ' selected' : '', '>', $r, '</option>';
        echo '</select><label for="flavor">Spielversion</label><select id="flavor" name="flavor">';
        require_once __DIR__.'/GameFlavor.php';
        foreach (GameFlavor::ALL as $key => $info) echo '<option value="', $key, '"', ($in['flavor'] ?? GameFlavor::DEFAULT) === $key ? ' selected' : '', '>', htmlspecialchars($info[0]), '</option>';
        echo '</select></fieldset>',
            '<fieldset><legend>3 · Dein Admin-Konto</legend>',
            '<label for="email">E-Mail (Anmeldename)</label><input id="email" name="email" type="email" required value="', $v('email'), '">',
            '<label for="name">Anzeigename</label><input id="name" name="name" required maxlength="100" value="', $v('name'), '">',
            '<label for="password">Passwort (mindestens ', self::MIN_PASSWORD, ' Zeichen)</label><input id="password" name="password" type="password" required minlength="', self::MIN_PASSWORD, '" autocomplete="new-password">',
            '<label for="password2">Passwort wiederholen</label><input id="password2" name="password2" type="password" required autocomplete="new-password"></fieldset>',
            '<button type="submit">Banklord einrichten</button></form></main></body></html>';
    }
}
