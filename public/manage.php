<?php
declare(strict_types=1);
function route_manage(string $path): void {
    if ($path==='/manage' || str_starts_with($path,'/manage/')) {
        $blog=owned_blog();
        if ($path==='/manage') { methods(['GET']);redirect('/manage/settings'); }
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
            methods(['GET','POST']);$error=submitted(function () use($blog) {rate_limit('reauth',(string)$blog['user_id'],10,900);delete_account($blog,input('password'),input('confirmation'));logout();redirect('/');});delete_blog_form($blog,$error);return;
        }
        fail(404,'not_found');
    }
}
