<?php
declare(strict_types=1);
function config(): array {
    static $config;
    if ($config !== null) return $config;
    $root = dirname(__DIR__);
    $config = [
        'base_url' => rtrim(getenv('BLOG_BASE_URL') ?: 'http://localhost:8080', '/'),
        'database' => getenv('BLOG_DATABASE') ?: "$root/storage/blog.sqlite",
        'session_path' => getenv('BLOG_SESSION_PATH') ?: "$root/storage/sessions",
        'registration' => getenv('BLOG_REGISTRATION') !== '0',
        'debug' => getenv('BLOG_DEBUG') === '1',
        'request_limit' => (int)(getenv('BLOG_REQUEST_LIMIT') ?: 262144),
        'body_limit' => (int)(getenv('BLOG_BODY_LIMIT') ?: 131072),
        'css_limit' => (int)(getenv('BLOG_CSS_LIMIT') ?: 65536),
        'post_limit' => (int)(getenv('BLOG_POST_LIMIT') ?: 10000),
        'session_lifetime' => (int)(getenv('BLOG_SESSION_LIFETIME') ?: 86400),
        'argon_memory' => (int)(getenv('BLOG_ARGON_MEMORY') ?: 65536),
        'argon_time' => (int)(getenv('BLOG_ARGON_TIME') ?: 3),
        'bcrypt_cost' => (int)(getenv('BLOG_BCRYPT_COST') ?: 12),
    ];
    $private = getenv('BLOG_CONFIG');
    if ($private) $config = array_replace($config, require $private);
    $url = parse_url($config['base_url']);
    if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) || !empty($url['path'])) throw new RuntimeException('Invalid base URL; use an origin without a path.');
    return $config;
}
function reserved_names(): array {
    return ['admin','administrator','api','login','logout','register','manage','assets','static','style','style.css','health','robots.txt','posts','new','edit','delete','preview','favicon.ico','index.php','www','root','support','security','abuse','postmaster','webmaster','bin','src','config','storage','database','vendor','tests','.well-known'];
}

function section_reserved_names(): array { return array_merge(reserved_names(), ['inicio','blogs']); }
