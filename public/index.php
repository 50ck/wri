<?php
declare(strict_types=1);
require dirname(__DIR__).'/app.php';
function route_index(string $path): void {
    if ($path==='/robots.txt') {
        methods(['GET']);headers_for();send_header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nAllow: /\n";
        $count=0;foreach (platform_sitemaps() as $entry) $count++;
        for ($part=1;$part<=max(1,(int)ceil($count/50000));$part++) echo 'Sitemap: '.config()['base_url'].'/sitemap.xml'.($part>1?'?part='.$part:'')."\n";
        return;
    }
    if ($path==='/sitemap.xml') {
        methods(['GET']);$part=sitemap_part();
        if (input('home',$_GET)==='1') {
            $blog=platform_home_blog();if ($blog && $blog['visibility']!=='public') fail(404,'not_found');
            sitemap_xml([['/',null]]);return;
        }
        $blog=platform_home_blog();
        if ($blog && blog_prefix($blog)==='') { require_once __DIR__.'/blog.php';can_read($blog);blog_sitemap($blog);return; }
        sitemap_xml((static function () use($part) {
            $start=($part-1)*50000;$found=false;
            foreach (platform_sitemaps() as $i=>$entry) {
                if ($i<$start) continue;if ($i>=$start+50000) break;
                $found=true;yield $entry;
            }
            if (!$found && $part>1) fail(404,'not_found');
        })(),true);return;
    }
    if ($path==='/') {
        methods(['GET']);
        $blog=platform_home_blog();
        if ($blog) { require_once __DIR__.'/blog.php';can_read($blog);render_blog($blog);return; }
        page('brand',function () {
            echo '<p>'.e(t('intro')).'</p>';
            echo '<p>'.link_to('/login','login');
            if (registration_open()) echo ' '.e(t('or')).' '.link_to('/register','sign_up');
            echo '</p>';addon_slot('home_after');
        });return;
    }
    if (in_array($path,['/register','/signup'],true)) {
        if (!registration_open()) fail(404,'not_found');methods(['GET','POST']);
        $error=submitted(function () {
            rate_limit('registration',$_SERVER['REMOTE_ADDR']??'',5,3600);
            $user=register_account(input('username'),input('new_password'),input('confirm_password'));
            authenticate($user['id']);redirect(blog_home_path(owned_blog()));
        });auth_form('register',$error);return;
    }
    if ($path==='/login') {
        methods(['GET','POST']);
        $error=submitted(function () {
            $name=strtolower(trim(input('username'))); $ip=$_SERVER['REMOTE_ADDR']??'';
            rate_limit('login-ip',$ip,30,900);rate_limit('login-name',$name,10,900);
            $user=one('SELECT * FROM users WHERE username=?',[$name]);
            // A fixed bcrypt hash gives unknown usernames a real password verification cost.
            $dummy='$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $valid=password_check(input('password'),$user['password_hash']??$dummy);
            if (!$user || !$valid) fail(422,'login_error');
            authenticate($user['id']);redirect(blog_home_path(owned_blog()));
        });auth_form('login',$error);return;
    }
    if ($path==='/logout') { methods(['POST']);check_csrf();logout();redirect('/'); }
    fail(404,'not_found');
}
function platform_home_blog(): ?array {
    return config()['homepage']!==''?one('SELECT b.*,u.username FROM blogs b JOIN users u ON u.id=b.user_id WHERE u.username=?',[config()['homepage']]):null;
}
function platform_sitemaps(): Generator {
    $home=platform_home_blog();
    if ($home && blog_prefix($home)==='') { yield ['/sitemap.xml',null];return; }
    if (!$home || $home['visibility']==='public') yield ['/sitemap.xml?home=1',null];
    foreach (query("SELECT u.username,count(p.id) AS posts FROM blogs b JOIN users u ON u.id=b.user_id LEFT JOIN posts p ON p.blog_id=b.id AND p.deleted_at IS NULL WHERE b.visibility='public' GROUP BY b.id ORDER BY b.id") as $blog) {
        $parts=max(1,(int)ceil($blog['posts']/10000));
        for ($part=1;$part<=$parts;$part++) yield ['/'.$blog['username'].'/sitemap.xml'.($parts>1?'?part='.$part:''),null];
    }
}
function auth_form(string $kind,?string $error=null,?array $management=null): void {
    $action=$management?'/admin.php/users/new':'/'.$kind;
    page($management?'create_user':$kind,function () use ($kind,$action) {
        echo '<form method="post" action="'.e($action).'">'; token_field();
        field('username','username',value('username',input('username',$_GET)),'text',true,24);
        if ($kind==='register') echo '<small>'.e(t('username_help')).'</small>';
        field($kind==='register'?'new_password':'password','password','','password');
        if ($kind==='register') { field('confirm_password','confirm_password','','password'); echo '<small>'.e(t('password_help')).'</small>'; }
        echo '<div class="actions">';button($action==='/admin.php/users/new'?'create_user':$kind);echo '</div></form>';
    },$error,$management);
}

run();
