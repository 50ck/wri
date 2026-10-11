<?php
declare(strict_types=1);
function config(bool $reload=false): array {
    static $config;
    if ($reload) $config=null;
    if ($config !== null) return $config;
    $root = __DIR__;
    $config = [
        'site_name' => getenv('BLOG_SITE_NAME') ?: 'wri',
        'site_description' => getenv('BLOG_SITE_DESCRIPTION') ?: 'a little place to write on the internet',
        'base_url' => rtrim(getenv('BLOG_BASE_URL') ?: 'http://localhost:8080', '/'),
        'database' => getenv('BLOG_DATABASE') ?: "$root/storage/blog.sqlite",
        'session_path' => getenv('BLOG_SESSION_PATH') ?: "$root/storage/sessions",
        'registration' => getenv('BLOG_REGISTRATION') !== '0',
        'admin_user_id' => null,
        'admin_initialized' => false,
        'homepage_blog_id' => null,
        'allow_signups' => true,
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
    $config['settings_file'] ??= getenv('BLOG_SETTINGS_FILE') ?: dirname($config['database']).'/config.json';
    if (is_file($config['settings_file'])) {
        $settings=json_decode(file_get_contents($config['settings_file']),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($settings) || array_diff(array_keys($settings),['admin_user_id','admin_initialized','homepage_blog_id','allow_signups'])) throw new RuntimeException('Invalid platform configuration');
        $config=array_replace($config,$settings);
    }
    foreach (['admin_user_id','homepage_blog_id'] as $key) if ($config[$key]!==null && (!is_int($config[$key]) || $config[$key]<1)) throw new RuntimeException('Invalid configured ID');
    foreach (['admin_initialized','allow_signups'] as $key) if (!is_bool($config[$key])) throw new RuntimeException('Invalid configured flag');
    $url = parse_url($config['base_url']);
    if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) || !empty($url['path'])) throw new RuntimeException('Invalid base URL; use an origin without a path.');
    return $config;
}
function update_settings(callable $action): mixed {
    $file=config()['settings_file'];$lock=fopen($file.'.lock','c');$temp=null;
    if (!$lock) throw new RuntimeException('Cannot lock configuration');
    try {
        if (!flock($lock,LOCK_EX)) throw new RuntimeException('Cannot lock configuration');
        $settings=array_intersect_key(config(true),array_flip(['admin_user_id','admin_initialized','homepage_blog_id','allow_signups']));
        $original=$settings;
        if (file_exists($file) && !is_file($file)) throw new RuntimeException('Invalid configuration file');
        $temp=tempnam(dirname($file),'.config-');
        if ($temp===false || dirname($temp)!==dirname($file)) throw new RuntimeException('Cannot prepare configuration');
        $result=$action($settings);
        if ($settings!==$original || !is_file($file)) {
            $json=json_encode($settings,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
            if (file_put_contents($temp,$json)!==strlen($json) || !chmod($temp,0600) || !rename($temp,$file)) throw new RuntimeException('Cannot save configuration');
        }
        config(true);return $result;
    } finally {
        if ($temp && is_file($temp)) unlink($temp);
        flock($lock,LOCK_UN);fclose($lock);
    }
}
function reserved_names(): array {
    return ['admin','administrator','api','login','logout','register','signup','manage','assets','static','style','style.css','health','robots.txt','posts','new','edit','delete','preview','_addons','blog.php','manage.php','favicon.ico','index.php','www','root','support','security','abuse','postmaster','webmaster','bin','src','config','storage','database','vendor','libraries','tests','.well-known'];
}

function section_reserved_names(): array { return array_merge(reserved_names(), ['inicio','blogs']); }

function t(string $key): string {
    if ($key==='brand') return config()['site_name'];
    if ($key==='intro') return config()['site_description'];
    static $strings;
    $strings ??= parse_ini_file(__DIR__.'/strings.txt', false, INI_SCANNER_RAW);
    if ($strings === false) throw new RuntimeException('Cannot read strings.txt');
    return $strings[$key] ?? throw new LogicException('Missing translation: '.$key);
}
