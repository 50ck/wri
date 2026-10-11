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
            if ($old && ($old['status']==='published'||is_owner($blog))) redirect(post_path($blog,$old),302);
            fail(404,'not_found');
        }
        if ($post['status']!=='published' && !is_owner($blog)) fail(404,'not_found');
        render_blog($blog,$section,$post);return;
    }
    fail(404,'not_found');
}
