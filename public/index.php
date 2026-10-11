<?php
declare(strict_types=1);
require dirname(__DIR__).'/app.php';
function route_index(string $path): void {
    if ($path==='/') {
        methods(['GET']);
        $error=null;
        if (isset($_GET['username'])) { try { $name=username(input('username',$_GET));redirect('/register?username='.rawurlencode($name)); } catch(HttpError $e) { $error=$e->getMessage();http_response_code($e->status); } }
        page('brand',function () {
            echo '<p>'.e(t('intro')).'</p>';
            if (config()['registration']) { echo '<form method="get" action="/"><p>'.e(parse_url(config()['base_url'],PHP_URL_HOST)).'/</p>';field('username','username',input('username',$_GET),'text',true,24);echo '<div class="actions">';button('create_blog');echo '</div></form>'; }
            echo '<p>'.e(t('have_account')).' '.link_to('/login','login').'</p>';
        },$error);return;
    }
    if ($path==='/register') {
        methods(['GET','POST']);if (!config()['registration']) fail(403,'registration_closed');
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
