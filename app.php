<?php
declare(strict_types=1);
umask(0077);
const BLOG_DATABASE = __DIR__.'/storage/blog.sqlite';
const BLOG_SESSION_PATH = __DIR__.'/storage/sessions';
require_once __DIR__.'/addons.php';
require_once __DIR__.'/markdown.php';
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function link_to(string $path,string $key): string { return '<a href="'.e($path).'">'.e(t($key)).'</a>'; }

function token_field(): void { echo '<input type="hidden" name="csrf" value="'.e(csrf()).'">'; }

function field(string $name,string $key,string $value='',string $type='text',bool $required=true,int $max=0): void {
    echo '<label for="'.e($name).'">'.e(t($key)).'</label><input id="'.e($name).'" name="'.e($name).'" type="'.e($type).'" value="'.e($value).'"'.($required?' required':'').($max?' maxlength="'.$max.'"':'');
    if ($type==='password') echo ' autocomplete="'.(in_array($name,['confirm_password','new_password'],true)?'new-password':'current-password').'"';
    if ($name==='username') echo ' autocomplete="username" autocapitalize="none" spellcheck="false"';
    echo '>';
}

function area(string $name,string $key,string $value='',bool $css=false): void {
    echo '<label for="'.e($name).'">'.e(t($key)).'</label>';
    echo '<textarea id="'.e($name).'" name="'.e($name).'"'.($css?' class="css-editor" spellcheck="false"':' aria-label="'.e(t($key)).'" aria-describedby="'.e($name).'-help"').'>'.e($value).'</textarea>';
    if (!$css) echo '<small id="'.e($name).'-help">'.e(t('markdown_help')).'</small>';
}

function button(string $key,?string $name=null,?string $value=null): void { echo '<button type="submit"'.($name?' name="'.e($name).'" value="'.e($value).'"':'').'>'.e(t($key)).'</button>'; }

function page(string $key,callable $content,?string $error=null,?array $management=null,?string $addon=null): void {
    $styles=$addon===null ? [] : [$addon];
    if ($key==='brand') foreach (addons('admin') as $id=>$extension) if (isset($extension['slots']['home_after'])) $styles[]=$id;
    $styles=array_filter(array_unique($styles),static fn($id)=>isset(addons('admin')[$id]['style']));
    headers_for(addonStyles:$styles);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.e($key==='brand'?t('brand'):t($key).' · '.t('brand')).'</title>';
    if ($key==='brand' && t('intro')!=='') echo '<meta name="description" content="'.e(t('intro')).'">';
    if ($key==='brand') echo '<link rel="canonical" href="'.e(config()['base_url'].'/').'">';
    echo '<link rel="stylesheet" href="/assets/base.css">';
    foreach ($styles as $id) echo '<link rel="stylesheet" href="/_addons/'.e($id).'/style.css">';
    echo '</head><body>';
    if ($management) {
        $admin=str_starts_with($_SERVER['REQUEST_URI']??'','/admin.php');$title=$admin?'admin':'manage';
        echo '<header><h1 class="manage-title">'.e(t($title)).'</h1><nav aria-label="'.e(t($title)).'">';
        if ($admin) echo link_to('/admin.php/config','config').' '.link_to('/admin.php/users','users').' '.link_to('/manage','manage');
        else echo link_to('/manage/settings','settings').' '.link_to('/manage/sections','sections').' '.link_to('/manage/appearance','appearance').' '.link_to('/manage/addons','addons').(is_admin()?' '.link_to('/admin.php','admin'):'');
        echo '</nav>';
        if (!$admin) { echo '<div class="actions">'.link_to('/'.$management['username'],'blog').'<form class="inline" method="post" action="/logout">';token_field();button('logout');echo '</form></div>'; }
        echo '</header>';
    }
    echo '<main id="main">'.($management?'<h2>':'<h1>').e(t($key)).($management?'</h2>':'</h1>');
    if ($error) echo '<p class="error" role="alert">'.e($error).'</p>';
    $content();
    if (isset($_SESSION['flash'])) { echo '<p class="feedback" role="status">'.e(t($_SESSION['flash'])).'</p>'; unset($_SESSION['flash']); }
    echo '</main></body></html>';
}

function value(string $key,string $default=''): string { return ($_SERVER['REQUEST_METHOD']==='POST') ? input($key) : $default; }

function icon_link(string $path,string $key,string $shape): void {
    $shapes=['plus'=>'M8 2v12M2 8h12','pencil'=>'M3 10l7-7 3 3-7 7-4 1zM9 4l3 3','trash'=>'M3 4h10M6 4V2h4v2M4 4l1 10h6l1-10','settings'=>'M8 1v2M8 13v2M1 8h2M13 8h2M3 3l2 2M11 11l2 2M3 13l2-2M11 5l2-2'];
    echo '<a href="'.e($path).'" aria-label="'.e(t($key)).'" title="'.e(t($key)).'"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="'.e($shapes[$shape]).'"/>'.($shape==='settings'?'<circle cx="8" cy="8" r="3"/>':'').'</svg></a>';
}

function confirmation(string $key,string $help,string $cancel,?array $management=null): void {
    page($key,function () use ($help,$cancel) {echo '<p>'.e(t($help)).'</p><form method="post">';token_field();echo '<div class="actions">'.link_to($cancel,'cancel');button('confirm');echo '</div></form>';},null,$management);
}

function admin_usernames(string $value): array {
    $names=array_values(array_unique(array_filter(array_map(static fn($name)=>strtolower(trim($name)),explode(',',$value)),static fn($name)=>$name!=='')));
    foreach ($names as $name) if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{1,22})[a-z0-9]\z/D',$name) || str_contains($name,'--') || in_array($name,reserved_names(),true)) throw new RuntimeException('Invalid admin username');
    return $names;
}
function config(bool $reload=false): array {
    static $config;
    if ($reload) $config=null;
    if ($config!==null) return $config;
    $file=getenv('BLOG_CONFIG') ?: __DIR__.'/config.ini';
    if (!str_starts_with($file,'/')) $file=__DIR__.'/'.$file;
    if (file_exists($file) && !is_file($file)) throw new RuntimeException('Invalid configuration file');
    $defaults=parse_ini_file(__DIR__.'/config.example.ini',true,INI_SCANNER_RAW);
    $settings=is_file($file) ? parse_ini_file($file,true,INI_SCANNER_RAW) : [];
    if ($defaults===false || $settings===false) throw new RuntimeException('Invalid configuration file');
    $values=array_replace_recursive($defaults,$settings);
    $result=$values;
    foreach (['site','admin','limits'] as $section) foreach ($defaults[$section] as $key=>$default) {
        $value=$values[$section][$key]??null;
        if (!is_string($value)) throw new RuntimeException('Invalid configuration value');
        $override=getenv('BLOG_'.strtoupper($key));
        if ($override!==false) $value=$override;
        $result[$key]=$value;
    }
    foreach (['site_name','site_description'] as $key) $result[$key]=strtr($result[$key],['\\"'=>'"','\\\\'=>'\\']);
    $result['allow_signups']=filter_var($result['allow_signups'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
    if ($result['allow_signups']===null) throw new RuntimeException('Invalid configured flag');
    $result['username']=admin_usernames($result['username']);
    $result['homepage']=strtolower(trim($result['homepage']));
    if ($result['homepage']!=='' && admin_usernames($result['homepage'])!==[$result['homepage']]) throw new RuntimeException('Invalid homepage username');
    foreach (['request_limit'=>[1,16777216],'body_limit'=>[1,16777216],'css_limit'=>[1,16777216],'post_limit'=>[1,1000000]] as $key=>[$min,$max]) {
        $result[$key]=filter_var($result[$key],FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
        if ($result[$key]===false) throw new RuntimeException('Invalid configured limit');
    }
    $result['base_url']=rtrim($result['base_url'],'/');
    $url=parse_url($result['base_url']);
    if (!$url || !in_array($url['scheme']??'',['http','https'],true) || empty($url['host']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) || !empty($url['path'])) throw new RuntimeException('Invalid base URL');
    $result['config_file']=$file;
    return $config=$result;
}
function update_settings(callable $action): mixed {
    $file=config()['config_file'];$lock=fopen($file.'.lock','c');$temp=null;
    if (!$lock) throw new RuntimeException('Cannot lock configuration');
    try {
        if (!flock($lock,LOCK_EX)) throw new RuntimeException('Cannot lock configuration');
        $fields=['username'=>'admin','site_name'=>'site','site_description'=>'site','allow_signups'=>'site','homepage'=>'site'];
        $settings=array_intersect_key(config(true),$fields);$original=$settings;
        if (!$settings['username'] && ($first=one('SELECT username FROM users ORDER BY id LIMIT 1'))) $settings['username']=[$first['username']];
        $source=file_get_contents(is_file($file)?$file:__DIR__.'/config.example.ini');
        if ($source===false) throw new RuntimeException('Cannot read configuration');
        $result=$action($settings);$changes=[];
        foreach ($fields as $key=>$group) {
            $value=$settings[$key]??null;
            if ($key==='username') {
                if (!is_array($value) || array_filter($value,static fn($name)=>!is_string($name)) || admin_usernames(implode(',',$value))!==$value) throw new RuntimeException('Invalid admin usernames');
                $encoded='"'.implode(', ',$value).'"';
            } elseif ($key==='allow_signups') {
                if (!is_bool($value)) throw new RuntimeException('Invalid configured flag');
                $encoded=$value?'true':'false';
            } else {
                if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || strpbrk($value,"\r\n\0")!==false || mb_strlen($value,'UTF-8')>($key==='site_name'?120:280) || ($key==='site_name' && trim($value)==='')) fail(422,'text_error');
                if ($key==='homepage' && $value!=='' && admin_usernames($value)!==[$value]) throw new RuntimeException('Invalid homepage username');
                $encoded='"'.str_replace(['\\','"'],['\\\\','\\"'],$value).'"';
            }
            if ($value!==$original[$key]) $changes[$group][$key]=$encoded;
        }
        if ($changes || !is_file($file)) {
            $out='';$section='';$seen=[];
            $append=static function () use(&$out,&$changes,&$section) {
                foreach ($changes[$section]??[] as $key=>$value) $out.=$key.' = '.$value."\n";
                unset($changes[$section]);
            };
            foreach (preg_split('/(?<=\n)/',$source) as $line) {
                if (preg_match('/^\h*\[([^\]\r\n]+)\]\h*(?:;[^\r\n]*)?(?:\r?\n)?$/',$line,$match)) { $append();$section=$match[1]; }
                if (preg_match('/^(\h*([a-z_]+)\h*=\h*)("(?:\\\\.|[^"\\\\])*"|[^;\r\n]*?)(\h*(?:;[^\r\n]*)?)(\r?\n)?$/',$line,$match) && isset($fields[$match[2]]) && $fields[$match[2]]===$section) {
                    $key=$match[2];
                    if (isset($seen[$key])) throw new RuntimeException('Duplicate configuration key');
                    $seen[$key]=true;
                    if (isset($changes[$section][$key])) { $line=$match[1].$changes[$section][$key].($match[4]??'').($match[5]??'');unset($changes[$section][$key]); }
                }
                $out.=$line;
            }
            if (!str_ends_with($out,"\n")) $out.="\n";
            $append();
            foreach ($changes as $section=>$values) { $out.="\n[$section]\n";foreach ($values as $key=>$value) $out.=$key.' = '.$value."\n"; }
            $temp=tempnam(dirname($file),'.config-');
            if ($temp===false || dirname($temp)!==dirname($file)) throw new RuntimeException('Cannot prepare configuration');
            if (file_put_contents($temp,$out)!==strlen($out) || !chmod($temp,0600) || !rename($temp,$file)) throw new RuntimeException('Cannot save configuration');
        }
        config(true);return $result;
    } finally {
        if ($temp && is_file($temp)) unlink($temp);
        flock($lock,LOCK_UN);fclose($lock);
    }
}
function reserved_names(): array {
    return ['admin','administrator','api','login','logout','register','signup','manage','assets','static','style','style.css','health','robots.txt','sitemap.xml','posts','new','edit','delete','preview','_addons','blog.php','manage.php','admin.php','favicon.ico','index.php','www','root','support','security','abuse','postmaster','webmaster','bin','src','config','storage','database','vendor','libraries','tests','.well-known'];
}

function section_reserved_names(): array { return array_merge(reserved_names(), ['inicio']); }

function t(string $key): string {
    if ($key==='brand') return config()['site_name'];
    if ($key==='intro') return config()['site_description'];
    static $strings;
    $strings ??= parse_ini_file(__DIR__.'/strings.ini', false, INI_SCANNER_RAW);
    if ($strings === false) throw new RuntimeException('Cannot read strings.ini');
    return $strings[$key] ?? throw new LogicException('Missing translation: '.$key);
}

function db(): PDO {
    static $db;
    if (!$db) {
        $db = new PDO('sqlite:' . BLOG_DATABASE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
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
    if (in_array('argon2id', password_algos(), true)) return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost'=>65536, 'time_cost'=>3, 'threads'=>1]);
    // Hash the complete Unicode password before bcrypt, avoiding its 72-byte truncation.
    return 'sha384:' . password_hash(base64_encode(hash('sha384', $password, true)), PASSWORD_BCRYPT, ['cost'=>12]);
}
function password_check(string $password, string $hash): bool {
    if (strlen($password) > 1024 || str_contains($password, "\0")) return false;
    if (str_starts_with($hash, 'sha384:')) return password_verify(base64_encode(hash('sha384', $password, true)), substr($hash, 7));
    return password_verify($password, $hash);
}
function start_session(): void {
    $c = config();
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1'); ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '86400');
    session_save_path(BLOG_SESSION_PATH);
    session_name(parse_url($c['base_url'], PHP_URL_SCHEME) === 'https' ? '__Host-blog_session' : 'blog_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>parse_url($c['base_url'], PHP_URL_SCHEME)==='https','httponly'=>true,'samesite'=>'Lax']);
    session_start();
    if (isset($_SESSION['issued']) && time() - $_SESSION['issued'] > 86400) { $_SESSION = []; session_regenerate_id(true); }
}
function current_user(): ?array {
    $user=isset($_SESSION['user_id']) ? one('SELECT * FROM users WHERE id=?', [$_SESSION['user_id']]) : null;
    return $user && (int)$user['auth_version']===(int)($_SESSION['auth_version']??0) ? $user : null;
}
function require_user(): array { return current_user() ?? throw new HttpError(401, t('login_required')); }
function registration_open(): bool { return config()['allow_signups']; }
function is_admin(?array $user=null): bool {
    $user??=current_user();if (!$user) return false;
    return config()['username'] ? in_array($user['username'],config()['username'],true) : ($user['user_id']??$user['id'])===(one('SELECT id FROM users ORDER BY id LIMIT 1')['id']??null);
}
function require_admin(): void { require_user();if (!is_admin()) fail(403,'forbidden'); }
function save_platform_home(string $name): void {
    require_admin();$name=$name===''?'':username($name);
    update_settings(static function (array &$settings) use($name) {
        require_admin();
        if ($name!=='' && !one("SELECT b.id FROM blogs b JOIN users u ON u.id=b.user_id WHERE u.username=? AND b.visibility='public'",[$name])) fail(422,'invalid');
        $settings['homepage']=$name;
    });
}
function authenticate(int $id): void { session_regenerate_id(true); $_SESSION = ['user_id'=>$id,'auth_version'=>(int)one('SELECT auth_version FROM users WHERE id=?',[$id])['auth_version'],'issued'=>time(),'csrf'=>bin2hex(random_bytes(32))]; }
function admin_user(int $id): array {
    require_admin();
    return one('SELECT u.*,b.id AS blog_id,b.visibility FROM users u JOIN blogs b ON b.user_id=u.id WHERE u.id=?',[$id]) ?? throw new HttpError(404,t('not_found'));
}
function set_user_admin(int $id,bool $enabled): void {
    require_admin();
    transaction(static function () use($id,$enabled) {
        update_settings(static function (array &$settings) use($id,$enabled) {
            $user=admin_user($id);
            if (!$enabled && $id===(int)require_user()['id']) fail(403,'forbidden');
            $settings['username']=$enabled
                ? array_values(array_unique([...$settings['username'],$user['username']]))
                : array_values(array_filter($settings['username'],static fn($name)=>$name!==$user['username']));
        });
    });
}
function admin_edit_user(int $id,string $name,string $password,string $confirm,string $visibility): void {
    require_admin();$name=username($name);
    if (!in_array($visibility,['public','private'],true)) fail(422,'invalid');
    $hash=null;
    if ($password!=='' || $confirm!=='') { validate_password($password,$confirm);$hash=password_make($password); }
    transaction(static function () use($id,$name,$hash,$visibility) {
        $user=admin_user($id);
        if (one('SELECT id FROM users WHERE username=? AND id<>?',[$name,$id]) || one('SELECT user_id FROM username_aliases WHERE username=? AND user_id<>?',[$name,$id])) fail(422,'username_taken');
        update_settings(static function (array &$settings) use($user,$id,$name,$hash,$visibility) {
            require_admin();
            if ($name!==$user['username']) {
                if ($settings['homepage']===$user['username']) $settings['homepage']=$name;
                query('DELETE FROM username_aliases WHERE username=? AND user_id=?',[$name,$id]);
                query('INSERT INTO username_aliases(username,user_id) VALUES (?,?)',[$user['username'],$id]);
                $settings['username']=array_values(array_unique(array_map(static fn($admin)=>$admin===$user['username']?$name:$admin,$settings['username'])));
            }
            query('UPDATE users SET username=?,password_hash=?,auth_version=auth_version+?,updated_at=? WHERE id=?',[$name,$hash??$user['password_hash'],$hash===null?0:1,now(),$id]);
            query('UPDATE blogs SET visibility=?,updated_at=? WHERE user_id=?',[$visibility,now(),$id]);
        });
    });
}
function admin_delete_user(int $id): void {
    require_admin();$admin=require_user();
    rate_limit('admin-delete',(string)$admin['id'],10,900);
    transaction(static function () use($id) {
        $user=admin_user($id);
        if (is_admin($user)) fail(403,'forbidden');
        update_settings(static function (array &$settings) use($id,$user) {
            require_admin();if (in_array($user['username'],$settings['username'],true)) fail(403,'forbidden');
            query('DELETE FROM users WHERE id=?',[$id]);
            if ($settings['homepage']===$user['username']) $settings['homepage']='';
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
    $path=explode('?',$_SERVER['REQUEST_URI']??'/',2)[0];
    $operational=preg_match('~\A(?:/(?:manage|admin\.php|login|register|signup|logout)(?:/|$)|/[^/]+/(?:new|edit|delete|preview)(?:/|$))~',$path);
    if ($operational || $preview || ($blog && $blog['visibility']==='private') || (http_response_code()?:200)>=400) send_header('X-Robots-Tag: noindex');
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
            if (!$byAdmin && in_array($name,$settings['username'],true)) fail(422,'username_taken');
            if (one('SELECT user_id FROM username_aliases WHERE username=?',[$name])) fail(422,'username_taken');
            $time=now();
            query('INSERT INTO users(username,password_hash,created_at,updated_at) VALUES (?,?,?,?)',[$name,$hash,$time,$time]);
            $id=(int)db()->lastInsertId();
            query('INSERT INTO blogs(user_id,title,created_at,updated_at) VALUES (?,?,?,?)',[$id,$name,$time,$time]);
            $blog=(int)db()->lastInsertId();
            query('INSERT INTO sections(blog_id,title,slug,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?)',[$blog,t('blogs'),'blogs',-1,$time,$time]);
            enable_default_addons($blog);
            if (!$settings['username']) $settings['username']=[$name];
            return ['id'=>$id,'username'=>$name];
        }));
    } catch (PDOException $e) {
        if (one('SELECT id FROM users WHERE username=?',[$name])) fail(422,'username_taken');
        throw $e;
    }
}
function section_list(int $blog): array { return query('SELECT * FROM sections WHERE blog_id=? ORDER BY sort_order,id',[$blog])->fetchAll(); }
function navigation_sections(int $blog): array {
    $order=one('SELECT home_order FROM blogs WHERE id=?',[$blog]);
    $sections=array_merge([
        ['id'=>'home','slug'=>'','title'=>t('home'),'sort_order'=>$order['home_order']],
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
        if ($id!=='home') owned_section($blog,(int)$id);
        $sections=navigation_sections($blog['id']);
        $index=array_search($id,array_column($sections,'id'),true); $other=$index+($direction==='up'?-1:1);
        if (isset($sections[$other])) [$sections[$index],$sections[$other]]=[$sections[$other],$sections[$index]];
        foreach ($sections as $order=>$section) {
            if ($section['id']==='home') query('UPDATE blogs SET home_order=?,updated_at=? WHERE id=?',[$order,now(),$blog['id']]);
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
function has_unsectioned_posts(int $blog): bool { return one('SELECT id FROM posts WHERE blog_id=? AND section_id IS NULL AND deleted_at IS NULL LIMIT 1',[$blog])!==null; }
function save_post(array $blog,?int $id,string $title,string $body,?int $section): int {
    $title=text_field($title,200); $body=markdown_field($body);
    return transaction(function () use ($blog,$id,$title,$body,$section) {
        if ($section) owned_section($blog,$section);
        $time=now();
        if ($id) {
            $post=owned_post($blog,$id); $slug=$post['slug'];
            if ($title!==$post['title'] || $post['section_id']!==$section) $slug=free_post_slug($blog['id'],$section,$title!==$post['title']?slug_base($title):$slug,$id);
            if ($slug!==$post['slug'] || $post['section_id']!==$section) {
                remember_path($blog['id'],namespace_path($post['section_id'],$post['slug']),$id);
                query('DELETE FROM redirects WHERE blog_id=? AND old_path=? AND post_id=?',[$blog['id'],namespace_path($section,$slug),$id]);
            }
            query('UPDATE posts SET title=?,body_markdown=?,section_id=?,slug=?,updated_at=? WHERE id=? AND blog_id=?',[$title,$body,$section,$slug,$time,$id,$blog['id']]);
            return $id;
        }
        if ((int)query('SELECT count(*) FROM posts WHERE blog_id=?',[$blog['id']])->fetchColumn() >= config()['post_limit']) fail(422,'post_limit');
        $slug=free_post_slug($blog['id'],$section,slug_base($title));
        query('INSERT INTO posts(blog_id,section_id,title,slug,body_markdown,created_at,updated_at,published_at) VALUES (?,?,?,?,?,?,?,?)',[$blog['id'],$section,$title,$slug,$body,$time,$time,$time]);
        return (int)db()->lastInsertId();
    });
}
function delete_post(array $blog,int $id): void {
    owned_post($blog,$id);
    query('UPDATE posts SET deleted_at=?,updated_at=? WHERE id=? AND blog_id=?',[now(),now(),$id,$blog['id']]);
}
function post_path(array $blog,array $post): string { return '/'.$blog['username'].'/'.namespace_path($post['section_id'],$post['slug']); }
function blog_home_path(array $blog): string { return config()['homepage']===$blog['username']?'/':'/'.$blog['username']; }
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
        if ($user && is_admin($user)) fail(403,'forbidden');
        if (!$user || !password_check($password,$user['password_hash']) || !hash_equals($user['username'],$confirmation)) fail(422,'delete_error');
        update_settings(static function (array &$settings) use($user,$blog) {
            if (in_array($user['username'],$settings['username'],true)) fail(403,'forbidden');
            query('DELETE FROM users WHERE id=?',[$user['id']]);
            if ($settings['homepage']===$user['username']) $settings['homepage']='';
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
function request_query(): string {
    $query=http_build_query($_GET,'','&',PHP_QUERY_RFC3986);
    return $query!==''?'?'.$query:'';
}
function sitemap_part(): int {
    $value=input('part',$_GET);
    if ($value!=='' && (!ctype_digit($value) || (int)$value<1 || (int)$value>1000000)) fail(400,'invalid');
    return max(1,(int)$value);
}
function sitemap_xml(iterable $entries,bool $index=false): void {
    headers_for();send_header('Content-Type: application/xml; charset=utf-8');
    $root=$index?'sitemapindex':'urlset';$tag=$index?'sitemap':'url';$count=0;$bytes=0;
    echo '<?xml version="1.0" encoding="UTF-8"?><'.$root.' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($entries as [$path,$modified]) {
        $xml='<'.$tag.'><loc>'.e(config()['base_url'].$path).'</loc>'.($modified?'<lastmod>'.e($modified).'</lastmod>':'').'</'.$tag.'>';
        if (++$count>50000 || ($bytes+=strlen($xml))>50000000) fail(503,'server_error');
        echo $xml;
    }
    echo '</'.$root.'>';
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
    if ($path!=='/' && str_ends_with($path,'/')) { methods(['GET']);redirect(rtrim($path,'/').request_query(),308); }
    if ($path==='/_addons' || str_starts_with($path,'/_addons/')) { addon_route(null,'platform',array_slice(explode('/',trim($path,'/')),1)); }
    elseif ($path==='/admin.php' || str_starts_with($path,'/admin.php/')) { require_once __DIR__.'/public/admin.php';route_admin($path); }
    elseif ($path==='/manage' || str_starts_with($path,'/manage/')) { require_once __DIR__.'/public/manage.php';route_manage($path); }
    elseif (in_array($path,['/','/register','/signup','/login','/logout','/robots.txt','/sitemap.xml'],true)) route_index($path);
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
        http_response_code(500);page('error',function () {echo '<p>'.e(t('server_error')).'</p>';});
    } finally { ob_end_flush(); }
}
