<?php
declare(strict_types=1);
require dirname(__DIR__).'/app.php';
function route_index(string $path): void {
    if ($path==='/') {
        methods(['GET']);
        $home=platform_settings()['homepage_blog_id'];
        if ($home!==null) {
            $blog=one('SELECT b.*,u.username FROM blogs b JOIN users u ON u.id=b.user_id WHERE b.id=?',[$home]);
            if (!$blog) fail(404,'unavailable');
            can_read($blog);render_blog($blog);return;
        }
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
            authenticate($user['id']);redirect('/'.$user['username']);
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
            authenticate($user['id']);redirect('/'.$user['username']);
        });auth_form('login',$error);return;
    }
    if ($path==='/logout') { methods(['POST']);check_csrf();logout();redirect('/'); }
    fail(404,'not_found');
}
run();
