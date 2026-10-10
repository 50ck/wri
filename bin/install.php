<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
require dirname(__DIR__) . '/src/config.php';
require dirname(__DIR__) . '/src/database.php';
try {
    if (PHP_VERSION_ID < 80400 || !extension_loaded('pdo_sqlite') || !extension_loaded('mbstring') || !extension_loaded('dom')) throw new RuntimeException('PHP 8.4+, pdo_sqlite, mbstring and dom are required.');
    foreach ([dirname(config()['database']), config()['session_path']] as $dir) if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Cannot create storage.');
    if (dirname(config()['database']) === dirname(__DIR__).'/storage') chmod(dirname(config()['database']), 0700);
    chmod(config()['session_path'], 0700);
    migrate(); chmod(config()['database'], 0600);
    echo "Database ready. Configure Nginx and PHP-FPM; see README.md.\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
