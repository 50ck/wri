<?php
declare(strict_types=1);
umask(0077);
require_once __DIR__.'/config.php';
require_once __DIR__.'/addons.php';
require_once __DIR__.'/markdown.php';
require_once __DIR__.'/render.php';
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
    ];
    foreach ($migrations as $version => $apply) transaction(static function () use ($version,$apply) {
        if (!one('SELECT version FROM migrations WHERE version=?',[$version])) {
            $apply(); query('INSERT INTO migrations VALUES (?,?)',[$version,now()]);
        }
    });
}
function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

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

function send_header(string $header, bool $replace = true, int $status = 0): void {
    if (PHP_SAPI === 'cli') $GLOBALS['response_headers'][] = $header;
    if ($status) header($header, $replace, $status); else header($header, $replace);
}
class HttpError extends RuntimeException { public function __construct(public int $status, string $message) { parent::__construct($message); } }
function fail(int $status, string $key): never { throw new HttpError($status, t($key)); }
function input(string $name, ?array $source = null): string {
    $value = ($source ?? $_POST)[$name] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) fail(400, 'invalid');
    return $value;
}
function username(string $value): string {
    $value = strtolower(trim($value));
    if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{1,22})[a-z0-9]\z/D', $value) || str_contains($value, '--') || in_array($value, reserved_names(), true)) fail(422, 'username_error');
    return $value;
}
function text_field(string $value, int $limit, bool $required = true): string {
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $limit || !mb_check_encoding($value, 'UTF-8')) fail(422, 'text_error');
    return $value;
}
function markdown_field(string $value): string {
    if (strlen($value) > config()['body_limit']) fail(413, 'too_large');
    return $value;
}
function validate_password(string $password, string $confirm): void {
    if (!mb_check_encoding($password, 'UTF-8')) fail(422, 'invalid');
    if (str_contains($password, "\0")) fail(422, 'password_null');
    if (mb_strlen($password, 'UTF-8') < 12) fail(422, 'password_min');
    if (strlen($password) > 1024) {
        // Express the byte cap as the number of characters that fit in this password's prefix.
        $max = mb_strlen(mb_strcut($password, 0, 1024, 'UTF-8'), 'UTF-8');
        throw new HttpError(422, str_replace('{n}', (string)$max, t('password_max')));
    }
    if (!hash_equals($password, $confirm)) fail(422, 'confirmation_error');
}
function password_make(string $password): string {
    if (in_array('argon2id', password_algos(), true)) return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost'=>config()['argon_memory'], 'time_cost'=>config()['argon_time'], 'threads'=>1]);
    // Hash the complete Unicode password before bcrypt, avoiding its 72-byte truncation.
    return 'sha384:' . password_hash(base64_encode(hash('sha384', $password, true)), PASSWORD_BCRYPT, ['cost'=>config()['bcrypt_cost']]);
}
function password_check(string $password, string $hash): bool {
    if (strlen($password) > 1024 || str_contains($password, "\0")) return false;
    if (str_starts_with($hash, 'sha384:')) return password_verify(base64_encode(hash('sha384', $password, true)), substr($hash, 7));
    return password_verify($password, $hash);
}
function start_session(): void {
    $c = config();
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1'); ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string)$c['session_lifetime']);
    session_save_path($c['session_path']);
    session_name(parse_url($c['base_url'], PHP_URL_SCHEME) === 'https' ? '__Host-blog_session' : 'blog_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>parse_url($c['base_url'], PHP_URL_SCHEME)==='https','httponly'=>true,'samesite'=>'Lax']);
    session_start();
    if (isset($_SESSION['issued']) && time() - $_SESSION['issued'] > $c['session_lifetime']) { $_SESSION = []; session_regenerate_id(true); }
}
function current_user(): ?array {
    return isset($_SESSION['user_id']) ? one('SELECT * FROM users WHERE id=?', [$_SESSION['user_id']]) : null;
}
function require_user(): array { return current_user() ?? throw new HttpError(401, t('login_required')); }
function authenticate(int $id): void { session_regenerate_id(true); $_SESSION = ['user_id'=>$id,'issued'=>time(),'csrf'=>bin2hex(random_bytes(32))]; }
function logout(): void {
    $_SESSION=[]; session_destroy();
    $p=session_get_cookie_params(); setcookie(session_name(), '', ['expires'=>1,'path'=>'/','secure'=>$p['secure'],'httponly'=>true,'samesite'=>'Lax']);
}
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function check_csrf(): void { if (!hash_equals(csrf(), input('csrf'))) fail(403, 'csrf_error'); }
function rate_limit(string $scope, string $identity, int $limit, int $window): void {
    transaction(function () use ($scope,$identity,$limit,$window) {
        $key = $scope . ':' . hash('sha256', $identity); $time=time();
        query('DELETE FROM rate_limits WHERE starts < ?', [$time - 86400]);
        $row=one('SELECT * FROM rate_limits WHERE bucket=?', [$key]);
        if ($row && $time - $row['starts'] < $window && $row['attempts'] >= $limit) { send_header('Retry-After: ' . ($window - ($time-$row['starts']))); fail(429, 'rate_error'); }
        if (!$row || $time-$row['starts'] >= $window) query('INSERT INTO rate_limits VALUES (?,?,1) ON CONFLICT(bucket) DO UPDATE SET starts=excluded.starts, attempts=1', [$key,$time]);
        else query('UPDATE rate_limits SET attempts=attempts+1 WHERE bucket=?', [$key]);
    });
}
function owned_blog(): array {
    $user=require_user(); return one('SELECT b.*,u.username FROM blogs b JOIN users u ON u.id=b.user_id WHERE b.user_id=?', [$user['id']]) ?? throw new HttpError(404,t('not_found'));
}
function is_owner(array $blog): bool { return (int)($blog['user_id']) === (int)(current_user()['id'] ?? 0); }
function can_read(array $blog): void { if ($blog['visibility'] === 'private' && !is_owner($blog)) fail(404, 'unavailable'); }
function headers_for(?array $blog = null, bool $preview = false): void {
    send_header('Cache-Control: no-store, private'); send_header('X-Content-Type-Options: nosniff'); send_header('Referrer-Policy: no-referrer');
    $origin=config()['base_url'];
    $styles = $origin . '/assets/base.css';
    if ($blog) $styles .= ' ' . $origin . '/assets/blog.css ' . $origin . '/' . $blog['username'] . '/style.css';
    $csp="default-src 'none'; script-src 'none'; style-src $styles; img-src 'none'; font-src 'none'; connect-src 'none'; media-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors " . ($preview ? "'self'" : "'none'");
    if (!$blog) $csp .= "; frame-src $origin";
    else $csp .= "; sandbox allow-same-origin" . ($preview ? '' : ' allow-top-navigation-by-user-activation');
    send_header('Content-Security-Policy: ' . $csp);
    send_header('X-Frame-Options: ' . ($preview ? 'SAMEORIGIN' : 'DENY'));
    if (str_starts_with($origin, 'https://')) send_header('Strict-Transport-Security: max-age=31536000');
    send_header('Content-Type: text/html; charset=utf-8');
}

function register_account(string $name, string $password, string $confirm): array {
    if (!config()['registration']) fail(403,'registration_closed');
    $name=username($name); validate_password($password,$confirm); $hash=password_make($password);
    try {
        return transaction(function () use ($name,$hash) {
            $time=now();
            query('INSERT INTO users(username,password_hash,created_at,updated_at) VALUES (?,?,?,?)',[$name,$hash,$time,$time]);
            $id=(int)db()->lastInsertId();
            query('INSERT INTO blogs(user_id,title,created_at,updated_at) VALUES (?,?,?,?)',[$id,$name,$time,$time]);
            enable_default_addons((int)db()->lastInsertId());
            return ['id'=>$id,'username'=>$name];
        });
    } catch (PDOException $e) {
        if (one('SELECT id FROM users WHERE username=?',[$name])) fail(422,'username_taken');
        throw $e;
    }
}
function section_list(int $blog): array { return query('SELECT * FROM sections WHERE blog_id=? ORDER BY sort_order,id',[$blog])->fetchAll(); }
function slug_base(string $title): string {
    $title=strtr(mb_strtolower($title,'UTF-8'),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $slug=trim(preg_replace('/[^a-z0-9]+/', '-', $title), '-');
    $slug=trim(substr($slug,0,60),'-');
    if ($slug==='' || in_array($slug,reserved_names(),true)) $slug='entrada';
    return $slug;
}
function free_section_slug(int $blog, string $title): string {
    $base=slug_base($title);
    if (in_array($base,section_reserved_names(),true)) $base='seccion';
    $slug=$base; $i=2;
    while (one('SELECT id FROM sections WHERE blog_id=? AND slug=?',[$blog,$slug])) $slug=$base.'-'.$i++;
    return $slug;
}
function namespace_path(?int $section, string $slug): string {
    $prefix=$section ? (one('SELECT slug FROM sections WHERE id=?',[$section])['slug'] ?? 'posts') : 'posts';
    return $prefix.'/'.$slug;
}
function free_post_slug(int $blog, ?int $section, string $base, int $exclude = 0): string {
    $slug=$base; $i=2;
    while (one('SELECT id FROM posts WHERE blog_id=? AND section_id IS ? AND slug=? AND id<>?',[$blog,$section,$slug,$exclude]) || one('SELECT post_id FROM redirects WHERE blog_id=? AND old_path=? AND post_id<>?',[$blog,namespace_path($section,$slug),$exclude])) $slug=$base.'-'.$i++;
    return $slug;
}
function add_section(array $blog,string $title,string $content): int {
    $title=text_field($title,120); $content=markdown_field($content);
    return transaction(function () use ($blog,$title,$content) {
        if (count(section_list($blog['id']))>=10) fail(422,'section_limit');
        $time=now();
        query('INSERT INTO sections(blog_id,title,slug,content_markdown,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',[$blog['id'],$title,free_section_slug($blog['id'],$title),$content, count(section_list($blog['id'])),$time,$time]);
        return (int)db()->lastInsertId();
    });
}
function owned_section(array $blog,int $id): array { return one('SELECT * FROM sections WHERE id=? AND blog_id=?',[$id,$blog['id']]) ?? throw new HttpError(404,t('not_found')); }
function edit_section(array $blog,int $id,string $title,string $content): void {
    owned_section($blog,$id); $title=text_field($title,120); $content=markdown_field($content);
    query('UPDATE sections SET title=?,content_markdown=?,updated_at=? WHERE id=? AND blog_id=?',[$title,$content,now(),$id,$blog['id']]);
}
function reorder_section(array $blog,int $id,string $direction): void {
    if (!in_array($direction,['up','down'],true)) fail(400,'invalid');
    transaction(function () use ($blog,$id,$direction) {
        owned_section($blog,$id); $sections=section_list($blog['id']);
        $index=array_search($id,array_column($sections,'id')); $other=$index+($direction==='up'?-1:1);
        if (isset($sections[$other])) [$sections[$index],$sections[$other]]=[$sections[$other],$sections[$index]];
        foreach ($sections as $order=>$section) query('UPDATE sections SET sort_order=?,updated_at=? WHERE id=? AND blog_id=?',[$order,now(),$section['id'],$blog['id']]);
    });
}
function remember_path(int $blog,string $path,int $post): void {
    query('INSERT INTO redirects(blog_id,old_path,post_id) VALUES (?,?,?) ON CONFLICT(blog_id,old_path) DO UPDATE SET post_id=excluded.post_id',[$blog,$path,$post]);
}
function delete_section(array $blog,int $id): void {
    transaction(function () use ($blog,$id) {
        $section=owned_section($blog,$id);
        foreach (query('SELECT * FROM posts WHERE blog_id=? AND section_id=?',[$blog['id'],$id])->fetchAll() as $post) {
            $slug=free_post_slug($blog['id'],null,$post['slug'],$post['id']);
            remember_path($blog['id'],$section['slug'].'/'.$post['slug'],$post['id']);
            query('UPDATE posts SET section_id=NULL,slug=?,updated_at=? WHERE id=? AND blog_id=?',[$slug,now(),$post['id'],$blog['id']]);
        }
        query('DELETE FROM sections WHERE id=? AND blog_id=?',[$id,$blog['id']]);
    });
}
function owned_post(array $blog,int $id): array { return one('SELECT * FROM posts WHERE id=? AND blog_id=? AND deleted_at IS NULL',[$id,$blog['id']]) ?? throw new HttpError(404,t('not_found')); }
function save_post(array $blog,?int $id,string $title,string $body,?int $section,string $status): int {
    $title=text_field($title,200); $body=markdown_field($body);
    if (!in_array($status,['draft','published'],true)) fail(422,'invalid');
    return transaction(function () use ($blog,$id,$title,$body,$section,$status) {
        if ($section) owned_section($blog,$section);
        $time=now();
        if ($id) {
            $post=owned_post($blog,$id); $slug=$post['slug'];
            if ($post['section_id'] !== $section) {
                $slug=free_post_slug($blog['id'],$section,$slug,$id);
                remember_path($blog['id'],namespace_path($post['section_id'],$post['slug']),$id);
            }
            query('UPDATE posts SET title=?,body_markdown=?,section_id=?,slug=?,status=?,updated_at=?,published_at=? WHERE id=? AND blog_id=?',[$title,$body,$section,$slug,$status,$time,$post['published_at'] ?? ($status==='published'?$time:null),$id,$blog['id']]);
            return $id;
        }
        if ((int)query('SELECT count(*) FROM posts WHERE blog_id=?',[$blog['id']])->fetchColumn() >= config()['post_limit']) fail(422,'post_limit');
        $slug=free_post_slug($blog['id'],$section,slug_base($title));
        query('INSERT INTO posts(blog_id,section_id,title,slug,body_markdown,status,created_at,updated_at,published_at) VALUES (?,?,?,?,?,?,?,?,?)',[$blog['id'],$section,$title,$slug,$body,$status,$time,$time,$status==='published'?$time:null]);
        return (int)db()->lastInsertId();
    });
}
function delete_post(array $blog,int $id): void {
    owned_post($blog,$id);
    query('UPDATE posts SET deleted_at=?,updated_at=? WHERE id=? AND blog_id=?',[now(),now(),$id,$blog['id']]);
}
function post_path(array $blog,array $post): string { return '/'.$blog['username'].'/'.namespace_path($post['section_id'],$post['slug']); }
function save_css(array $blog,string $css): void {
    if (strlen($css)>config()['css_limit']) fail(413,'too_large');
    query('UPDATE blogs SET custom_css=?,updated_at=? WHERE id=? AND user_id=?',[$css,now(),$blog['id'],$blog['user_id']]);
}
function save_settings(array $blog,string $title,string $description,string $visibility): void {
    $title=text_field($title,120); $description=text_field($description,280,false);
    if (!in_array($visibility,['public','private'],true)) fail(422,'invalid');
    query('UPDATE blogs SET title=?,description=?,visibility=?,updated_at=? WHERE id=? AND user_id=?',[$title,$description,$visibility,now(),$blog['id'],$blog['user_id']]);
}
function delete_account(array $blog,string $password,string $confirmation): void {
    transaction(function () use ($blog,$password,$confirmation) {
        $user=one('SELECT * FROM users WHERE id=?',[$blog['user_id']]);
        if (!$user || !password_check($password,$user['password_hash']) || !hash_equals($user['username'],$confirmation)) fail(422,'delete_error');
        query('DELETE FROM users WHERE id=?',[$user['id']]);
    });
}

function save_home(array $blog,string $content): void {
    $content=markdown_field($content);
    query('UPDATE blogs SET home_markdown=?,updated_at=? WHERE id=? AND user_id=?',[$content,now(),$blog['id'],$blog['user_id']]);
}

function redirect(string $path,int $status=303,?string $flash=null): never {
    if (!str_starts_with($path,'/') || str_starts_with($path,'//') || preg_match('/[\r\n]/',$path)) throw new LogicException('Unsafe redirect');
    if ($flash) $_SESSION['flash']=$flash;
    send_header('Location: '.$path,true,$status);exit;
}
function methods(array $allowed): void {
    if (!in_array($_SERVER['REQUEST_METHOD'],$allowed,true)) { send_header('Allow: '.implode(', ',$allowed));fail(405,'method_error'); }
}
function submitted(callable $action): ?string {
    if ($_SERVER['REQUEST_METHOD']!=='POST') return null;
    check_csrf();
    try { $action(); }
    catch (HttpError $e) { if (!in_array($e->status,[400,413,422],true)) throw $e; http_response_code($e->status);return $e->getMessage(); }
    return null;
}
function route(): void {
    $path=explode('?',$_SERVER['REQUEST_URI']??'/',2)[0];
    if (strlen($path)>256 || !preg_match('~\A/[a-z0-9/._-]*\z~D',$path) || str_contains($path,'//') || str_contains($path,'..')) fail(404,'not_found');
    if ($path!=='/' && str_ends_with($path,'/')) { methods(['GET']);redirect(rtrim($path,'/'),308); }
    if ($path==='/manage' || str_starts_with($path,'/manage/')) { require_once __DIR__.'/public/manage.php';route_manage($path); }
    elseif (in_array($path,['/','/register','/login','/logout'],true)) route_index($path);
    else { require_once __DIR__.'/public/blog.php';route_blog($path); }
}
function run(): void {
    ini_set('display_errors','0');ini_set('log_errors','1');error_reporting(E_ALL & ~E_DEPRECATED);
    ob_start();
    try {
        headers_for();
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>config()['request_limit'] || strlen(file_get_contents('php://input', false, null, 0, config()['request_limit']+1)) > config()['request_limit']) fail(413,'too_large');
        if ($_SERVER['REQUEST_METHOD']==='POST' && stripos($_SERVER['CONTENT_TYPE']??'','application/x-www-form-urlencoded')!==0) fail(400,'invalid');
        start_session();route();
    } catch (HttpError $e) {
        ob_clean();
        http_response_code($e->status);page('error',function () use($e) {echo '<p>'.e($e->getMessage()).'</p><p>'.link_to('/','home').' '.link_to('/login','login').'</p>';});
    } catch (Throwable $e) {
        ob_clean();
        // Never log request values, passwords, cookies, or exception messages containing data.
        error_log('Blog error '.get_class($e).' in '.basename($e->getFile()).':'.$e->getLine());
        if (config()['debug']) foreach ($e->getTrace() as $frame) error_log('Blog trace '.basename($frame['file'] ?? '').':'.($frame['line'] ?? 0).' '.($frame['function'] ?? ''));
        http_response_code(500);page('error',function () {echo '<p>'.e(t('server_error')).'</p>';});
    } finally { ob_end_flush(); }
}
