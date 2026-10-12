<?php
declare(strict_types=1);
function route_blog(string $path): void {
    $parts=explode('/',trim($path,'/'));$name=$parts[0];
    if (in_array($name,reserved_names(),true)) fail(404,'not_found');
    $blog=one('SELECT b.*,u.username FROM blogs b JOIN users u ON u.id=b.user_id WHERE u.username=?',[$name]);
    if (!$blog) {
        $alias=one('SELECT b.*,u.username FROM username_aliases a JOIN users u ON u.id=a.user_id JOIN blogs b ON b.user_id=u.id WHERE a.username=?',[$name]);
        if ($alias) { can_read($alias);methods(['GET']);redirect('/'.$alias['username'].(count($parts)>1?'/'.implode('/',array_slice($parts,1)):''),308); }
    }
    if (!$blog) fail(404,'not_found');
    $tail=array_slice($parts,1);
    if (($tail[0]??'')==='new' || in_array($tail[0]??'',['edit','delete'],true)) {
        if (!is_owner($blog)) { require_user();fail(403,'forbidden'); }
        if ($tail===['new']) {
            methods(['GET','POST']);$error=submitted(function () use($blog) {
                rate_limit('post-new',(string)$blog['user_id'],30,3600);
                $section=input('section_id');if ($section!=='' && (!ctype_digit($section) || (int)$section<1)) fail(422,'invalid');
                $id=save_post($blog,null,input('title'),input('body'),$section!==''?(int)$section:null);
                redirect(post_path($blog,owned_post($blog,$id)),303,'published');
            });post_editor($blog,null,$error);return;
        }
        if (count($tail)===2 && ctype_digit($tail[1])) {
            $post=owned_post($blog,(int)$tail[1]);
            if ($tail[0]==='edit') {
                methods(['GET','POST']);$error=submitted(function () use($blog,$post) {
                    $section=input('section_id');if ($section!=='' && (!ctype_digit($section) || (int)$section<1)) fail(422,'invalid');
                    save_post($blog,$post['id'],input('title'),input('body'),$section!==''?(int)$section:null);
                    redirect(post_path($blog,owned_post($blog,$post['id'])),303,'saved');
                });post_editor($blog,$post,$error);return;
            }
            methods(['GET','POST']);submitted(function () use($blog,$post) {delete_post($blog,$post['id']);redirect('/'.$blog['username'].'/blogs',303,'deleted');});confirmation('delete_post','delete_post_help',post_path($blog,$post));return;
        }
        fail(404,'not_found');
    }
    can_read($blog);
    if (($tail[0]??'')==='_addons') { addon_route($blog,'public',array_slice($tail,1));return; }
    methods(['GET']);
    if (!$tail) { render_blog($blog);return; }
    if ($tail===['inicio']) redirect('/'.$blog['username'],308);
    if ($tail===['blogs']) { render_blog($blog,feed:true);return; }
    if ($tail===['style.css']) {
        headers_for($blog);send_header('Content-Type: text/css; charset=utf-8');echo addon_styles($blog).($blog['custom_css'] ?: file_get_contents(__DIR__.'/assets/blog.css'));return;
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
            if ($old) redirect(post_path($blog,$old),302);
            fail(404,'not_found');
        }
        render_blog($blog,$section,$post);return;
    }
    fail(404,'not_found');
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
    if ($post) {
        echo '<article class="blog-post"><h2 class="blog-post-title">'.e($post['title']).'</h2><p><time class="blog-post-date" datetime="'.e($post['published_at']).'">'.e(substr($post['published_at'],0,10)).'</time></p><div class="blog-post-body">'.$html.'</div></article>';
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
        $sql.=' ORDER BY published_at DESC,id DESC LIMIT 21 OFFSET ?';$params[]=($page-1)*20;
        $posts=query($sql,$params)->fetchAll();$more=count($posts)>20;$posts=array_slice($posts,0,20);
        if (!$posts) echo '<p>'.e(t('empty')).'</p>';
        else {
            echo '<ul class="blog-post-list">';
            foreach ($posts as $item) {
                echo '<li><time class="blog-post-date" datetime="'.e($item['published_at']).'">'.e(substr($item['published_at'],0,10)).'</time><a href="'.e(post_path($blog,$item)).'">'.e($item['title']).'</a>';
                if ($owner) { echo ' ';icon_link('/'.$blog['username'].'/edit/'.$item['id'],'edit','pencil');echo ' ';icon_link('/'.$blog['username'].'/delete/'.$item['id'],'delete','trash'); } echo '</li>';
            } echo '</ul>';
        }
        $base='/'.$blog['username'].($section?'/'.$section['slug']:'/blogs');
        echo '<nav aria-label="'.e(t('next')).'">';if ($page>1) echo link_to($base.'?page='.($page-1),'previous');if ($more) echo ' '.link_to($base.'?page='.($page+1),'next');echo '</nav>';
    }
    if ($owner && isset($_SESSION['flash'])) { echo '<p class="feedback" role="status">'.e(t($_SESSION['flash'])).'</p>'; unset($_SESSION['flash']); }
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
        echo '<div class="actions">';button($post?'save':'publish');echo link_to('/'.$blog['username'],'cancel').'</div></form>';
    },$error);
}
