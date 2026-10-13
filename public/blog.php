<?php
declare(strict_types=1);
function route_blog(string $path): void {
    $parts=explode('/',trim($path,'/'));$name=$parts[0];
    if (in_array($name,reserved_names(),true)) fail(404,'not_found');
    $blog=one('SELECT b.*,u.username FROM blogs b JOIN users u ON u.id=b.user_id WHERE u.username=?',[$name]);
    if (!$blog) {
        $alias=one('SELECT b.*,u.username FROM username_aliases a JOIN users u ON u.id=a.user_id JOIN blogs b ON b.user_id=u.id WHERE a.username=?',[$name]);
        if ($alias) { can_read($alias);methods(['GET']);redirect('/'.$alias['username'].(count($parts)>1?'/'.implode('/',array_slice($parts,1)):'').request_query(),308); }
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
            methods(['GET','POST']);submitted(function () use($blog,$post) {delete_post($blog,$post['id']);redirect('/'.$blog['username'].($post['section_id']?'/'.owned_section($blog,$post['section_id'])['slug']:''),303,'deleted');});confirmation('delete_post','delete_post_help',post_path($blog,$post));return;
        }
        fail(404,'not_found');
    }
    can_read($blog);
    if (($tail[0]??'')==='_addons') { addon_route($blog,'public',array_slice($tail,1));return; }
    methods(['GET']);
    if ($tail===['sitemap.xml']) { blog_sitemap($blog);return; }
    if (!$tail) { render_blog($blog);return; }
    if ($tail===['style.css']) {
        headers_for($blog);send_header('Content-Type: text/css; charset=utf-8');echo addon_styles($blog).($blog['custom_css'] ?: file_get_contents(__DIR__.'/assets/blog.css'));return;
    }
    if ($tail===['preview']) { if (!is_owner($blog)) fail(403,'forbidden');render_blog($blog,null,null,true);return; }
    if ($tail===['posts']) {
        if (!has_unsectioned_posts($blog['id'])) fail(404,'not_found');
        render_blog($blog,['id'=>null,'title'=>t('posts'),'slug'=>'posts','content_markdown'=>'']);return;
    }
    if (count($tail)===1) {
        $section=one('SELECT * FROM sections WHERE blog_id=? AND slug=?',[$blog['id'],$tail[0]]);
        if (!$section) fail(404,'not_found');render_blog($blog,$section);return;
    }
    if (count($tail)===2) {
        $section=$tail[0]==='posts'?null:one('SELECT * FROM sections WHERE blog_id=? AND slug=?',[$blog['id'],$tail[0]]);
        $post=($section || $tail[0]==='posts') ? one('SELECT * FROM posts WHERE blog_id=? AND section_id IS ? AND slug=? AND deleted_at IS NULL',[$blog['id'],$section['id']??null,$tail[1]]) : null;
        if (!$post) {
            $old=one('SELECT p.* FROM redirects r JOIN posts p ON p.id=r.post_id WHERE r.blog_id=? AND r.old_path=? AND p.deleted_at IS NULL',[$blog['id'],implode('/',$tail)]);
            if ($old) redirect(post_path($blog,$old),308);
            fail(404,'not_found');
        }
        render_blog($blog,$section,$post);return;
    }
    fail(404,'not_found');
}

function blog_sitemap(array $blog): void {
    if ($blog['visibility']!=='public') fail(404,'not_found');
    $part=sitemap_part();$count=(int)query('SELECT count(*) FROM posts WHERE blog_id=? AND deleted_at IS NULL',[$blog['id']])->fetchColumn();
    $parts=max(1,(int)ceil($count/10000));
    if ($part>$parts) fail(404,'not_found');
    if ($parts>1 && !isset($_GET['part'])) {
        sitemap_xml((static function () use($blog,$parts) {for ($i=1;$i<=$parts;$i++) yield ['/'.$blog['username'].'/sitemap.xml?part='.$i,null];})(),true);return;
    }
    sitemap_xml((static function () use($blog,$part) {
        if ($part===1) {
            $home=home_section($blog);
            if (blog_home_path($blog)!=='/') yield [blog_home_path($blog),$blog['updated_at']];
            foreach (section_list($blog['id']) as $section) if ($section['id']!==($home['id']??null)) yield ['/'.$blog['username'].'/'.$section['slug'],null];
            if ($home && has_unsectioned_posts($blog['id'])) yield ['/'.$blog['username'].'/posts',null];
        }
        foreach (query('SELECT p.slug,p.updated_at,s.slug AS section_slug FROM posts p LEFT JOIN sections s ON s.id=p.section_id AND s.blog_id=p.blog_id WHERE p.blog_id=? AND p.deleted_at IS NULL ORDER BY p.id LIMIT 10000 OFFSET ?',[$blog['id'],($part-1)*10000]) as $post) yield ['/'.$blog['username'].'/'.($post['section_slug']??'posts').'/'.$post['slug'],$post['updated_at']];
    })());
}

function blog_excerpt(string $html): string {
    $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);
    libxml_clear_errors();libxml_use_internal_errors($previous);
    foreach (['pre','code','math','svg'] as $tag) foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) $node->parentNode?->removeChild($node);
    foreach ($doc->getElementsByTagName('p') as $paragraph) {
        if ($paragraph->getAttribute('class')==='markdown-alert-title') continue;
        $text=trim(preg_replace('/\s+/u',' ',$paragraph->textContent));
        if ($text!=='') return mb_strlen($text,'UTF-8')>200?mb_substr($text,0,197,'UTF-8').'…':$text;
    }
    return '';
}
function render_blog(array $blog,?array $section=null,?array $post=null,bool $preview=false): void {
    $selected=home_section($blog);$blog['home_section_id']=$selected['id']??null;
    if (!$post && !$section) $section=$selected??['id'=>null,'title'=>t('posts'),'slug'=>'posts','content_markdown'=>''];
    $number=$section && !$post?input('page',$_GET):'';
    if ($number!=='' && (!ctype_digit($number) || (int)$number<1 || (int)$number>10000)) fail(400,'invalid');
    $page=max(1,(int)$number);
    $home=blog_home_path($blog);
    $path=$post?post_path($blog,$post):section_path($blog,$section);
    $posts=[];$more=false;
    if ($section && !$post) {
        $posts=query('SELECT * FROM posts WHERE blog_id=? AND section_id IS ? AND deleted_at IS NULL ORDER BY published_at DESC,id DESC LIMIT 21 OFFSET ?',[$blog['id'],$section['id'],($page-1)*20])->fetchAll();
        if (!$posts && $page>1) fail(404,'not_found');
        if ($number!=='' && ($page===1 || $number!==(string)$page)) redirect($path.($page>1?'?page='.$page:''),308);
        $more=count($posts)>20;$posts=array_slice($posts,0,20);
    }
    $body=$post?$post['body_markdown']:$section['content_markdown'];
    $html=markdown($body,$blog);
    $title=($post['title']??$section['title']).' · '.$blog['title'];
    if ($page>1) $title.=' · '.str_replace('{n}',(string)$page,t('page_number'));
    $description=!$post && $path===$home && $blog['description']!==''?$blog['description']:blog_excerpt($html);
    $canonical=config()['base_url'].$path.($page>1?'?page='.$page:'');
    headers_for($blog,$preview); $owner=is_owner($blog) && !$preview;
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.e($title).'</title>';
    if ($description!=='') echo '<meta name="description" content="'.e($description).'">';
    if (!$preview && $blog['visibility']==='public') echo '<link rel="canonical" href="'.e($canonical).'">';
    echo '<link rel="stylesheet" href="/'.e($blog['username']).'/style.css"></head><body class="blog"><header class="blog-header"><p class="blog-title"><a href="'.e($home).'">'.e($blog['title']).'</a></p>';
    if ($blog['description']!=='') echo '<p class="blog-description">'.e($blog['description']).'</p>';
    $sections=section_list($blog['id']);$unsectioned=has_unsectioned_posts($blog['id']);
    ob_start();addon_slot('nav',$blog);$addonNav=ob_get_clean();
    if (count($sections)+(int)$unsectioned>1 || trim($addonNav)!=='') {
        echo '<nav class="blog-nav" aria-label="'.e(t('sections')).'">';
        foreach ($sections as $item) echo '<a href="'.e(section_path($blog,$item)).'">'.e($item['title']).'</a> ';
        if ($unsectioned) echo link_to(section_path($blog,['id'=>null,'slug'=>'posts']),'posts').' ';
        echo $addonNav.'</nav>';
    }
    echo '</header>';
    echo '<main class="blog-main" id="main">';
    if ($post) {
        echo '<article class="blog-post" itemscope itemtype="https://schema.org/BlogPosting"><link itemprop="mainEntityOfPage" href="'.e($canonical).'"><link itemprop="url" href="'.e($canonical).'"><meta itemprop="dateModified" content="'.e($post['updated_at']).'"><h1 class="blog-post-title" itemprop="headline">'.e($post['title']).'</h1><p><time class="blog-post-date" itemprop="datePublished" datetime="'.e($post['published_at']).'">'.e(substr($post['published_at'],0,10)).'</time><span itemprop="author" itemscope itemtype="https://schema.org/Person"><a itemprop="url" href="'.e(config()['base_url'].$home).'"><span itemprop="name">'.e($blog['username']).'</span></a></span></p><div class="blog-post-body" itemprop="articleBody">'.$html.'</div></article>';
        addon_slot('post_after',$blog,$post);
        if ($owner) { echo '<div class="blog-owner">';icon_link('/'.$blog['username'].'/edit/'.$post['id'],'edit','pencil');icon_link('/'.$blog['username'].'/delete/'.$post['id'],'delete','trash');echo '</div>'; }
    } else {
        echo '<section class="blog-section"><h1>'.e($section['title']);
        if ($owner) {
            if ($section['id']!==null) { echo ' ';icon_link('/manage/sections/'.$section['id'].'/edit?from=section','edit','pencil'); }
            echo ' ';icon_link('/'.$blog['username'].'/new?section_id='.$section['id'],'new_post','plus');
        }
        echo '</h1>'.$html.'</section>';
        if (!$posts) echo '<p>'.e(t('empty')).'</p>';
        else {
            echo '<ul class="blog-post-list">';
            foreach ($posts as $item) {
                echo '<li><time class="blog-post-date" datetime="'.e($item['published_at']).'">'.e(substr($item['published_at'],0,10)).'</time><a href="'.e(post_path($blog,$item)).'">'.e($item['title']).'</a>';
                if ($owner) { echo ' ';icon_link('/'.$blog['username'].'/edit/'.$item['id'],'edit','pencil');echo ' ';icon_link('/'.$blog['username'].'/delete/'.$item['id'],'delete','trash'); } echo '</li>';
            } echo '</ul>';
        }
        $base=section_path($blog,$section);
        echo '<nav aria-label="'.e(t('next')).'">';if ($page>1) echo link_to($base.($page>2?'?page='.($page-1):''),'previous');if ($more) echo ' '.link_to($base.'?page='.($page+1),'next');echo '</nav>';
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
        $default=$post ? $post['section_id'] : (array_key_exists('section_id',$_GET)?input('section_id',$_GET):(one('SELECT id FROM sections WHERE blog_id=? AND slug=?',[$blog['id'],'blogs'])['id']??null));
        $selected=value('section_id',(string)$default);
        foreach (section_list($blog['id']) as $section) echo '<option value="'.$section['id'].'"'.($selected===(string)$section['id']?' selected':'').'>'.e($section['title']).'</option>';
        echo '</select>';area('body','post',value('body',$post['body_markdown']??''));
        echo '<div class="actions">';button($post?'save':'publish');echo link_to('/'.$blog['username'],'cancel').'</div></form>';
    },$error);
}
