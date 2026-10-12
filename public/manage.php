<?php
declare(strict_types=1);
function route_manage(string $path): void {
    if ($path==='/manage' || str_starts_with($path,'/manage/')) {
        $blog=owned_blog();
        if ($path==='/manage') { methods(['GET']);redirect('/manage/settings'); }
        if ($path==='/manage/admin/signups') {
            require_admin();methods(['GET','POST']);$enabled=input('enabled',$_SERVER['REQUEST_METHOD']==='POST'?$_POST:$_GET);
            if (!in_array($enabled,['0','1'],true)) fail(400,'invalid');
            $error=submitted(function () use($enabled) {update_settings(static function (array &$settings) use($enabled) {require_admin();$settings['allow_signups']=$enabled==='1';});redirect('/manage/admin',303,'saved');});
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
                admin_delete_user($user['id']);
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
        if (preg_match('~\A/manage/sections/(home|blogs)/reorder\z~',$path,$m)) {
            methods(['POST']);check_csrf();reorder_section($blog,$m[1],input('direction'));redirect('/manage/sections',303,'saved');
        }
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

function section_editor(array $blog,?array $section,?string $error=null): void {
    page($section?'edit_section':'new_section',function () use ($section) {
        echo '<form method="post">';token_field();field('title','title',value('title',$section['title']??''),'text',true,120);area('content','intro_content',value('content',$section['content_markdown']??''));
        echo '<div class="actions">';button('save');echo link_to('/manage/sections','cancel').'</div></form>';
    },$error,$blog);
}

function manage_sections(array $blog): void {
    page('sections',function () use ($blog) {
        $sections=navigation_sections($blog['id']);echo '<ul class="section-list">';
        foreach ($sections as $i=>$section) {
            $path='/manage/sections/'.$section['id'];
            echo '<li><a href="/'.e($blog['username']).($section['slug']!==''?'/'.e($section['slug']):'').'">'.e($section['title']).'</a>';
            echo ' <form class="inline section-actions" method="post" action="'.$path.'/reorder">';token_field();
            if ($i>0) reorder_button('up');if ($i<count($sections)-1) reorder_button('down');echo '</form>';
            if ($section['id']==='home') echo ' <span class="section-actions">'.link_to('/manage/sections/home','edit').'</span>';
            elseif ($section['id']!=='blogs') echo ' <span class="section-actions">'.link_to($path.'/edit','edit').' '.link_to($path.'/delete','delete').'</span>';
            echo '</li>';
        } echo '</ul>';if (count($sections)<12) echo link_to('/manage/sections/new','new_section');
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

function home_editor(array $blog,?string $error=null): void {
    page('edit_home',function () use ($blog) {
        echo '<form method="post">';token_field();area('content','content',value('content',$blog['home_markdown']));
        echo '<div class="actions">';button('save');echo link_to('/'.$blog['username'],'cancel').'</div></form>';
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

function manage_admin(array $blog,?string $error=null): void {
    page('admin',function () {
        $platform=platform_settings();$home=$platform['homepage_blog_id'];
        echo '<ul class="section-list"><li>'.link_to('/manage/admin/signups?enabled='.($platform['allow_signups']?'0':'1'),$platform['allow_signups']?'close_signups':'allow_signups').'</li>';
        if ($home!==null) echo '<li>'.link_to('/manage/admin/homepage','use_platform_home').'</li>';
        echo '</ul>';
        echo '<h3>'.e(t('users')).'</h3>';
        $number=input('page',$_GET);
        if ($number!=='' && (!ctype_digit($number) || (int)$number<1 || (int)$number>10000)) fail(400,'invalid');
        $page=max(1,(int)$number);
        $users=query('SELECT u.id AS user_id,u.username,b.id,b.visibility FROM users u JOIN blogs b ON b.user_id=u.id ORDER BY u.id LIMIT 51 OFFSET ?',[($page-1)*50])->fetchAll();
        $more=count($users)>50;echo '<p>'.link_to('/manage/admin/users/new','create_user').'</p><ul class="section-list">';
        foreach (array_slice($users,0,50) as $user) {
            echo '<li><a href="/'.e($user['username']).'">'.e($user['username']).'</a>';
            echo ' <span class="section-actions">';
            icon_link('/manage/admin/users/'.$user['user_id'].'/edit','edit','pencil');
            if ((int)$user['user_id']!==(int)$platform['admin_user_id']) { echo ' ';icon_link('/manage/admin/users/'.$user['user_id'].'/delete','delete','trash'); }
            if ($user['visibility']==='public' && (int)$user['id']!==(int)$home) {
                echo ' '.link_to('/manage/admin/homepage?blog_id='.$user['id'],'use_blog_home');
            } elseif ($user['visibility']==='private') echo ' '.e(t('private'));
            echo '</span></li>';
        }
        echo '</ul><nav>';
        if ($page>1) echo link_to('/manage/admin?page='.($page-1),'previous');
        if ($more) echo link_to('/manage/admin?page='.($page+1),'next');
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
        echo '</select><div class="actions">';button('save');echo link_to('/manage/admin','cancel').'</div></form>';
    },$error,$blog);
}

function admin_user_delete(array $blog,array $user,?string $error=null): void {
    page('delete_user',function () use($user) {
        echo '<form method="post">';token_field();
        echo '<div class="actions">'.link_to('/manage/admin','cancel').'<button type="submit">'.e(str_replace('{username}',$user['username'],t('delete_user_button'))).'</button></div></form>';
    },$error,$blog);
}
