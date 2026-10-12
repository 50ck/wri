<?php
declare(strict_types=1);
function route_admin(string $path): void {
    require_admin();$blog=owned_blog();
    if ($path==='/admin.php') { methods(['GET']);redirect('/admin.php/config'); }
    if ($path==='/admin.php/config') {
        methods(['GET']);
        page('config',function () {
            echo '<ul class="section-list"><li>'.link_to('/admin.php/config/site','edit_site').'</li><li>'.link_to('/admin.php/config/signups?enabled='.(config()['allow_signups']?'0':'1'),config()['allow_signups']?'close_signups':'allow_signups').'</li></ul>';
        },null,$blog);return;
    }
    if ($path==='/admin.php/config/site') {
        methods(['GET','POST']);
        $error=submitted(function () {
            $name=text_field(input('site_name'),120);$description=text_field(input('site_description'),280,false);
            update_settings(static function (array &$settings) use($name,$description) {
                require_admin();$settings['site_name']=$name;$settings['site_description']=$description;
            },'site');redirect('/admin.php/config',303,'saved');
        });
        page('edit_site',function () {
            echo '<form method="post">';token_field();
            field('site_name','site_name',value('site_name',config()['site_name']),'text',true,120);
            field('site_description','site_description',value('site_description',config()['site_description']),'text',false,280);
            echo '<div class="actions">';button('save');echo link_to('/admin.php/config','cancel').'</div></form>';
        },$error,$blog);return;
    }
    if ($path==='/admin.php/config/signups') {
        methods(['GET','POST']);$enabled=input('enabled',$_SERVER['REQUEST_METHOD']==='POST'?$_POST:$_GET);
        if (!in_array($enabled,['0','1'],true)) fail(400,'invalid');
        $error=submitted(function () use($enabled) {update_settings(static function (array &$settings) use($enabled) {require_admin();$settings['allow_signups']=$enabled==='1';});redirect('/admin.php/config',303,'saved');});
        page($enabled==='1'?'allow_signups':'close_signups',function () use($enabled) {
            echo '<p>'.e(t('restore_help')).'</p><form method="post">';token_field();
            echo '<input type="hidden" name="enabled" value="'.$enabled.'"><div class="actions">'.link_to('/admin.php/config','cancel');button('confirm');echo '</div></form>';
        },$error,$blog);return;
    }
    if ($path==='/admin.php/users/new') {
        methods(['GET','POST']);
        $error=submitted(function () {register_account(input('username'),input('new_password'),input('confirm_password'),true);redirect('/admin.php/users',303,'saved');});
        auth_form('register',$error,$blog);return;
    }
    if (preg_match('~\A/admin\.php/users/([1-9][0-9]*)/(edit|delete)\z~',$path,$m)) {
        methods(['GET','POST']);$user=admin_user((int)$m[1]);$self=$user['id']===$blog['user_id'];
        if ($m[2]==='delete' && $self) fail(403,'forbidden');
        if ($m[2]==='edit') {
            $error=submitted(function () use($user,$self) {
                admin_edit_user($user['id'],input('username'),input('new_password'),input('confirm_password'),input('visibility'));
                if ($self && input('new_password')!=='') authenticate($user['id']);
                redirect('/admin.php/users',303,'saved');
            });admin_user_editor($blog,$user,$error);return;
        }
        $error=submitted(function () use($user) {
            admin_delete_user($user['id']);
            redirect('/admin.php/users',303,'deleted');
        });admin_user_delete($blog,$user,$error);return;
    }
    if ($path==='/admin.php/users/homepage') {
        methods(['GET']);$id=input('blog_id',$_GET);
        if ($id!=='' && (!ctype_digit($id) || !one("SELECT id FROM blogs WHERE id=? AND visibility='public'",[$id]))) fail(422,'invalid');
        page('homepage',function () use($id) {
            echo '<p>'.e(t('restore_help')).'</p><form method="post" action="/admin.php/users">';token_field();
            echo '<input type="hidden" name="blog_id" value="'.e($id).'"><div class="actions">'.link_to('/admin.php/users','cancel');button('confirm');echo '</div></form>';
        },null,$blog);return;
    }
    if ($path==='/admin.php/users') {
        methods(['GET','POST']);
        $error=submitted(function () {save_platform_home(input('blog_id'));redirect('/admin.php/users',303,'saved');});
        admin_users($blog,$error);return;
    }
    fail(404,'not_found');
}

function admin_users(array $blog,?string $error=null): void {
    page('users',function () {
        $platform=config();$home=$platform['homepage_blog_id'];
        if ($home!==null) echo '<ul class="section-list"><li>'.link_to('/admin.php/users/homepage','use_platform_home').'</li></ul>';
        $number=input('page',$_GET);
        if ($number!=='' && (!ctype_digit($number) || (int)$number<1 || (int)$number>10000)) fail(400,'invalid');
        $page=max(1,(int)$number);
        $users=query('SELECT u.id AS user_id,u.username,b.id,b.visibility FROM users u JOIN blogs b ON b.user_id=u.id ORDER BY u.id LIMIT 51 OFFSET ?',[($page-1)*50])->fetchAll();
        $more=count($users)>50;echo '<p>'.link_to('/admin.php/users/new','create_user').'</p><ul class="section-list">';
        foreach (array_slice($users,0,50) as $user) {
            echo '<li><a href="/'.e($user['username']).'">'.e($user['username']).'</a>';
            echo ' <span class="section-actions">';
            icon_link('/admin.php/users/'.$user['user_id'].'/edit','edit','pencil');
            if (!is_admin($user)) { echo ' ';icon_link('/admin.php/users/'.$user['user_id'].'/delete','delete','trash'); }
            if ($user['visibility']==='public' && (int)$user['id']!==(int)$home) {
                echo ' '.link_to('/admin.php/users/homepage?blog_id='.$user['id'],'use_blog_home');
            } elseif ($user['visibility']==='private') echo ' '.e(t('private'));
            echo '</span></li>';
        }
        echo '</ul><nav>';
        if ($page>1) echo link_to('/admin.php/users?page='.($page-1),'previous');
        if ($more) echo link_to('/admin.php/users?page='.($page+1),'next');
        echo '</nav>';
    },$error,$blog);
}

function admin_user_editor(array $blog,array $user,?string $error=null): void {
    page('edit_user',function () use($user) {
        echo '<form method="post">';token_field();
        field('username','username',value('username',$user['username']),'text',true,24);
        field('new_password','new_password','','password',false);
        field('confirm_password','confirm_password','','password',false);
        echo '<small>'.e(t('password_unchanged')).'</small><label for="visibility">'.e(t('visibility')).'</label><select name="visibility" id="visibility">';
        foreach (['public','private'] as $state) echo '<option value="'.$state.'"'.(value('visibility',$user['visibility'])===$state?' selected':'').'>'.e(t($state)).'</option>';
        echo '</select><div class="actions">';button('save');echo link_to('/admin.php/users','cancel').'</div></form>';
    },$error,$blog);
}

function admin_user_delete(array $blog,array $user,?string $error=null): void {
    page('delete_user',function () use($user) {
        echo '<form method="post">';token_field();
        echo '<div class="actions">'.link_to('/admin.php/users','cancel').'<button type="submit">'.e(str_replace('{username}',$user['username'],t('delete_user_button'))).'</button></div></form>';
    },$error,$blog);
}
