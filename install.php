<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
require __DIR__.'/app.php';
function initialize_database(): void {
    db()->exec('PRAGMA journal_mode = WAL');
    transaction(static function () {
        db()->exec(initial_schema());
        db()->exec('DROP TABLE IF EXISTS migrations');
    });
    update_settings(static function (array &$settings) {});
}

function initial_schema(): string { return <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 username TEXT NOT NULL COLLATE NOCASE UNIQUE,
 password_hash TEXT NOT NULL,
 auth_version INTEGER NOT NULL DEFAULT 0,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS blogs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
 title TEXT NOT NULL,
 description TEXT NOT NULL DEFAULT '',
 visibility TEXT NOT NULL DEFAULT 'public' CHECK(visibility IN ('public','private')),
 custom_css TEXT NOT NULL DEFAULT '',
 home_markdown TEXT NOT NULL DEFAULT '',
 home_order INTEGER NOT NULL DEFAULT -2,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS sections (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 blog_id INTEGER NOT NULL REFERENCES blogs(id) ON DELETE CASCADE,
 title TEXT NOT NULL,
 slug TEXT NOT NULL,
 content_markdown TEXT NOT NULL DEFAULT '',
 sort_order INTEGER NOT NULL DEFAULT 0,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL,
 UNIQUE(blog_id, slug), UNIQUE(blog_id, id)
);
CREATE TRIGGER IF NOT EXISTS section_limit BEFORE INSERT ON sections
WHEN (SELECT count(*) FROM sections WHERE blog_id=NEW.blog_id) >= 10
BEGIN SELECT RAISE(ABORT, 'section_limit'); END;
CREATE TABLE IF NOT EXISTS posts (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 blog_id INTEGER NOT NULL REFERENCES blogs(id) ON DELETE CASCADE,
 section_id INTEGER,
 title TEXT NOT NULL,
 slug TEXT NOT NULL,
 body_markdown TEXT NOT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL,
 published_at TEXT NOT NULL,
 deleted_at TEXT,
 FOREIGN KEY(blog_id, section_id) REFERENCES sections(blog_id, id)
);
CREATE UNIQUE INDEX IF NOT EXISTS posts_slug ON posts(blog_id, COALESCE(section_id, 0), slug);
CREATE INDEX IF NOT EXISTS posts_feed ON posts(blog_id, deleted_at, published_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS posts_section ON posts(blog_id, section_id);
CREATE TABLE IF NOT EXISTS redirects (
 blog_id INTEGER NOT NULL REFERENCES blogs(id) ON DELETE CASCADE,
 old_path TEXT NOT NULL,
 post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
 PRIMARY KEY(blog_id, old_path)
);
CREATE TABLE IF NOT EXISTS rate_limits (
 bucket TEXT PRIMARY KEY,
 starts INTEGER NOT NULL,
 attempts INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS blog_addons (
 blog_id INTEGER NOT NULL REFERENCES blogs(id) ON DELETE CASCADE,
 addon_id TEXT NOT NULL,
 PRIMARY KEY(blog_id, addon_id)
);
CREATE TABLE IF NOT EXISTS username_aliases (
 username TEXT PRIMARY KEY COLLATE NOCASE,
 user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE
);
SQL;
}


// Tests can load the schema functions without running the CLI installation.
if (realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__) return;
try {
    if (PHP_VERSION_ID < 80400 || !extension_loaded('pdo_sqlite') || !extension_loaded('mbstring') || !extension_loaded('dom')) throw new RuntimeException('PHP 8.4+, pdo_sqlite, mbstring and dom are required.');
    foreach ([dirname(BLOG_DATABASE), BLOG_SESSION_PATH, dirname(config()['config_file'])] as $dir) if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Cannot create storage.');
    chmod(dirname(BLOG_DATABASE), 0700);
    chmod(BLOG_SESSION_PATH, 0700);
    initialize_database(); chmod(BLOG_DATABASE, 0600);
    echo "ok\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
