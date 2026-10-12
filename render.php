<?php
declare(strict_types=1);
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function link_to(string $path,string $key): string { return '<a href="'.e($path).'">'.e(t($key)).'</a>'; }
function token_field(): void { echo '<input type="hidden" name="csrf" value="'.e(csrf()).'">'; }
function field(string $name,string $key,string $value='',string $type='text',bool $required=true,int $max=0): void {
    echo '<label for="'.e($name).'">'.e(t($key)).'</label><input id="'.e($name).'" name="'.e($name).'" type="'.e($type).'" value="'.e($value).'"'.($required?' required':'').($max?' maxlength="'.$max.'"':'');
    if ($type==='password') echo ' autocomplete="'.(in_array($name,['confirm_password','new_password'],true)?'new-password':'current-password').'"';
    if ($name==='username') echo ' autocomplete="username" autocapitalize="none" spellcheck="false"';
    echo '>';
}
function area(string $name,string $key,string $value='',bool $css=false): void {
    if ($css) echo '<label for="'.e($name).'">'.e(t($key)).'</label>';
    echo '<textarea id="'.e($name).'" name="'.e($name).'"'.($css?' class="css-editor" spellcheck="false"':' aria-label="'.e(t($key)).'" aria-describedby="'.e($name).'-help"').'>'.e($value).'</textarea>';
    if (!$css) echo '<small id="'.e($name).'-help">'.e(t('markdown_help')).'</small>';
}
function button(string $key,?string $name=null,?string $value=null): void { echo '<button type="submit"'.($name?' name="'.e($name).'" value="'.e($value).'"':'').'>'.e(t($key)).'</button>'; }
function page(string $key,callable $content,?string $error=null,?array $management=null,?string $addon=null): void {
    $styles=$addon===null ? [] : [$addon];
    if ($key==='brand') foreach (addons('admin') as $id=>$extension) if (isset($extension['slots']['home_after'])) $styles[]=$id;
    $styles=array_filter(array_unique($styles),static fn($id)=>isset(addons('admin')[$id]['style']));
    headers_for(addonStyles:$styles);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.e(t($key)).' · '.e(t('brand')).'</title><link rel="stylesheet" href="/assets/base.css">';
    foreach ($styles as $id) echo '<link rel="stylesheet" href="/_addons/'.e($id).'/style.css">';
    echo '</head><body>';
    if ($management) {
        echo '<header><h1 class="manage-title">'.e(t('manage')).'</h1><nav aria-label="'.e(t('manage')).'">'.link_to('/manage/settings','settings').' '.link_to('/manage/sections','sections').' '.link_to('/manage/appearance','appearance').' '.link_to('/manage/addons','addons').(is_admin()?' '.link_to('/manage/admin','admin'):'').'</nav><div class="actions">'.link_to('/'.$management['username'],'blog').'<form class="inline" method="post" action="/logout">';token_field();button('logout');echo '</form></div></header>';
    }
    echo '<main id="main">'.($management?'<h2>':'<h1>').e(t($key)).($management?'</h2>':'</h1>');
    if ($error) echo '<p class="error" role="alert">'.e($error).'</p>';
    if (isset($_SESSION['flash'])) { echo '<p class="feedback" role="status">'.e(t($_SESSION['flash'])).'</p>'; unset($_SESSION['flash']); }
    $content(); echo '</main></body></html>';
}
function value(string $key,string $default=''): string { return ($_SERVER['REQUEST_METHOD']==='POST') ? input($key) : $default; }
function auth_form(string $kind,?string $error=null,?array $management=null): void {
    $action=$management?'/manage/admin/users/new':'/'.$kind;
    page($management?'create_user':$kind,function () use ($kind,$action) {
        echo '<form method="post" action="'.e($action).'">'; token_field();
        field('username','username',value('username',input('username',$_GET)),'text',true,24);
        if ($kind==='register') echo '<small>'.e(t('username_help')).'</small>';
        field($kind==='register'?'new_password':'password','password','','password');
        if ($kind==='register') { field('confirm_password','confirm_password','','password'); echo '<small>'.e(t('password_help')).'</small>'; }
        echo '<div class="actions">';button($action==='/manage/admin/users/new'?'create_user':$kind);echo '</div></form>';
    },$error,$management);
}
function icon_link(string $path,string $key,string $shape): void {
    $shapes=['plus'=>'M8 2v12M2 8h12','pencil'=>'M3 10l7-7 3 3-7 7-4 1zM9 4l3 3','trash'=>'M3 4h10M6 4V2h4v2M4 4l1 10h6l1-10','settings'=>'M8 1v2M8 13v2M1 8h2M13 8h2M3 3l2 2M11 11l2 2M3 13l2-2M11 5l2-2'];
    echo '<a href="'.e($path).'" aria-label="'.e(t($key)).'" title="'.e(t($key)).'"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="'.e($shapes[$shape]).'"/>'.($shape==='settings'?'<circle cx="8" cy="8" r="3"/>':'').'</svg></a>';
}
function render_blog(array $blog,?array $section=null,?array $post=null,bool $preview=false,bool $feed=false): void {
    $body=$post ? $post['body_markdown'] : ($section ? $section['content_markdown'] : ($feed ? '' : $blog['home_markdown']));
    $html=markdown($body,$blog);
    headers_for($blog,$preview); $owner=is_owner($blog) && !$preview;
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.e(($post['title'] ?? $section['title'] ?? $blog['title'])).'</title><link rel="stylesheet" href="/'.e($blog['username']).'/style.css"></head><body class="blog"><header class="blog-header"><h1 class="blog-title"><a href="/'.e($blog['username']).'">'.e($blog['title']).'</a></h1>';
    if ($blog['description']!=='') echo '<p class="blog-description">'.e($blog['description']).'</p>';
    echo '<nav class="blog-nav" aria-label="'.e(t('sections')).'">';
    foreach (navigation_sections($blog['id']) as $item) echo '<a href="/'.e($blog['username']).($item['slug']!==''?'/'.e($item['slug']):'').'">'.e($item['title']).'</a> ';
    addon_slot('nav',$blog);
    echo '</nav></header>';
    echo '<main class="blog-main" id="main">';
    if ($owner && isset($_SESSION['flash'])) { echo '<p class="feedback" role="status">'.e(t($_SESSION['flash'])).'</p>'; unset($_SESSION['flash']); }
    if ($post) {
        echo '<article class="blog-post"><h2 class="blog-post-title">'.e($post['title']).'</h2><p><time class="blog-post-date" datetime="'.e($post['published_at'] ?? $post['created_at']).'">'.e(substr($post['published_at'] ?? $post['created_at'],0,10)).'</time>'.($post['status']==='draft'?e(t('draft')):'').'</p><div class="blog-post-body">'.$html.'</div></article>';
        addon_slot('post_after',$blog,$post);
        if ($owner) { echo '<div class="blog-owner">';icon_link('/'.$blog['username'].'/edit/'.$post['id'],'edit','pencil');icon_link('/'.$blog['username'].'/delete/'.$post['id'],'delete','trash');echo '</div>'; }
    } elseif (!$section && !$feed) {
        echo '<article class="blog-section blog-home">';
        echo $html.'</article>';
    } else {
        if ($section) echo '<section class="blog-section"><h2>'.e($section['title']).'</h2>'.$html.'</section>';
        else {
            echo '<h2>'.e(t('blogs'));
            if ($owner) { echo ' ';icon_link('/'.$blog['username'].'/new','new_post','plus'); }
            echo '</h2>';
        }
        $number=input('page',$_GET); if ($number!=='' && (!ctype_digit($number) || (int)$number<1 || (int)$number>10000)) fail(400,'invalid');
        $page=max(1,(int)$number); $sql='SELECT * FROM posts WHERE blog_id=? AND deleted_at IS NULL'; $params=[$blog['id']];
        if ($section) { $sql.=' AND section_id=?';$params[]=$section['id']; }
        if (!$owner) $sql.=" AND status='published'";
        $sql.=' ORDER BY COALESCE(published_at,created_at) DESC,id DESC LIMIT 21 OFFSET ?';$params[]=($page-1)*20;
        $posts=query($sql,$params)->fetchAll();$more=count($posts)>20;$posts=array_slice($posts,0,20);
        if (!$posts) echo '<p>'.e(t('empty')).'</p>';
        else {
            echo '<ul class="blog-post-list">';
            foreach ($posts as $item) {
                echo '<li><time class="blog-post-date" datetime="'.e($item['published_at']??$item['created_at']).'">'.e(substr($item['published_at']??$item['created_at'],0,10)).'</time><a href="'.e(post_path($blog,$item)).'">'.e($item['title']).'</a>'.($item['status']==='draft'?' · '.e(t('draft')):'');
                if ($owner) { echo ' ';icon_link('/'.$blog['username'].'/edit/'.$item['id'],'edit','pencil');echo ' ';icon_link('/'.$blog['username'].'/delete/'.$item['id'],'delete','trash'); } echo '</li>';
            } echo '</ul>';
        }
        $base='/'.$blog['username'].($section?'/'.$section['slug']:'/blogs');
        echo '<nav aria-label="'.e(t('next')).'">';if ($page>1) echo link_to($base.'?page='.($page-1),'previous');if ($more) echo ' '.link_to($base.'?page='.($page+1),'next');echo '</nav>';
    }
    echo '</main>';
    if ($owner) { echo '<div class="blog-settings">';icon_link('/manage','settings','settings');echo '</div>'; }
    echo '</body></html>';
}
function post_editor(array $blog,?array $post,?string $error=null): void {
    page($post?'edit_post':'new_post',function () use ($blog,$post) {
        echo '<form method="post">';token_field();field('title','title',value('title',$post['title']??''),'text',true,200);
        echo '<label for="section_id">'.e(t('section')).'</label><select id="section_id" name="section_id"><option value="">'.e(t('unsectioned')).'</option>';
        $selected=value('section_id',(string)($post['section_id']??''));
        foreach (section_list($blog['id']) as $section) echo '<option value="'.$section['id'].'"'.($selected===(string)$section['id']?' selected':'').'>'.e($section['title']).'</option>';
        echo '</select>';area('body','content',value('body',$post['body_markdown']??''));
        echo '<div class="actions">';button('save_draft','status','draft');button('publish','status','published');echo link_to('/'.$blog['username'],'cancel').'</div></form>';
    },$error);
}
function section_editor(array $blog,?array $section,?string $error=null): void {
    page($section?'edit_section':'new_section',function () use ($section) {
        echo '<form method="post">';token_field();field('title','title',value('title',$section['title']??''),'text',true,120);area('content','intro_content',value('content',$section['content_markdown']??''));
        echo '<div class="actions">';button('save');echo link_to('/manage/sections','cancel').'</div></form>';
    },$error,$blog);
}
function confirmation(string $key,string $help,string $cancel,?array $management=null): void {
    page($key,function () use ($help,$cancel) {echo '<p>'.e(t($help)).'</p><form method="post">';token_field();echo '<div class="actions">'.link_to($cancel,'cancel');button('confirm');echo '</div></form>';},null,$management);
}
function manage_sections(array $blog): void {
    page('sections',function () use ($blog) {
        $sections=navigation_sections($blog['id']);echo '<ul class="section-list">';
        foreach ($sections as $i=>$section) {
            $path='/manage/sections/'.$section['id'];
            echo '<li><a href="/'.e($blog['username']).($section['slug']!==''?'/'.e($section['slug']):'').'">'.e($section['title']).'</a>';
            if ($section['id']==='home') echo ' <span class="section-actions">'.link_to('/manage/sections/home','edit').'</span>';
            elseif ($section['id']!=='blogs') echo ' <span class="section-actions">'.link_to($path.'/edit','edit').' '.link_to($path.'/delete','delete').'</span>';
            echo ' <form class="inline" method="post" action="'.$path.'/reorder">';token_field();
            if ($i>0) reorder_button('up');if ($i<count($sections)-1) reorder_button('down');echo '</form></li>';
        } echo '</ul>';if (count($sections)<12) echo link_to('/manage/sections/new','new_section');
    },null,$blog);
}
function reorder_button(string $direction): void {
    $path=$direction==='up'?'M8 14V2M3 7l5-5 5 5':'M8 2v12M3 9l5 5 5-5';
    echo '<button type="submit" name="direction" value="'.$direction.'" aria-label="'.e(t($direction)).'" title="'.e(t($direction)).'"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="'.$path.'"/></svg></button>';
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
