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
