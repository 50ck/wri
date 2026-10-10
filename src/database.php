<?php
declare(strict_types=1);
function db(): PDO {
    static $db;
    if (!$db) {
        $db = new PDO('sqlite:' . config()['database'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA busy_timeout = 5000');
    }
    return $db;
}
function query(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt;
}
function one(string $sql, array $params = []): ?array { return query($sql, $params)->fetch() ?: null; }
function transaction(callable $fn): mixed {
    db()->exec('BEGIN IMMEDIATE');
    try { $result = $fn(); db()->exec('COMMIT'); return $result; }
    catch (Throwable $e) { db()->exec('ROLLBACK'); throw $e; }
}
function migrate(): void {
    db()->exec('PRAGMA journal_mode = WAL');
    db()->exec('CREATE TABLE IF NOT EXISTS migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
    foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $file) {
        transaction(function () use ($file) {
            $version = basename($file);
            if (!one('SELECT version FROM migrations WHERE version=?', [$version])) {
                db()->exec(file_get_contents($file));
                query('INSERT INTO migrations VALUES (?,?)', [$version, now()]);
            }
        });
    }
}
function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
