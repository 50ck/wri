<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
require __DIR__.'/app.php';
function migrate(): void {
    db()->exec('PRAGMA journal_mode = WAL');
    db()->exec('CREATE TABLE IF NOT EXISTS migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
    $migrations = [
        '001.sql' => static fn() => db()->exec(initial_schema()),
        '002_homepage.php' => static function () {
            db()->exec("ALTER TABLE blogs ADD COLUMN home_markdown TEXT NOT NULL DEFAULT ''");
            // Preserve existing sections whose paths now belong to the two built-in pages.
            foreach (query('SELECT id,blog_id,slug FROM sections WHERE slug IN (?,?)', ['inicio','blogs'])->fetchAll() as $section) {
                $suffix=2;
                do { $slug=$section['slug'].'-'.$suffix++; }
                while (one('SELECT id FROM sections WHERE blog_id=? AND slug=?',[$section['blog_id'],$slug]));
                foreach (query('SELECT id,slug FROM posts WHERE blog_id=? AND section_id=?',[$section['blog_id'],$section['id']])->fetchAll() as $post) {
                    query('INSERT INTO redirects(blog_id,old_path,post_id) VALUES (?,?,?) ON CONFLICT(blog_id,old_path) DO UPDATE SET post_id=excluded.post_id',[$section['blog_id'],$section['slug'].'/'.$post['slug'],$post['id']]);
                }
                query('UPDATE sections SET slug=?,updated_at=? WHERE id=?',[$slug,now(),$section['id']]);
            }
        },
        '003_addons' => static function () {
            db()->exec('CREATE TABLE blog_addons (blog_id INTEGER NOT NULL REFERENCES blogs(id) ON DELETE CASCADE, addon_id TEXT NOT NULL, PRIMARY KEY(blog_id, addon_id))');
            foreach (query('SELECT id FROM blogs')->fetchAll() as $blog) enable_default_addons((int)$blog['id']);
        },
        '004_admin' => static function () {
            db()->exec('CREATE TABLE platform (id INTEGER PRIMARY KEY CHECK(id=1), admin_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, admin_initialized INTEGER NOT NULL CHECK(admin_initialized IN (0,1)), homepage_blog_id INTEGER REFERENCES blogs(id) ON DELETE SET NULL)');
            $first=one('SELECT id FROM users ORDER BY id LIMIT 1');
            query('INSERT INTO platform(id,admin_user_id,admin_initialized) VALUES (1,?,?)',[$first['id']??null,$first?1:0]);
        },
    ];
    foreach ($migrations as $version => $apply) transaction(static function () use ($version,$apply) {
        if (!one('SELECT version FROM migrations WHERE version=?',[$version])) {
            $apply(); query('INSERT INTO migrations VALUES (?,?)',[$version,now()]);
        }
    });
}

function initial_schema(): string { return <<<'SQL'
CREATE TABLE users (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 username TEXT NOT NULL COLLATE NOCASE UNIQUE,
 password_hash TEXT NOT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE blogs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
 title TEXT NOT NULL,
 description TEXT NOT NULL DEFAULT '',
 visibility TEXT NOT NULL DEFAULT 'public' CHECK(visibility IN ('public','private')),
 custom_css TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE sections (
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
CREATE TRIGGER section_limit BEFORE INSERT ON sections
WHEN (SELECT count(*) FROM sections WHERE blog_id=NEW.blog_id) >= 10
BEGIN SELECT RAISE(ABORT, 'section_limit'); END;
CREATE TABLE posts (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 blog_id INTEGER NOT NULL REFERENCES blogs(id) ON DELETE CASCADE,
 section_id INTEGER,
 title TEXT NOT NULL,
 slug TEXT NOT NULL,
 body_markdown TEXT NOT NULL,
 status TEXT NOT NULL CHECK(status IN ('draft','published')),
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL,
 published_at TEXT,
 deleted_at TEXT,
 FOREIGN KEY(blog_id, section_id) REFERENCES sections(blog_id, id)
);
CREATE UNIQUE INDEX posts_slug ON posts(blog_id, COALESCE(section_id, 0), slug);
CREATE INDEX posts_feed ON posts(blog_id, status, deleted_at, published_at DESC, id DESC);
CREATE INDEX posts_section ON posts(blog_id, section_id);
CREATE TABLE redirects (
 blog_id INTEGER NOT NULL REFERENCES blogs(id) ON DELETE CASCADE,
 old_path TEXT NOT NULL,
 post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
 PRIMARY KEY(blog_id, old_path)
);
CREATE TABLE rate_limits (
 bucket TEXT PRIMARY KEY,
 starts INTEGER NOT NULL,
 attempts INTEGER NOT NULL
);
SQL;
}


// Tests can load the migration functions without running the CLI installation.
if (realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__) return;
try {
    if (PHP_VERSION_ID < 80400 || !extension_loaded('pdo_sqlite') || !extension_loaded('mbstring') || !extension_loaded('dom')) throw new RuntimeException('PHP 8.4+, pdo_sqlite, mbstring and dom are required.');
    foreach ([dirname(config()['database']), config()['session_path']] as $dir) if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Cannot create storage.');
    if (dirname(config()['database']) === __DIR__.'/storage') chmod(dirname(config()['database']), 0700);
    chmod(config()['session_path'], 0700);
    migrate(); chmod(config()['database'], 0600);
    echo "Database ready. Configure Nginx and PHP-FPM; see README.md.\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
