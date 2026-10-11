<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/blog-core-'.bin2hex(random_bytes(6));mkdir($dir,0700);
putenv('BLOG_DATABASE='.$dir.'/test.sqlite');putenv('BLOG_ARGON_MEMORY=8192');putenv('BLOG_ARGON_TIME=1');
require dirname(__DIR__).'/src/bootstrap.php';
$checks=0;
function check(bool $ok,string $label): void { global $checks;if (!$ok) throw new RuntimeException($label);$checks++; }
function rejects(callable $fn,int $status): void { try {$fn();}catch(HttpError $e){check($e->status===$status,'Unexpected rejection');return;}throw new RuntimeException('Expected rejection'); }
try {
    // Upgrade a real v1 database, including conflicting section paths.
    db()->exec(file_get_contents(dirname(__DIR__).'/database/migrations/001.sql'));
    db()->exec('CREATE TABLE migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
    query('INSERT INTO migrations VALUES (?,?)',['001.sql',now()]);
    query('INSERT INTO users(username,password_hash,created_at,updated_at) VALUES (?,?,?,?)',['legacy','unused',now(),now()]);
    $legacyUser=(int)db()->lastInsertId();
    query('INSERT INTO blogs(user_id,title,created_at,updated_at) VALUES (?,?,?,?)',[$legacyUser,'legacy',now(),now()]);
    $legacyBlog=(int)db()->lastInsertId();
    foreach (['blogs','blogs-2','inicio'] as $slug) query('INSERT INTO sections(blog_id,title,slug,content_markdown,created_at,updated_at) VALUES (?,?,?,?,?,?)',[$legacyBlog,$slug,$slug,'preserved introduction',now(),now()]);
    $legacySection=one('SELECT id FROM sections WHERE blog_id=? AND slug=?',[$legacyBlog,'blogs'])['id'];
    query('INSERT INTO posts(blog_id,section_id,title,slug,body_markdown,status,created_at,updated_at,published_at) VALUES (?,?,?,?,?,?,?,?,?)',[$legacyBlog,$legacySection,'Existing post','existing','preserved post','published',now(),now(),now()]);
    $legacyPost=(int)db()->lastInsertId();
    migrate();migrate();
    check(one('SELECT home_markdown FROM blogs WHERE id=?',[$legacyBlog])['home_markdown']==='','Migration initializes singleton homepage');
    check(one('SELECT slug FROM sections WHERE id=?',[$legacySection])['slug']==='blogs-3','Migration resolves section collision');
    check((bool)one('SELECT id FROM sections WHERE blog_id=? AND slug=?',[$legacyBlog,'inicio-2']),'Migration reserves inicio');
    check(one('SELECT body_markdown FROM posts WHERE id=?',[$legacyPost])['body_markdown']==='preserved post','Migration preserves posts');
    check(one('SELECT content_markdown FROM sections WHERE id=?',[$legacySection])['content_markdown']==='preserved introduction','Migration preserves sections');
    check(one('SELECT post_id FROM redirects WHERE blog_id=? AND old_path=?',[$legacyBlog,'blogs/existing'])['post_id']===$legacyPost,'Migration keeps old post URL');

    $user=register_account('  ALICE  ','contraseña muy larga','contraseña muy larga');
    $blog=one('SELECT b.*,u.username FROM blogs b JOIN users u ON u.id=b.user_id WHERE user_id=?',[$user['id']]);
    check($blog['username']==='alice','Normalization and atomic blog creation');
    rejects(fn()=>register_account('alice','password long enough','password long enough'),422);
    foreach (['ab','a--bc','-abc','abc-','a b c','Álvaro','admin','posts','manage','assets'] as $bad) rejects(fn()=>username($bad),422);
    rejects(fn()=>register_account('valid','password long enough','different'),422);
    rejects(fn()=>validate_password(str_repeat('é',513),str_repeat('é',513)),422);
    foreach ([['corta','minimo 12 caracteres'], ["\0",'sin caracteres nulos'], [str_repeat('a',1025),'máximo 1024 caracteres'], [str_repeat('é',513),'máximo 512 caracteres']] as [$password,$message]) {
        try { validate_password($password,$password); throw new RuntimeException('Expected password rejection'); }
        catch (HttpError $e) { check($e->status===422 && $e->getMessage()===$message,'Specific password error'); }
    }
    validate_password(str_repeat('é',512),str_repeat('é',512));
    check(true,'Password at UTF-8 byte limit');
    $long=str_repeat('字',200);check(password_check($long,password_make($long)),'Unicode long password');
    db()->exec("CREATE TRIGGER break_blog BEFORE INSERT ON blogs BEGIN SELECT RAISE(ABORT,'test'); END");
    try {register_account('broken','password long enough','password long enough');throw new RuntimeException('Expected rollback');}catch(PDOException){}
    check(!one('SELECT * FROM users WHERE username=?',['broken']),'No orphan account after blog failure');
    db()->exec('DROP TRIGGER break_blog');
    save_home($blog,'**Inicio personalizado**');
    check(one('SELECT home_markdown FROM blogs WHERE id=?',[$blog['id']])['home_markdown']==='**Inicio personalizado**','Singleton homepage persists');
    check(in_array('blogs',section_reserved_names(),true) && free_section_slug($blog['id'],'blogs')!=='blogs' && free_section_slug($blog['id'],'inicio')!=='inicio','Built-in section routes reserved');
    $section=add_section($blog,'Diário','## Introducción');
    edit_section($blog,$section,'Diario nuevo','intro');check(owned_section($blog,$section)['slug']==='diario','Stable section slug');
    $post=save_post($blog,null,'Primera entrada','Hola',$section,'published');
    $unsectioned=save_post($blog,null,'Primera entrada','Otro',null,'draft');
    check(owned_post($blog,$post)['slug']===owned_post($blog,$unsectioned)['slug'],'Separate URL namespaces');
    $collision=save_post($blog,null,'Primera entrada','Otro',null,'draft');check(owned_post($blog,$collision)['slug']==='primera-entrada-2','Slug collision');
    save_post($blog,$post,'Nuevo título','Cambio',$section,'draft');check(owned_post($blog,$post)['slug']==='primera-entrada','Stable title slug');
    delete_section($blog,$section);$moved=owned_post($blog,$post);
    check($moved['section_id']===null && $moved['body_markdown']==='Cambio' && $moved['status']==='draft','Section deletion preserves posts');
    check($moved['slug']==='primera-entrada-3','Section deletion handles collisions');
    check((bool)one('SELECT * FROM redirects WHERE post_id=?',[$post]),'Redirect preserved');
    // Independent processes contend for the same SQLite writer lock.
    $probe=proc_open([PHP_BINARY,'-r','exit(extension_loaded("pdo_sqlite")?0:1);'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    foreach($pipes as $pipe)fclose($pipe);
    $phpFlags=proc_close($probe)===0 ? [] : ['-d','extension=pdo_sqlite'];
    $children=[];
    for($i=0;$i<12;$i++) {
        $pid=pcntl_fork();if($pid===-1)throw new RuntimeException('fork failed');
        if($pid===0) {
            // exec gives each contender a fresh PDO connection and PHP runtime.
            pcntl_exec(PHP_BINARY,array_merge($phpFlags,[__DIR__.'/section-worker.php',(string)$blog['id'],(string)$i]));
            exit(4);
        }$children[]=$pid;
    }
    $success=0;$limited=0;
    foreach($children as $pid){pcntl_waitpid($pid,$status);$code=pcntl_wexitstatus($status);if($code===0)$success++;elseif($code===2)$limited++;else throw new RuntimeException('Concurrent child failed: '.$code);}
    check($success===10 && $limited===2 && count(section_list($blog['id']))===10,'Concurrent section limit');
    rejects(fn()=>add_section($blog,'Eleventh',''),422);
    try {query('INSERT INTO sections(blog_id,title,slug,created_at,updated_at) VALUES (?,?,?,?,?)',[$blog['id'],'Bypass','bypass',now(),now()]);throw new RuntimeException('Missing database limit');}catch(PDOException){check(true,'Trigger limit');}
    $sections=section_list($blog['id']);reorder_section($blog,$sections[1]['id'],'up');check(section_list($blog['id'])[0]['id']===$sections[1]['id'],'Reorder');
    $bob=register_account('bobby','password long enough','password long enough');$other=one('SELECT * FROM blogs WHERE user_id=?',[$bob['id']]);
    $foreign=add_section($other,'Foreign','');rejects(fn()=>save_post($blog,null,'Wrong','Body',$foreign,'published'),404);
    save_css($blog,'body {color: red}');check(one('SELECT custom_css FROM blogs WHERE id=?',[$blog['id']])['custom_css']==='body {color: red}','CSS persistence');
    rejects(fn()=>save_css($blog,str_repeat('a',65537)),413);
    $html=markdown("# Título\n\n**fuerte** *suave* `código`\n\n- uno\n- dos\n\n> cita\n\n---\n\n```\n<script>alert(1)</script>\n```\n\n[mal](javascript:alert(1))\n\n![imagen](https://tracker.test/image)\n\n<img src=x onerror=alert(1)>");
    foreach(['<h1>','<strong>','<em>','<code>','<ul>','<blockquote>','<hr','<pre>'] as $tag)check(str_contains($html,$tag),'Markdown '.$tag);
    check(!str_contains($html,'<script')&&!str_contains($html,'<img')&&!str_contains($html,'href="javascript:'),'Markdown sanitization');
    check(e('<"&')==='&lt;&quot;&amp;','HTML escaping');
    delete_post($blog,$post);rejects(fn()=>owned_post($blog,$post),404);
    rejects(fn()=>delete_account($blog,'wrong','alice'),422);
    rejects(fn()=>delete_account($blog,'contraseña muy larga','wrong'),422);
    delete_account($blog,'contraseña muy larga','alice');
    foreach(['users','blogs','sections','posts','redirects'] as $table)check(!one("SELECT * FROM $table WHERE ".($table==='users'?'id':($table==='blogs'?'user_id':'blog_id')).'=?',[$table==='users'||$table==='blogs'?$user['id']:$blog['id']]),'Cascade '.$table);
    echo "$checks core checks passed.\n";
} finally { foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir); }
