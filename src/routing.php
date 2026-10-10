<?php
declare(strict_types=1);
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
    $uri=$_SERVER['REQUEST_URI'] ?? '/'; $path=explode('?',$uri,2)[0];
    if (strlen($path)>256 || !preg_match('~\A/[a-z0-9/._-]*\z~D',$path) || str_contains($path,'//') || str_contains($path,'..')) fail(404,'not_found');
    if ($path!=='/' && str_ends_with($path,'/')) { methods(['GET']);redirect(rtrim($path,'/'),308); }
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
    if ($path==='/manage' || str_starts_with($path,'/manage/')) {
        $blog=owned_blog();
        if ($path==='/manage') { methods(['GET']);redirect('/manage/sections'); }
        if ($path==='/manage/sections') { methods(['GET']);manage_sections($blog);return; }
        if ($path==='/manage/sections/new') {
            methods(['GET','POST']);$error=submitted(function () use($blog) {add_section($blog,input('title'),input('content'));redirect('/manage/sections',303,'section_created');});section_editor($blog,null,$error);return;
        }
        if (preg_match('~\A/manage/sections/([1-9][0-9]*)/(edit|delete|reorder)\z~',$path,$m)) {
            $section=owned_section($blog,(int)$m[1]);
            if ($m[2]==='edit') { methods(['GET','POST']);$error=submitted(function () use($blog,$section) {edit_section($blog,$section['id'],input('title'),input('content'));redirect('/manage/sections',303,'saved');});section_editor($blog,$section,$error);return; }
            if ($m[2]==='delete') { methods(['GET','POST']);submitted(function () use($blog,$section) {delete_section($blog,$section['id']);redirect('/manage/sections',303,'deleted');});confirmation('delete_section','delete_section_help','/manage/sections',$blog);return; }
            methods(['POST']);check_csrf();reorder_section($blog,$section['id'],input('direction'));redirect('/manage/sections',303,'saved');
        }
        if ($path==='/manage/appearance') {
            methods(['GET','POST']);$error=submitted(function () use($blog) {save_css($blog,input('css'));redirect('/manage/appearance',303,'saved');});manage_appearance($blog,$error);return;
        }
        if ($path==='/manage/appearance/restore') {
            methods(['GET','POST']);submitted(function () use($blog) {save_css($blog,'');redirect('/manage/appearance',303,'css_restored');});confirmation('restore','restore_help','/manage/appearance',$blog);return;
        }
        if ($path==='/manage/settings') {
            methods(['GET','POST']);$error=submitted(function () use($blog) {save_settings($blog,input('title'),input('description'),input('visibility'));redirect('/manage/settings',303,'saved');});manage_settings($blog,$error);return;
        }
        if ($path==='/manage/settings/delete') {
            methods(['GET','POST']);$error=submitted(function () use($blog) {delete_account($blog,input('password'),input('confirmation'));logout();redirect('/');});delete_blog_form($blog,$error);return;
        }
        fail(404,'not_found');
    }
    $parts=explode('/',trim($path,'/'));$name=$parts[0];
    if (in_array($name,reserved_names(),true)) fail(404,'not_found');
    $blog=one('SELECT b.*,u.username FROM blogs b JOIN users u ON u.id=b.user_id WHERE u.username=?',[$name]);
    if (!$blog) fail(404,'not_found');
    $tail=array_slice($parts,1);
    if (($tail[0]??'')==='new' || in_array($tail[0]??'',['edit','delete'],true)) {
        if (!is_owner($blog)) { require_user();fail(403,'forbidden'); }
        if ($tail===['new']) {
            methods(['GET','POST']);$error=submitted(function () use($blog) {
                rate_limit('post-new',(string)$blog['user_id'],30,3600);
                $section=input('section_id');if ($section!=='' && (!ctype_digit($section) || (int)$section<1)) fail(422,'invalid');
                $id=save_post($blog,null,input('title'),input('body'),$section!==''?(int)$section:null,input('status'));
                redirect(post_path($blog,owned_post($blog,$id)),303,input('status')==='published'?'published':'saved');
            });post_editor($blog,null,$error);return;
        }
        if (count($tail)===2 && ctype_digit($tail[1])) {
            $post=owned_post($blog,(int)$tail[1]);
            if ($tail[0]==='edit') {
                methods(['GET','POST']);$error=submitted(function () use($blog,$post) {
                    $section=input('section_id');if ($section!=='' && (!ctype_digit($section) || (int)$section<1)) fail(422,'invalid');
                    save_post($blog,$post['id'],input('title'),input('body'),$section!==''?(int)$section:null,input('status'));
                    redirect(post_path($blog,owned_post($blog,$post['id'])),303,input('status')==='published'?'published':'saved');
                });post_editor($blog,$post,$error);return;
            }
            methods(['GET','POST']);submitted(function () use($blog,$post) {delete_post($blog,$post['id']);redirect('/'.$blog['username'],303,'deleted');});confirmation('delete_post','delete_post_help',post_path($blog,$post));return;
        }
        fail(404,'not_found');
    }
    methods(['GET']);can_read($blog);
    if (!$tail) { render_blog($blog);return; }
    if ($tail===['style.css']) {
        headers_for($blog);send_header('Content-Type: text/css; charset=utf-8');echo $blog['custom_css'] ?: file_get_contents(dirname(__DIR__).'/public/assets/blog.css');return;
    }
    if ($tail===['preview']) { if (!is_owner($blog)) fail(403,'forbidden');render_blog($blog,null,null,true);return; }
    if (count($tail)===1) {
        $section=one('SELECT * FROM sections WHERE blog_id=? AND slug=?',[$blog['id'],$tail[0]]);
        if (!$section) fail(404,'not_found');render_blog($blog,$section);return;
    }
    if (count($tail)===2) {
        $section=$tail[0]==='posts'?null:one('SELECT * FROM sections WHERE blog_id=? AND slug=?',[$blog['id'],$tail[0]]);
        $post=($section || $tail[0]==='posts') ? one('SELECT * FROM posts WHERE blog_id=? AND section_id IS ? AND slug=? AND deleted_at IS NULL',[$blog['id'],$section['id']??null,$tail[1]]) : null;
        if (!$post) {
            $old=one('SELECT p.* FROM redirects r JOIN posts p ON p.id=r.post_id WHERE r.blog_id=? AND r.old_path=? AND p.deleted_at IS NULL',[$blog['id'],implode('/',$tail)]);
            if ($old && ($old['status']==='published'||is_owner($blog))) redirect(post_path($blog,$old),302);
            fail(404,'not_found');
        }
        if ($post['status']!=='published' && !is_owner($blog)) fail(404,'not_found');
        render_blog($blog,$section,$post);return;
    }
    fail(404,'not_found');
}
