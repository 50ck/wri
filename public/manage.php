<?php
declare(strict_types=1);
function route_manage(string $path): void {
    if ($path==='/manage' || str_starts_with($path,'/manage/')) {
        $blog=owned_blog();
        if ($path==='/manage') { methods(['GET']);redirect('/manage/settings'); }
        if ($path==='/manage/admin/signups') {
            require_admin();methods(['GET','POST']);$enabled=input('enabled',$_SERVER['REQUEST_METHOD']==='POST'?$_POST:$_GET);
            if (!in_array($enabled,['0','1'],true)) fail(400,'invalid');
            $error=submitted(function () use($enabled) {query('UPDATE platform SET allow_signups=? WHERE id=1',[(int)$enabled]);redirect('/manage/admin',303,'saved');});
            page($enabled==='1'?'allow_signups':'close_signups',function () use($enabled) {
                echo '<p>'.e(t('restore_help')).'</p><form method="post">';token_field();
                echo '<input type="hidden" name="enabled" value="'.$enabled.'"><div class="actions">'.link_to('/manage/admin','cancel');button('confirm');echo '</div></form>';
            },$error,$blog);return;
        }
        if ($path==='/manage/admin/users/new') {
            require_admin();methods(['GET','POST']);
            $error=submitted(function () {register_account(input('username'),input('new_password'),input('confirm_password'),true);redirect('/manage/admin',303,'saved');});
            auth_form('register',$error,$blog);return;
        }
        if (preg_match('~\A/manage/admin/users/([1-9][0-9]*)/(edit|delete)\z~',$path,$m)) {
            require_admin();methods(['GET','POST']);$user=admin_user((int)$m[1]);$self=$user['id']===$blog['user_id'];
            if ($m[2]==='delete' && $self) fail(403,'forbidden');
            if ($m[2]==='edit') {
                $error=submitted(function () use($user,$self) {
                    admin_edit_user($user['id'],input('username'),input('new_password'),input('confirm_password'),input('visibility'));
                    if ($self && input('new_password')!=='') authenticate($user['id']);
                    redirect('/manage/admin',303,'saved');
                });admin_user_editor($blog,$user,$error);return;
            }
            $error=submitted(function () use($user) {
                admin_delete_user($user['id'],input('password'),input('confirmation'));
                redirect('/manage/admin',303,'deleted');
            });admin_user_delete($blog,$user,$error);return;
        }
        if ($path==='/manage/admin/homepage') {
            require_admin();methods(['GET']);$id=input('blog_id',$_GET);
            if ($id!=='' && (!ctype_digit($id) || !one("SELECT id FROM blogs WHERE id=? AND visibility='public'",[$id]))) fail(422,'invalid');
            page('homepage',function () use($id) {
                echo '<p>'.e(t('restore_help')).'</p><form method="post" action="/manage/admin">';token_field();
                echo '<input type="hidden" name="blog_id" value="'.e($id).'"><div class="actions">'.link_to('/manage/admin','cancel');button('confirm');echo '</div></form>';
            },null,$blog);return;
        }
        if ($path==='/manage/admin') {
            require_admin();methods(['GET','POST']);
            $error=submitted(function () {save_platform_home(input('blog_id'));redirect('/manage/admin',303,'saved');});
            manage_admin($blog,$error);return;
        }
        if ($path==='/manage/addons') {
            methods(['GET','POST']);$error=submitted(function () use($blog) {save_addons($blog,$_POST['addons']??[]);redirect('/manage/addons',303,'saved');});manage_addons($blog,$error);return;
        }
        if (str_starts_with($path,'/manage/addons/')) { addon_route($blog,'manage',explode('/',substr($path,15)));return; }
        if ($path==='/manage/sections') { methods(['GET']);manage_sections($blog);return; }
        if ($path==='/manage/sections/home') {
            methods(['GET','POST']);$error=submitted(function () use($blog) {save_home($blog,input('content'));redirect('/'.$blog['username'],303,'saved');});home_editor($blog,$error);return;
        }
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
            if (is_admin()) fail(403,'forbidden');
            methods(['GET','POST']);$error=submitted(function () use($blog) {rate_limit('reauth',(string)$blog['user_id'],10,900);delete_account($blog,input('password'),input('confirmation'));logout();redirect('/');});delete_blog_form($blog,$error);return;
        }
        fail(404,'not_found');
    }
}
