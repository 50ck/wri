<?php
declare(strict_types=1);
function register_account(string $name, string $password, string $confirm): array {
    if (!config()['registration']) fail(403,'registration_closed');
    $name=username($name); validate_password($password,$confirm); $hash=password_make($password);
    try {
        return transaction(function () use ($name,$hash) {
            $time=now();
            query('INSERT INTO users(username,password_hash,created_at,updated_at) VALUES (?,?,?,?)',[$name,$hash,$time,$time]);
            $id=(int)db()->lastInsertId();
            query('INSERT INTO blogs(user_id,title,created_at,updated_at) VALUES (?,?,?,?)',[$id,$name,$time,$time]);
            return ['id'=>$id,'username'=>$name];
        });
    } catch (PDOException $e) {
        if (one('SELECT id FROM users WHERE username=?',[$name])) fail(422,'username_taken');
        throw $e;
    }
}
function section_list(int $blog): array { return query('SELECT * FROM sections WHERE blog_id=? ORDER BY sort_order,id',[$blog])->fetchAll(); }
function slug_base(string $title): string {
    $title=strtr(mb_strtolower($title,'UTF-8'),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $slug=trim(preg_replace('/[^a-z0-9]+/', '-', $title), '-');
    $slug=trim(substr($slug,0,60),'-');
    if ($slug==='' || in_array($slug,reserved_names(),true)) $slug='entrada';
    return $slug;
}
function free_section_slug(int $blog, string $title): string {
    $base=slug_base($title); $slug=$base; $i=2;
    while (one('SELECT id FROM sections WHERE blog_id=? AND slug=?',[$blog,$slug])) $slug=$base.'-'.$i++;
    return $slug;
}
function namespace_path(?int $section, string $slug): string {
    $prefix=$section ? (one('SELECT slug FROM sections WHERE id=?',[$section])['slug'] ?? 'posts') : 'posts';
    return $prefix.'/'.$slug;
}
function free_post_slug(int $blog, ?int $section, string $base, int $exclude = 0): string {
    $slug=$base; $i=2;
    while (one('SELECT id FROM posts WHERE blog_id=? AND section_id IS ? AND slug=? AND id<>?',[$blog,$section,$slug,$exclude]) || one('SELECT post_id FROM redirects WHERE blog_id=? AND old_path=? AND post_id<>?',[$blog,namespace_path($section,$slug),$exclude])) $slug=$base.'-'.$i++;
    return $slug;
}
function add_section(array $blog,string $title,string $content): int {
    $title=text_field($title,120); $content=markdown_field($content);
    return transaction(function () use ($blog,$title,$content) {
        if (count(section_list($blog['id']))>=10) fail(422,'section_limit');
        $time=now();
        query('INSERT INTO sections(blog_id,title,slug,content_markdown,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',[$blog['id'],$title,free_section_slug($blog['id'],$title),$content, count(section_list($blog['id'])),$time,$time]);
        return (int)db()->lastInsertId();
    });
}
function owned_section(array $blog,int $id): array { return one('SELECT * FROM sections WHERE id=? AND blog_id=?',[$id,$blog['id']]) ?? throw new HttpError(404,t('not_found')); }
function edit_section(array $blog,int $id,string $title,string $content): void {
    owned_section($blog,$id); $title=text_field($title,120); $content=markdown_field($content);
    query('UPDATE sections SET title=?,content_markdown=?,updated_at=? WHERE id=? AND blog_id=?',[$title,$content,now(),$id,$blog['id']]);
}
function reorder_section(array $blog,int $id,string $direction): void {
    if (!in_array($direction,['up','down'],true)) fail(400,'invalid');
    transaction(function () use ($blog,$id,$direction) {
        owned_section($blog,$id); $sections=section_list($blog['id']);
        $index=array_search($id,array_column($sections,'id')); $other=$index+($direction==='up'?-1:1);
        if (isset($sections[$other])) [$sections[$index],$sections[$other]]=[$sections[$other],$sections[$index]];
        foreach ($sections as $order=>$section) query('UPDATE sections SET sort_order=?,updated_at=? WHERE id=? AND blog_id=?',[$order,now(),$section['id'],$blog['id']]);
    });
}
function remember_path(int $blog,string $path,int $post): void {
    query('INSERT INTO redirects(blog_id,old_path,post_id) VALUES (?,?,?) ON CONFLICT(blog_id,old_path) DO UPDATE SET post_id=excluded.post_id',[$blog,$path,$post]);
}
function delete_section(array $blog,int $id): void {
    transaction(function () use ($blog,$id) {
        $section=owned_section($blog,$id);
        foreach (query('SELECT * FROM posts WHERE blog_id=? AND section_id=?',[$blog['id'],$id])->fetchAll() as $post) {
            $slug=free_post_slug($blog['id'],null,$post['slug'],$post['id']);
            remember_path($blog['id'],$section['slug'].'/'.$post['slug'],$post['id']);
            query('UPDATE posts SET section_id=NULL,slug=?,updated_at=? WHERE id=? AND blog_id=?',[$slug,now(),$post['id'],$blog['id']]);
        }
        query('DELETE FROM sections WHERE id=? AND blog_id=?',[$id,$blog['id']]);
    });
}
function owned_post(array $blog,int $id): array { return one('SELECT * FROM posts WHERE id=? AND blog_id=? AND deleted_at IS NULL',[$id,$blog['id']]) ?? throw new HttpError(404,t('not_found')); }
function save_post(array $blog,?int $id,string $title,string $body,?int $section,string $status): int {
    $title=text_field($title,200); $body=markdown_field($body);
    if (!in_array($status,['draft','published'],true)) fail(422,'invalid');
    return transaction(function () use ($blog,$id,$title,$body,$section,$status) {
        if ($section) owned_section($blog,$section);
        $time=now();
        if ($id) {
            $post=owned_post($blog,$id); $slug=$post['slug'];
            if ($post['section_id'] !== $section) {
                $slug=free_post_slug($blog['id'],$section,$slug,$id);
                remember_path($blog['id'],namespace_path($post['section_id'],$post['slug']),$id);
            }
            query('UPDATE posts SET title=?,body_markdown=?,section_id=?,slug=?,status=?,updated_at=?,published_at=? WHERE id=? AND blog_id=?',[$title,$body,$section,$slug,$status,$time,$post['published_at'] ?? ($status==='published'?$time:null),$id,$blog['id']]);
            return $id;
        }
        if ((int)query('SELECT count(*) FROM posts WHERE blog_id=?',[$blog['id']])->fetchColumn() >= config()['post_limit']) fail(422,'post_limit');
        $slug=free_post_slug($blog['id'],$section,slug_base($title));
        query('INSERT INTO posts(blog_id,section_id,title,slug,body_markdown,status,created_at,updated_at,published_at) VALUES (?,?,?,?,?,?,?,?,?)',[$blog['id'],$section,$title,$slug,$body,$status,$time,$time,$status==='published'?$time:null]);
        return (int)db()->lastInsertId();
    });
}
function delete_post(array $blog,int $id): void {
    owned_post($blog,$id);
    query('UPDATE posts SET deleted_at=?,updated_at=? WHERE id=? AND blog_id=?',[now(),now(),$id,$blog['id']]);
}
function post_path(array $blog,array $post): string { return '/'.$blog['username'].'/'.namespace_path($post['section_id'],$post['slug']); }
function save_css(array $blog,string $css): void {
    if (strlen($css)>config()['css_limit']) fail(413,'too_large');
    query('UPDATE blogs SET custom_css=?,updated_at=? WHERE id=? AND user_id=?',[$css,now(),$blog['id'],$blog['user_id']]);
}
function save_settings(array $blog,string $title,string $description,string $visibility): void {
    $title=text_field($title,120); $description=text_field($description,280,false);
    if (!in_array($visibility,['public','private'],true)) fail(422,'invalid');
    query('UPDATE blogs SET title=?,description=?,visibility=?,updated_at=? WHERE id=? AND user_id=?',[$title,$description,$visibility,now(),$blog['id'],$blog['user_id']]);
}
function delete_account(array $blog,string $password,string $confirmation): void {
    transaction(function () use ($blog,$password,$confirmation) {
        $user=one('SELECT * FROM users WHERE id=?',[$blog['user_id']]);
        if (!$user || !password_check($password,$user['password_hash']) || !hash_equals($user['username'],$confirmation)) fail(422,'delete_error');
        query('DELETE FROM users WHERE id=?',[$user['id']]);
    });
}
