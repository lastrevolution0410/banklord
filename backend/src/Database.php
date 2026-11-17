<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

final class Database
{
    public static function connect(): PDO
    {
        $host = getenv('DB_HOST') ?: 'localhost';
        $database = getenv('DB_NAME') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS') ?: '';
        if ($database === '' || $user === '') throw new \RuntimeException('DB settings missing');
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $database);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // DATETIME DEFAULT CURRENT_TIMESTAMP otherwise depends on shared-hosting
        // MySQL session time zone and cannot safely be compared with addon epoch
        // timestamps, which we explicitly convert to UTC during import.
        // Does not rewrite legacy data: old ledger timestamps require separate QA.
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
