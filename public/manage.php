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
        if ($path==='/manage/sections/new') {
            methods(['GET','POST']);$error=submitted(function () use($blog) {add_section($blog,input('title'),input('content'));redirect('/manage/sections',303,'section_created');});section_editor($blog,null,$error);return;
        }
        if (preg_match('~\A/manage/sections/([1-9][0-9]*)/(edit|delete|reorder|home)\z~',$path,$m)) {
            $section=owned_section($blog,(int)$m[1]);
            if ($m[2]==='edit') {
                methods(['GET','POST']);$return=input('from',$_GET)==='section'?section_path($blog,$section):'/manage/sections';
                $error=submitted(function () use($blog,$section,$return) {edit_section($blog,$section['id'],input('title'),input('content'));redirect($return,303,$return==='/manage/sections'?'saved':null);});
                section_editor($blog,$section,$error,$return);return;
            }
            if ($m[2]==='delete') { methods(['GET','POST']);submitted(function () use($blog,$section) {delete_section($blog,$section['id']);redirect('/manage/sections',303,'deleted');});confirmation('delete_section','delete_section_help','/manage/sections',$blog);return; }
            if ($m[2]==='home') { methods(['GET','POST']);submitted(function () use($blog,$section) {set_home_section($blog,$section['id']);redirect('/manage/sections',303,'saved');});confirmation('set_home','restore_help','/manage/sections',$blog);return; }
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

function section_editor(array $blog,?array $section,?string $error=null,string $return='/manage/sections'): void {
    page($section?'edit_section':'new_section',function () use ($section,$return) {
        echo '<form method="post">';token_field();field('title','title',value('title',$section['title']??''),'text',true,120);area('content','content',value('content',$section['content_markdown']??''));
        echo '<div class="actions">';button('save');echo link_to($return,'cancel').'</div></form>';
    },$error,$blog);
}

function manage_sections(array $blog): void {
    page('sections',function () use ($blog) {
        $sections=section_list($blog['id']);$home=home_section($blog)['id']??null;echo '<ul class="section-list">';
        foreach ($sections as $i=>$section) {
            $path='/manage/sections/'.$section['id'];
            echo '<li><a href="'.e(section_path($blog,$section)).'">'.e($section['title']).'</a>';
            echo ' <form class="inline section-actions" method="post" action="'.$path.'/reorder">';token_field();
            if ($i>0) reorder_button('up');if ($i<count($sections)-1) reorder_button('down');echo '</form>';
            echo ' <span class="section-actions">'.link_to($path.'/edit','edit').' '.link_to($path.'/delete','delete');
            if ($section['id']!==$home) echo ' '.link_to($path.'/home','set_home');
            echo '</span>';
            echo '</li>';
        } echo '</ul>';if (count($sections)<10) echo link_to('/manage/sections/new','new_section');
    },null,$blog);
}

function reorder_button(string $direction): void {
    $path=$direction==='up'?'M8 14V2M3 7l5-5 5 5':'M8 2v12M3 9l5 5 5-5';
    echo '<button class="icon" type="submit" name="direction" value="'.$direction.'" aria-label="'.e(t($direction)).'" title="'.e(t($direction)).'"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="'.$path.'"/></svg></button> ';
}

function manage_appearance(array $blog,?string $error=null): void {
    page('appearance',function () use ($blog) {
        echo '<form method="post" action="/manage/appearance">';token_field();area('css','css',value('css',$blog['custom_css']),true);echo '<div class="actions">';button('apply');echo link_to('/manage/appearance/restore','restore').' '.link_to('/assets/blog.css','show_original_css').'</div></form>';
    },$error,$blog);
}

function manage_settings(array $blog,?string $error=null): void {
    page('settings',function () use ($blog) {
        echo '<form method="post">';token_field();field('title','title',value('title',$blog['title']),'text',true,120);field('description','description',value('description',$blog['description']),'text',false,280);
        echo '<label for="visibility">'.e(t('visibility')).'</label><select name="visibility" id="visibility">';
        foreach (['public','private'] as $state) echo '<option value="'.$state.'"'.(value('visibility',$blog['visibility'])===$state?' selected':'').'>'.e(t($state)).'</option>';
        echo '</select><div class="actions">';button('save');echo '</div></form>';
        if (!is_admin()) echo '<p>'.link_to('/manage/settings/delete','delete_blog').'</p>';
    },$error,$blog);
}

function delete_blog_form(array $blog,?string $error=null): void {
    page('delete_blog',function () {
        echo '<form method="post">';token_field();field('password','password','','password');field('confirmation','type_username',value('confirmation'),'text');echo '<div class="actions">'.link_to('/manage/settings','cancel');button('delete_permanently');echo '</div></form>';
    },$error,$blog);
}

function manage_addons(array $blog,?string $error=null): void {
    page('addons',function () use ($blog) {
        $installed=addons('user');$active=active_addons($blog);
        if (!$installed) { echo '<p>'.e(t('addons_empty')).'</p>';return; }
        echo '<form method="post" action="/manage/addons">';token_field();
        foreach ($installed as $id=>$addon) echo '<p><label><input type="checkbox" name="addons[]" value="'.e($id).'"'.(isset($active[$id])?' checked':'').'> '.e($addon['label']['en']).'</label></p>';
        echo '<div class="actions">';button('save');echo '</div></form>';
    },$error,$blog);
}
