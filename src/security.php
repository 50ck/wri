<?php
declare(strict_types=1);
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
    if (!mb_check_encoding($password, 'UTF-8') || mb_strlen($password, 'UTF-8') < 12 || strlen($password) > 1024 || str_contains($password, "\0")) fail(422, 'password_error');
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
