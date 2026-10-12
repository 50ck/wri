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
function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

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
    $user=isset($_SESSION['user_id']) ? one('SELECT * FROM users WHERE id=?', [$_SESSION['user_id']]) : null;
    return $user && (int)$user['auth_version']===(int)($_SESSION['auth_version']??0) ? $user : null;
}
function require_user(): array { return current_user() ?? throw new HttpError(401, t('login_required')); }
function platform_settings(): array { return config(); }
function registration_open(): bool { return config()['registration'] && (bool)platform_settings()['allow_signups']; }
function is_admin(): bool { $user=current_user();return $user!==null && (int)$user['id']===(int)(platform_settings()['admin_user_id']??0); }
function require_admin(): void { require_user();if (!is_admin()) fail(403,'forbidden'); }
function save_platform_home(string $id): void {
    require_admin();
    update_settings(static function (array &$settings) use($id) {
        require_admin();
        if ($id!=='' && (!ctype_digit($id) || !one("SELECT id FROM blogs WHERE id=? AND visibility='public'",[$id]))) fail(422,'invalid');
        $settings['homepage_blog_id']=$id===''?null:(int)$id;
    });
}
function authenticate(int $id): void { session_regenerate_id(true); $_SESSION = ['user_id'=>$id,'auth_version'=>(int)one('SELECT auth_version FROM users WHERE id=?',[$id])['auth_version'],'issued'=>time(),'csrf'=>bin2hex(random_bytes(32))]; }
function admin_user(int $id): array {
    require_admin();
    return one('SELECT u.*,b.id AS blog_id,b.visibility FROM users u JOIN blogs b ON b.user_id=u.id WHERE u.id=?',[$id]) ?? throw new HttpError(404,t('not_found'));
}
function admin_edit_user(int $id,string $name,string $password,string $confirm,string $visibility): void {
    require_admin();$name=username($name);
    if (!in_array($visibility,['public','private'],true)) fail(422,'invalid');
    $hash=null;
    if ($password!=='' || $confirm!=='') { validate_password($password,$confirm);$hash=password_make($password); }
    transaction(static function () use($id,$name,$hash,$visibility) {
        $user=admin_user($id);
        if (one('SELECT id FROM users WHERE username=? AND id<>?',[$name,$id]) || one('SELECT user_id FROM username_aliases WHERE username=? AND user_id<>?',[$name,$id])) fail(422,'username_taken');
        if ($name!==$user['username']) {
            query('DELETE FROM username_aliases WHERE username=? AND user_id=?',[$name,$id]);
            query('INSERT INTO username_aliases(username,user_id) VALUES (?,?)',[$user['username'],$id]);
        }
        query('UPDATE users SET username=?,password_hash=?,auth_version=auth_version+?,updated_at=? WHERE id=?',[$name,$hash??$user['password_hash'],$hash===null?0:1,now(),$id]);
        query('UPDATE blogs SET visibility=?,updated_at=? WHERE user_id=?',[$visibility,now(),$id]);
    });
}
function admin_delete_user(int $id): void {
    require_admin();$admin=require_user();
    rate_limit('admin-delete',(string)$admin['id'],10,900);
    transaction(static function () use($id) {
        $user=admin_user($id);
        if ($id===(int)platform_settings()['admin_user_id']) fail(403,'forbidden');
        update_settings(static function (array &$settings) use($id,$user) {
            require_admin();if ($id===$settings['admin_user_id']) fail(403,'forbidden');
            query('DELETE FROM users WHERE id=?',[$id]);
            if ($settings['homepage_blog_id']===$user['blog_id']) $settings['homepage_blog_id']=null;
        });
    });
}
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
function headers_for(?array $blog = null, bool $preview = false, array $addonStyles = []): void {
    send_header('Cache-Control: no-store, private'); send_header('X-Content-Type-Options: nosniff'); send_header('Referrer-Policy: no-referrer');
    $origin=config()['base_url'];
    $styles = $origin . '/assets/base.css';
    foreach ($addonStyles as $id) if (isset(addons('admin')[$id]['style'])) $styles.=' '.$origin.'/_addons/'.$id.'/style.css';
    if ($blog) $styles .= ' ' . $origin . '/assets/blog.css ' . $origin . '/' . $blog['username'] . '/style.css';
    $imagePolicy=$blog ? 'https:' : "'none'";
    $csp="default-src 'none'; script-src 'none'; style-src $styles; img-src $imagePolicy; font-src 'none'; connect-src 'none'; media-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors " . ($preview ? "'self'" : "'none'");
    if (!$blog) $csp .= "; frame-src $origin";
    else $csp .= "; sandbox allow-same-origin" . ($preview ? '' : ' allow-top-navigation-by-user-activation');
    send_header('Content-Security-Policy: ' . $csp);
    send_header('X-Frame-Options: ' . ($preview ? 'SAMEORIGIN' : 'DENY'));
    if (str_starts_with($origin, 'https://')) send_header('Strict-Transport-Security: max-age=31536000');
    send_header('Content-Type: text/html; charset=utf-8');
}

function register_account(string $name, string $password, string $confirm, bool $byAdmin=false): array {
    if ($byAdmin) require_admin();elseif (!registration_open()) fail(404,'not_found');
    $name=username($name); validate_password($password,$confirm); $hash=password_make($password);
    try {
        return transaction(fn()=>update_settings(function (array &$settings) use ($name,$hash,$byAdmin) {
            if ($byAdmin) require_admin();elseif (!registration_open()) fail(404,'not_found');
            if (one('SELECT user_id FROM username_aliases WHERE username=?',[$name])) fail(422,'username_taken');
            $time=now();
            query('INSERT INTO users(username,password_hash,created_at,updated_at) VALUES (?,?,?,?)',[$name,$hash,$time,$time]);
            $id=(int)db()->lastInsertId();
            query('INSERT INTO blogs(user_id,title,created_at,updated_at) VALUES (?,?,?,?)',[$id,$name,$time,$time]);
            enable_default_addons((int)db()->lastInsertId());
            if (!$settings['admin_initialized']) {$settings['admin_user_id']=$id;$settings['admin_initialized']=true;}
            return ['id'=>$id,'username'=>$name];
        }));
    } catch (PDOException $e) {
        if (one('SELECT id FROM users WHERE username=?',[$name])) fail(422,'username_taken');
        throw $e;
    }
}
function section_list(int $blog): array { return query('SELECT * FROM sections WHERE blog_id=? ORDER BY sort_order,id',[$blog])->fetchAll(); }
function navigation_sections(int $blog): array {
    $order=one('SELECT home_order,feed_order FROM blogs WHERE id=?',[$blog]);
    $sections=array_merge([
        ['id'=>'home','slug'=>'','title'=>t('home'),'sort_order'=>$order['home_order']],
        ['id'=>'blogs','slug'=>'blogs','title'=>t('blogs'),'sort_order'=>$order['feed_order']],
    ],section_list($blog));
    usort($sections,static fn($a,$b)=>$a['sort_order']<=>$b['sort_order']);
    return $sections;
}
function slug_base(string $title): string {
    $title=strtr(mb_strtolower($title,'UTF-8'),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $slug=trim(preg_replace('/[^a-z0-9]+/', '-', $title), '-');
    $slug=trim(substr($slug,0,60),'-');
    if ($slug==='' || in_array($slug,reserved_names(),true)) $slug='post';
    return $slug;
}
function free_section_slug(int $blog, string $title): string {
    $base=slug_base($title);
    if (in_array($base,section_reserved_names(),true)) $base='section';
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
        $order=max(array_column(navigation_sections($blog['id']),'sort_order'))+1;
        query('INSERT INTO sections(blog_id,title,slug,content_markdown,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',[$blog['id'],$title,free_section_slug($blog['id'],$title),$content,$order,$time,$time]);
        return (int)db()->lastInsertId();
    });
}
function owned_section(array $blog,int $id): array { return one('SELECT * FROM sections WHERE id=? AND blog_id=?',[$id,$blog['id']]) ?? throw new HttpError(404,t('not_found')); }
function edit_section(array $blog,int $id,string $title,string $content): void {
    owned_section($blog,$id); $title=text_field($title,120); $content=markdown_field($content);
    query('UPDATE sections SET title=?,content_markdown=?,updated_at=? WHERE id=? AND blog_id=?',[$title,$content,now(),$id,$blog['id']]);
}
function reorder_section(array $blog,int|string $id,string $direction): void {
    if (!in_array($direction,['up','down'],true)) fail(400,'invalid');
    transaction(function () use ($blog,$id,$direction) {
        if (!in_array($id,['home','blogs'],true)) owned_section($blog,(int)$id);
        $sections=navigation_sections($blog['id']);
        $index=array_search($id,array_column($sections,'id'),true); $other=$index+($direction==='up'?-1:1);
        if (isset($sections[$other])) [$sections[$index],$sections[$other]]=[$sections[$other],$sections[$index]];
        foreach ($sections as $order=>$section) {
            if ($section['id']==='home') query('UPDATE blogs SET home_order=?,updated_at=? WHERE id=?',[$order,now(),$blog['id']]);
            elseif ($section['id']==='blogs') query('UPDATE blogs SET feed_order=?,updated_at=? WHERE id=?',[$order,now(),$blog['id']]);
            else query('UPDATE sections SET sort_order=?,updated_at=? WHERE id=? AND blog_id=?',[$order,now(),$section['id'],$blog['id']]);
        }
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
        if ((int)$blog['user_id']===(int)platform_settings()['admin_user_id']) fail(403,'forbidden');
        $user=one('SELECT * FROM users WHERE id=?',[$blog['user_id']]);
        if (!$user || !password_check($password,$user['password_hash']) || !hash_equals($user['username'],$confirmation)) fail(422,'delete_error');
        update_settings(static function (array &$settings) use($user,$blog) {
            if ((int)$blog['user_id']===$settings['admin_user_id']) fail(403,'forbidden');
            query('DELETE FROM users WHERE id=?',[$user['id']]);
            if ($settings['homepage_blog_id']===(int)$blog['id']) $settings['homepage_blog_id']=null;
        });
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
    if ($path==='/_addons' || str_starts_with($path,'/_addons/')) { addon_route(null,'platform',array_slice(explode('/',trim($path,'/')),1)); }
    elseif ($path==='/manage' || str_starts_with($path,'/manage/')) { require_once __DIR__.'/public/manage.php';route_manage($path); }
    elseif (in_array($path,['/','/register','/signup','/login','/logout'],true)) route_index($path);
    else { require_once __DIR__.'/public/blog.php';route_blog($path); }
}
function run(): void {
    ini_set('display_errors','0');ini_set('log_errors','1');error_reporting(E_ALL & ~E_DEPRECATED);
    ob_start();
    try {
        addon_observe_requests();headers_for();
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
