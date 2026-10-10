<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/blog-core-'.bin2hex(random_bytes(6));mkdir($dir,0700);
putenv('BLOG_DATABASE='.$dir.'/test.sqlite');putenv('BLOG_ARGON_MEMORY=8192');putenv('BLOG_ARGON_TIME=1');
require dirname(__DIR__).'/src/bootstrap.php';
$checks=0;
function check(bool $ok,string $label): void { global $checks;if (!$ok) throw new RuntimeException($label);$checks++; }
function rejects(callable $fn,int $status): void { try {$fn();}catch(HttpError $e){check($e->status===$status,'Unexpected rejection');return;}throw new RuntimeException('Expected rejection'); }
try {
    migrate();migrate();
    $user=register_account('  ALICE  ','contraseña muy larga','contraseña muy larga');
    $blog=one('SELECT b.*,u.username FROM blogs b JOIN users u ON u.id=b.user_id WHERE user_id=?',[$user['id']]);
    check($blog['username']==='alice','Normalization and atomic blog creation');
    rejects(fn()=>register_account('alice','password long enough','password long enough'),422);
    foreach (['ab','a--bc','-abc','abc-','a b c','Álvaro','admin','posts','manage','assets'] as $bad) rejects(fn()=>username($bad),422);
    rejects(fn()=>register_account('valid','password long enough','different'),422);
    rejects(fn()=>validate_password(str_repeat('é',513),str_repeat('é',513)),422);
    $long=str_repeat('字',200);check(password_check($long,password_make($long)),'Unicode long password');
    db()->exec("CREATE TRIGGER break_blog BEFORE INSERT ON blogs BEGIN SELECT RAISE(ABORT,'test'); END");
    try {register_account('broken','password long enough','password long enough');throw new RuntimeException('Expected rollback');}catch(PDOException){}
    check(!one('SELECT * FROM users WHERE username=?',['broken']),'No orphan account after blog failure');
    db()->exec('DROP TRIGGER break_blog');
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
    $children=[];
    for($i=0;$i<12;$i++) {
        $pid=pcntl_fork();if($pid===-1)throw new RuntimeException('fork failed');
        if($pid===0) {
            // exec gives each contender a fresh PDO connection and PHP runtime.
            pcntl_exec(PHP_BINARY,['-d','extension=pdo_sqlite',__DIR__.'/section-worker.php',(string)$blog['id'],(string)$i]);
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
