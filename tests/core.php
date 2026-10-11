<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/blog-core-'.bin2hex(random_bytes(6));mkdir($dir,0700);
putenv('BLOG_MERMAID_BIN=/nonexistent');
putenv('BLOG_DATABASE='.$dir.'/test.sqlite');putenv('BLOG_ARGON_MEMORY=8192');putenv('BLOG_ARGON_TIME=1');
require dirname(__DIR__).'/app.php';
$checks=0;
function check(bool $ok,string $label): void { global $checks;if (!$ok) throw new RuntimeException($label);$checks++; }
function rejects(callable $fn,int $status): void { try {$fn();}catch(HttpError $e){check($e->status===$status,'Unexpected rejection');return;}throw new RuntimeException('Expected rejection'); }
try {
    // Upgrade a real v1 database, including conflicting section paths.
    db()->exec(initial_schema());
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
    foreach(['<h1','<strong>','<em>','<code>','<ul>','<blockquote>','<hr','<pre>'] as $tag)check(str_contains($html,$tag),'Markdown '.$tag);
    check(!str_contains($html,'<script')&&!str_contains($html,'<img')&&!str_contains($html,'href="javascript:'),'Markdown sanitization');
    $stress=markdown(file_get_contents(__DIR__.'/fixtures/markdown-stress.md'));
    $document=new DOMDocument();$document->loadHTML('<?xml encoding="UTF-8"><div id="main">'.$stress.'</div>');
    $xpath=new DOMXPath($document);
    foreach (['introducción','después-del-código','repetido','repetido-1','repetido-1-1','main-1'] as $id) check((bool)$document->getElementById($id),'Unique heading anchor '.$id);
    foreach ($xpath->query('//a[starts-with(@href,"#")]') as $anchor) check((bool)$document->getElementById(rawurldecode(substr($anchor->getAttribute('href'),1))),'Fragment link resolves');
    check($document->getElementsByTagName('table')->length===1,'Markdown table survives sanitization');
    check(str_contains($stress,'<strong>texto preservado</strong>') && str_contains($stress,'a | b'),'Table cell markup and escaped separator');
    check($xpath->query('//th[@class="markdown-align-center"]')->length===1 && $xpath->query('//td[@class="markdown-align-right"]')->length===2,'Table alignment without inline CSS');
    check($xpath->query('//div[@class="blog-table" and @tabindex="0" and @role="region"]')->length===1,'Keyboard accessible scroll region');
    check($xpath->query('//ol[@start="7"]')->length===1,'Ordered list start preserved');
    check(str_contains($stress,'<code>const marca = `dummy`;</code>'),'Code span containing backticks');
    check(str_contains($document->getElementsByTagName('pre')->item(0)->textContent,"un bloque dentro de otro\n```") && $document->getElementById('después-del-código')->tagName==='h2','Four-backtick fence contains three-backtick fence');
    check(str_contains($stress,'Texto alternativo preservado') && $document->getElementsByTagName('img')->length===0,'Image alternative text without resource loading');
    check($xpath->query('//a[@title="texto de ayuda"]')->length===1,'Safe link title preserved');
    check($xpath->query('//*[@style or @onclick or @onerror]')->length===0 && $document->getElementsByTagName('script')->length===0,'Extended Markdown remains inert');
    $alerts=markdown("> [!NOTE]\n> contenido **fuerte**\n\n> [!TIP]\n> consejo con [enlace](https://example.com)\n\n> [!WARNING]\n> <script>inert</script>\n\n> [!OTHER]\n> texto normal\n\n`[!NOTE]`\n\n```text\n[!TIP]\n```");
    foreach (['note'=>'nota','tip'=>'consejo','warning'=>'aviso'] as $kind=>$label) check(str_contains($alerts,'class="markdown-alert markdown-alert-'.$kind.'"') && str_contains($alerts,'class="markdown-alert-title">'.$label.'</p>'),'Markdown alert '.$kind);
    check(str_contains($alerts,'<strong>fuerte</strong>') && str_contains($alerts,'href="https://example.com"'),'Alerts retain inline formatting');
    check(str_contains($alerts,'[!OTHER]') && str_contains($alerts,'<code>[!NOTE]</code>') && str_contains($alerts,'<code>[!TIP]</code>'),'Unknown markers and code stay literal');
    check(!str_contains($alerts,'<script>') && str_contains($alerts,'&lt;script&gt;'),'Alerts escape HTML');
    foreach (['js'=>'const pato = "hola";', 'json'=>'{"pato": 42}', 'diff'=>"+nuevo\n-viejo", 'php'=>'<?php echo "hola";', 'html'=>'<div title="hola">texto</div>', 'python'=>'print("hola")', 'css'=>'body { color: red; }', 'sql'=>'SELECT 42;', 'sh'=>'echo "hola"', 'yaml'=>'pato: true', 'ini'=>'[blog]\ntitle=hola', 'ts'=>'const pato: string = "hola";', 'md'=>'# título'] as $language=>$source) {
        $rendered=markdown("```".$language."\n".$source."\n```");
        $codeDoc=new DOMDocument();$codeDoc->loadHTML('<?xml encoding="UTF-8">'.$rendered);
        check(str_contains($rendered,'class="hljs-') && $codeDoc->getElementsByTagName('code')->item(0)->textContent===$source,'Server highlighting preserves '.$language);
    }
    $payload='</code><script>alert(1)</script><img src=x onerror=alert(1)> & "字"';
    $safe=markdown("```js\n".$payload."\n```");
    $safeDoc=new DOMDocument();$safeDoc->loadHTML('<?xml encoding="UTF-8">'.$safe);
    check($safeDoc->getElementsByTagName('code')->item(0)->textContent===$payload && !$safeDoc->getElementsByTagName('script')->length && !$safeDoc->getElementsByTagName('img')->length,'Highlighting cannot inject HTML');
    check(markdown("```unknown\n<script>\n```")==='<pre><code>&lt;script&gt;</code></pre>','Unknown languages remain escaped');
    $large=str_repeat('const pato = "hola"; ',1000);
    check(!str_contains(markdown("```js\n".$large."\n```"),'hljs-'),'Oversized code skips highlighting');
    $bounded=markdown(str_repeat("```js\n".str_repeat('const pato = "hola"; ',500)."\n```\n\n",8));
    check(substr_count($bounded,'<pre><code>const pato')===2,'Per-document highlighting budget');
    $flow=file_get_contents(__DIR__.'/fixtures/flowchart.mmd');
    $graph=mermaid_flowchart($flow);
    check(count($graph['nodes'])===6 && count($graph['edges'])===6 && $graph['nodes']['B']['shape']==='diamond','Mermaid example parses all nodes and edges');
    $diagram=markdown("```mermaid\n".$flow."```");
    $svgDoc=new DOMDocument();$svgDoc->loadHTML('<?xml encoding="UTF-8">'.$diagram,LIBXML_NOERROR|LIBXML_NOWARNING);$svgPath=new DOMXPath($svgDoc);
    check($svgDoc->getElementsByTagName('svg')->length===1 && !$svgDoc->getElementsByTagName('pre')->length,'Mermaid fence becomes inline SVG');
    check($svgPath->query('//g[@class="mermaid-node"]')->length===6 && $svgPath->query('//path[@class="mermaid-edge"]')->length===6 && $svgPath->query('//polygon[@class="mermaid-arrow"]')->length===6,'All flowchart nodes and arrowheads rendered');
    check($svgPath->query('//g[@class="mermaid-node"]/polygon')->length===1 && str_contains($diagram,'¿Hay café?') && str_contains($diagram,'>Sí</text>') && str_contains($diagram,'>No</text>'),'Decision and labelled branches rendered');
    check($svgPath->query('//div[@class="blog-diagram" and @role="region" and @tabindex="0"]')->length===1 && str_contains($diagram,'<desc>flowchart LR'),'Accessible diagram and source description');
    $xmlDoc=new DOMDocument();check($xmlDoc->loadXML(mermaid_svg($flow)),'Generated SVG is valid XML');
    foreach (['LR','RL','TB','TD','BT'] as $direction) check(mermaid_svg("graph ".$direction."; A(Inicio) --> B[Fin]; B --> A")!==null,'Flowchart direction '.$direction);
    check(mermaid_svg("flowchart LR\nA --> A")!==null && mermaid_svg("flowchart TB\nA[Único nodo]")!==null,'Self loops and isolated nodes');
    check(mermaid_flowchart("flowchart LR\n%% comentario\nA --> B --> C\nC[último]")['nodes']['C']['label']==='último','Chained arrows, comments and later node labels');
    $attack=markdown('```mermaid'."\nflowchart LR\n".'A["<script>alert(1)</script>"] -->|<img src=x>| B["& café"]'."\n```");
    $attackDoc=new DOMDocument();$attackDoc->loadHTML('<?xml encoding="UTF-8">'.$attack,LIBXML_NOERROR|LIBXML_NOWARNING);$attackPath=new DOMXPath($attackDoc);
    check($attackDoc->getElementsByTagName('svg')->length===1 && !$attackDoc->getElementsByTagName('script')->length && !$attackDoc->getElementsByTagName('img')->length && !$attackPath->query('//*[@href or @style or @onclick or @onerror]')->length,'Diagram labels cannot inject active SVG or HTML');
    foreach (["sequenceDiagram\nA->>B: hola", "flowchart LR\nclick A \"https://evil.test\"", "flowchart LR\nA -->", "flowchart LR\nA[roto", "flowchart LR\nA[".str_repeat('a',121).']', str_repeat('a',8193)] as $invalid) {
        check(mermaid_svg($invalid)===null,'Invalid or unsupported diagram safely rejected');
        check(str_contains(markdown("```mermaid\n".$invalid."\n```"),'<pre><code>'),'Rejected diagram preserves source');
    }
    $tooMany="flowchart LR\n";for($i=0;$i<41;$i++)$tooMany.='N'.$i."\n";
    check(mermaid_svg($tooMany)===null,'Diagram node limit');
    check(mermaid_svg("flowchart LR\n".str_repeat("A --> B\n",81))===null,'Diagram edge limit');
    check(substr_count(markdown(str_repeat("```mermaid\nflowchart LR\nA --> B\n```\n\n",17)),'class="mermaid-diagram"')===16,'Per-document diagram limit');
    check(!str_contains(markdown("<svg onload=alert(1)><script>evil</script></svg>"),'<svg'),'Raw user SVG remains escaped');
    $mathematics=markdown(file_get_contents(__DIR__.'/fixtures/math-stress.md'));
    $mathDoc=new DOMDocument();$mathDoc->loadHTML('<?xml encoding="UTF-8">'.$mathematics,LIBXML_NOERROR|LIBXML_NOWARNING);$mathPath=new DOMXPath($mathDoc);
    check($mathDoc->getElementsByTagName('math')->length===44 && !$mathDoc->getElementsByTagName('pre')->length,'All 44 stress formulas render without raw blocks');
    check($mathPath->query('//math[@display="inline"]')->length===23 && $mathPath->query('//math[@display="block"]')->length===21,'Inline versus display formulas');
    foreach (['mfrac','msqrt','mroot','msubsup','mtable','mover','munder','munderover','menclose'] as $tag) check($mathDoc->getElementsByTagName($tag)->length>0,'Native MathML '.$tag);
    check($mathPath->query('//mtable[mtr[8]]/mtr/mtd')->length===64,'Eight by eight matrix preserves all cells');
    check($mathPath->query('//mtable/mtr/mtd/mrow/mstyle/mi[text()="h"]')->length===1,'Last matrix cell preserves grouped arrow label');
    check($mathPath->query('//mtable[@rowlines="none solid none"]')->length===1,'Horizontal matrix rule retains correct row');
    check(str_contains($mathematics,'penalización') && str_contains($mathematics,'suavidad'),'Unicode formula annotations preserved');
    $delimiters=markdown('Inline $x^2$ and \(\frac{1}{2}\).'."\n\n".'\[\sqrt{x}\]'."\n\n".'$$x+1$$'."\n\n```math\nx^2\n```");
    check(substr_count($delimiters,'<math ')===5,'Dollar, parenthesis, bracket and math-fence delimiters');
    $literal=markdown('Precio: $5 y $10.'."\n\n".'Literal: \$x\$. ` $x^2$ `'."\n\n```text\n\$x+1\$\n\$\$x\$\$\n```");
    check(!str_contains($literal,'<math ') && str_contains($literal,'$5 y $10'),'Currency, escaped dollars and code remain literal');
    check(str_contains(markdown("$$\nx+1"),'$$') && !str_contains(markdown("$$\nx+1"),'<math '),'Unclosed display delimiter preserves source');
    check(math_element(new DOMDocument(),str_repeat('{',49).'x'.str_repeat('}',49),'inline')===null && math_element(new DOMDocument(),str_repeat('x',4097),'inline')===null,'Formula depth and size limits');
    check(substr_count(markdown(str_repeat('$x$ ',129)),'<math ')===128,'Formula count limit');
    $unsafeMath=markdown('$\text{<script>alert(1)</script>}$ $\href{https://evil.test}{test}$');
    $unsafeDoc=new DOMDocument();$unsafeDoc->loadHTML('<?xml encoding="UTF-8">'.$unsafeMath,LIBXML_NOERROR|LIBXML_NOWARNING);$unsafePath=new DOMXPath($unsafeDoc);
    check(!$unsafeDoc->getElementsByTagName('script')->length && !$unsafePath->query('//*[@href or @style or @onclick or @onerror]')->length,'Formula output cannot introduce active content');
    check(!str_contains(markdown('<math href="javascript:alert(1)"><mi>x</mi></math>'),'<math '),'Raw user MathML remains escaped');
    $maliciousSvg='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" onload="alert(1)"><script>evil</script><foreignObject><p xmlns="http://www.w3.org/1999/xhtml">evil</p></foreignObject><image href="https://tracker.test"/><style>body{color:red}</style><path d="M0 0 L10 10" style="stroke:red" fill="url(https://tracker.test)"/><a href="javascript:alert(1)"><text>link</text></a></svg>';
    $clean=sanitize_diagram_svg($maliciousSvg,'safe source');
    check($clean!==null && !preg_match('/<(?:script|foreignObject|image|style|a)\b|onload=|href=|style=|url\(https:/i',$clean),'Native SVG removes active elements, links, styles and resource references');
    check(sanitize_diagram_svg('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">&x;</svg>','')===null,'SVG entities and document types rejected');
    check(sanitize_diagram_svg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100000 100"/>','')===null,'SVG dimensions bounded');
    $transparent=sanitize_diagram_svg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="-10 -20 300 200"><rect x="-10" y="-20" width="300" height="200" fill="#333"/><rect x="20" y="30" width="80" height="40" fill="#242424"/></svg>','test');
    $transparentDoc=new DOMDocument();$transparentDoc->loadXML($transparent);
    check($transparentDoc->getElementsByTagName('rect')->length===1 && $transparentDoc->getElementsByTagName('rect')->item(0)->getAttribute('width')==='80','Canvas background removed without removing node boxes');
    check($transparentDoc->documentElement->getAttribute('width')==='300' && $transparentDoc->documentElement->getAttribute('height')==='200','Intrinsic diagram dimensions retained');
    $markers='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><marker id="arrow"><path d="M0 0L1 1"/></marker></defs><path marker-end="url(#arrow)" d="M0 0L10 10"/></svg>';
    $first=sanitize_diagram_svg($markers,'first');$second=sanitize_diagram_svg($markers,'second');
    preg_match('/id="([^"]+)"/',$first,$firstId);preg_match('/id="([^"]+)"/',$second,$secondId);
    check($firstId[1]!==$secondId[1] && str_contains($first,'url(#'.$firstId[1].')'),'Diagram marker IDs are isolated and references preserved');
    $binary=dirname(__DIR__).'/extensions/mermaid/bin/mmdr';
    if (is_executable($binary)) {
        preg_match_all('/```mermaid\n(.*?)```/s',file_get_contents(__DIR__.'/fixtures/diagram-stress.md'),$corpus);
        foreach ($corpus[1] as $index=>$source) {
            $rendered=native_diagram($source,$binary,microtime(true)+3);
            check($rendered!==null && str_contains($rendered,'mermaid-native'),'Native stress diagram '.$index);
            check(!preg_match('/<script|<foreignObject|<image|\sstyle=|\shref=|\sonload=/i',$rendered),'Native stress diagram remains inert '.$index);
        }
        check(native_diagram(str_repeat('x',8193),$binary,microtime(true)+3)===null,'Native diagram source limit');
        check(native_diagram('flowchart LR;A-->B',$binary,microtime(true)-1)===null,'Native render deadline');
        $slow=$dir.'/slow-renderer';file_put_contents($slow,"#!/bin/sh\nexec sleep 5\n");chmod($slow,0700);
        $started=microtime(true);check(native_diagram('flowchart LR;A-->B',$slow,microtime(true)+.05)===null && microtime(true)-$started<1,'Slow renderer is terminated');
    } else echo "Native Mermaid corpus skipped: run php extensions/mermaid/install.php.\n";
    check(e('<"&')==='&lt;&quot;&amp;','HTML escaping');
    delete_post($blog,$post);rejects(fn()=>owned_post($blog,$post),404);
    rejects(fn()=>delete_account($blog,'wrong','alice'),422);
    rejects(fn()=>delete_account($blog,'contraseña muy larga','wrong'),422);
    delete_account($blog,'contraseña muy larga','alice');
    foreach(['users','blogs','sections','posts','redirects'] as $table)check(!one("SELECT * FROM $table WHERE ".($table==='users'?'id':($table==='blogs'?'user_id':'blog_id')).'=?',[$table==='users'||$table==='blogs'?$user['id']:$blog['id']]),'Cascade '.$table);
    echo "$checks core checks passed.\n";
} finally { foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir); }
