#!/usr/bin/env python3
"""Full front-controller requests with real PDO/file sessions, no network needed."""
import http.cookiejar, json, os, re, shutil, socket, subprocess, sys, tempfile, time, unittest
import urllib.error, urllib.parse, urllib.request
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
PHP = ['php']
if subprocess.run(['php','-r','exit(extension_loaded("pdo_sqlite")?0:1);'],capture_output=True).returncode:
    PHP += ['-d','extension=pdo_sqlite']
LIVE = '--http' in sys.argv
if LIVE: sys.argv.remove('--http')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args): return None

class Client:
    def __init__(self, env, ip='127.0.0.1'):
        self.env, self.ip, self.session, self.token = env, ip, '', ''
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies), NoRedirect())
    def request(self, path, method='GET', data=None, **extra):
        request = dict(path=path, method=method, data=data or {}, session=self.session, ip=self.ip, **extra)
        if self.env.get('BLOG_TEST_HTTP'):
            return self.http_request(request)
        result = subprocess.run(PHP + ['tests/request.php'], cwd=ROOT, env=self.env, input=json.dumps(request), text=True, capture_output=True, check=True)
        if result.stderr:
            raise AssertionError(result.stderr)
        response = json.loads(result.stdout)
        self.session = response['session']
        tokens = re.findall(r'name="csrf" value="([a-f0-9]+)"', response['body'])
        if tokens: self.token = tokens[0]
        response['location'] = next((h[10:] for h in response['headers'] if h.startswith('Location: ')), None)
        return response
    def http_request(self, request):
        data=request['data']; pairs=[]
        for key, value in data.items():
            if isinstance(value,list): pairs.extend((key+'[]',item) for item in value)
            else: pairs.append((key,value))
        body=urllib.parse.urlencode(pairs).encode() if request['method']=='POST' else None
        if request.get('length',0)>262144: body=b'a'*(request['length'])
        req=urllib.request.Request(self.env['BLOG_TEST_HTTP']+request['path'],data=body,method=request['method'],headers={'Content-Type':request.get('type','application/x-www-form-urlencoded')})
        try: response=self.opener.open(req,timeout=10)
        except urllib.error.HTTPError as error: response=error
        with response:
            result=dict(status=response.status,headers=[f'{k}: {v}' for k,v in response.headers.items()],body=response.read().decode(),location=response.headers.get('Location'))
        self.session=next((cookie.value for cookie in self.cookies if cookie.name=='blog_session'),'')
        tokens=re.findall(r'name="csrf" value="([a-f0-9]+)"',result['body'])
        if tokens: self.token=tokens[0]
        result['session']=self.session
        return result
    def post(self, path, **data):
        return self.request(path, 'POST', dict(csrf=self.token, **data))
    def register(self, name):
        self.request('/register')
        return self.post('/register', username=name, new_password='una contraseña larga', confirm_password='una contraseña larga')

class Platform(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        global ROOT
        cls.temp = tempfile.TemporaryDirectory(prefix='blog-integration-')
        cls.original_root=ROOT
        ROOT=Path(cls.temp.name)/'app'
        shutil.copytree(cls.original_root,ROOT,ignore=shutil.ignore_patterns('.git','storage','__pycache__'))
        probe=ROOT/'extensions/probe';probe.mkdir()
        shutil.copyfile(ROOT/'tests/fixtures/addon.php',probe/'addon.php')
        (probe/'style.css').write_text('.addon-probe { font-weight: bold; }')
        for name,fixture in [('admin-probe','admin-addon.php'),('home-probe','home-addon.php')]:
            folder=ROOT/'extensions'/name;folder.mkdir()
            shutil.copyfile(ROOT/'tests/fixtures'/fixture,folder/'addon.php')
            (folder/'style.css').write_text('.'+name+' { font-weight: bold; }')
        cls.env = dict(os.environ, BLOG_DATABASE=cls.temp.name+'/test.sqlite', BLOG_SESSION_PATH=cls.temp.name+'/sessions', BLOG_ARGON_MEMORY='8192', BLOG_ARGON_TIME='1')
        subprocess.run(PHP+['install.php'], cwd=ROOT, env=cls.env, check=True, capture_output=True)
        cls.counter = 0
        cls.server = None
        if LIVE:
            sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
            cls.env['BLOG_BASE_URL']=cls.env['BLOG_TEST_HTTP']=f'http://127.0.0.1:{port}'
            cls.server_log=open(cls.temp.name+'/server.log','w')
            cls.server=subprocess.Popen(PHP+['-S',f'127.0.0.1:{port}','-t','public','bin/test-server.php'],cwd=ROOT,env=cls.env,stdout=cls.server_log,stderr=cls.server_log)
            for _ in range(100):
                try:
                    urllib.request.urlopen(cls.env['BLOG_TEST_HTTP']+'/',timeout=1).close();break
                except urllib.error.URLError: time.sleep(.05)
            else: raise RuntimeError('HTTP test server did not start')
    @classmethod
    def tearDownClass(cls):
        global ROOT
        if cls.server:
            cls.server.terminate();cls.server.wait(timeout=5);cls.server_log.close()
        cls.temp.cleanup()
        ROOT=cls.original_root
    def setUp(self):
        if LIVE:
            subprocess.run(PHP+['-r', 'require "app.php"; query("DELETE FROM rate_limits");'],cwd=ROOT,env=self.env,check=True,capture_output=True)
        type(self).counter += 1
        self.name = 'owner-'+str(self.counter)
        self.owner = Client(self.env, 'owner-'+str(self.counter))
        response = self.owner.register(self.name)
        self.assertEqual(response['status'],303)
        self.assertEqual(response['location'],'/'+self.name)
        self.visitor = Client(self.env, 'visitor-'+str(self.counter))
    def test_admin_addons_global_activation_and_styles(self):
        home=self.visitor.request('/')
        self.assertEqual(home['body'].count('admin home'),1)
        self.assertEqual(home['body'].count('home only'),1)
        self.assertIn('href="/_addons/admin-probe/style.css"',home['body'])
        self.assertIn('href="/_addons/home-probe/style.css"',home['body'])
        csp=next(h for h in reversed(home['headers']) if h.startswith('Content-Security-Policy:'))
        self.assertIn('/_addons/admin-probe/style.css',csp)
        self.assertIn("script-src 'none'",csp)
        for path in ['/login','/register','/manage/addons','/manage/settings']:
            response=self.owner.request(path)
            self.assertNotIn('admin home',response['body'])
            self.assertNotIn('home only',response['body'])
            self.assertNotIn('/_addons/admin-probe/style.css',response['body'])
            self.assertNotIn('/_addons/home-probe/style.css',response['body'])
        settings=self.owner.request('/manage/addons')['body']
        self.assertNotIn('value="admin-probe"',settings)
        self.assertNotIn('value="home-probe"',settings)
        self.assertEqual(settings.count(' checked'),3)
        self.assertEqual(self.owner.post('/manage/addons',addons=['admin-probe'])['status'],422)
        self.assertEqual(self.owner.post('/manage/addons',addons=[])['status'],303)
        self.assertEqual(self.owner.post('/manage/sections/home',content='%global% %probe% $x^2$')['status'],303)
        html=self.visitor.request('/'+self.name)['body']
        self.assertIn('<code>GLOBAL</code>',html)
        self.assertIn('%probe%',html);self.assertNotIn('<math ',html)
        self.assertIn('class="admin-probe"',html)
        self.assertNotIn('home only',html)
        style=self.visitor.request('/'+self.name+'/style.css')['body']
        self.assertIn('.admin-probe',style);self.assertNotIn('.home-probe',style)
        self.assertNotIn('.math-block',style)
        with __import__('sqlite3').connect(self.env['BLOG_DATABASE']) as database:
            self.assertEqual(database.execute("SELECT count(*) FROM blog_addons WHERE addon_id IN ('admin-probe','home-probe')").fetchone()[0],0)
        for path in ['/_addons/admin-probe/style.css','/_addons/home-probe/style.css']:
            response=self.visitor.request(path)
            self.assertEqual(response['status'],200)
            self.assertIn('Content-Type: text/css; charset=utf-8',response['headers'])
            self.assertEqual(self.visitor.request(path,method='POST')['status'],405)
        self.assertEqual(self.visitor.request('/_addons/math/style.css')['status'],404)
        self.assertEqual(self.visitor.request('/_addons/probe/ping')['status'],404)
        self.assertEqual(self.visitor.request('/_addons/admin-probe/unknown')['status'],404)
        self.assertEqual(self.visitor.request('/_addons')['status'],404)
        folder=ROOT/'extensions/admin-probe';removed=ROOT/'admin-removed'
        folder.rename(removed)
        try:
            self.assertNotIn('admin home',self.visitor.request('/')['body'])
            self.assertNotIn('admin-probe',self.visitor.request('/'+self.name+'/style.css')['body'])
            self.assertEqual(self.visitor.request('/_addons/admin-probe/ping')['status'],404)
        finally:
            removed.rename(folder)
        self.assertIn('admin home',self.visitor.request('/')['body'])

    def test_admin_addon_routes_csrf_rate_and_blog_privacy(self):
        path='/_addons/admin-probe/ping'
        response=self.visitor.request(path)
        self.assertEqual(response['status'],200)
        self.assertIn('/_addons/admin-probe/style.css',response['body'])
        self.assertNotIn('/_addons/home-probe/style.css',response['body'])
        self.assertEqual(self.visitor.post(path)['status'],200)
        self.assertEqual(self.visitor.request(path,'POST',{})['status'],403)
        self.assertEqual(self.visitor.request(path,method='DELETE')['status'],405)
        for _ in range(29): self.assertEqual(self.visitor.post(path)['status'],200)
        self.assertEqual(self.visitor.post(path)['status'],429)
        blogPath='/'+self.name+'/_addons/admin-probe/ping'
        self.assertEqual(self.visitor.request(blogPath)['status'],200)
        self.assertEqual(self.visitor.request('/manage/addons/admin-probe/ping')['status'],401)
        self.assertEqual(self.owner.request('/manage/addons/admin-probe/ping')['status'],200)
        self.owner.request('/manage/addons');self.owner.post('/manage/addons',addons=[])
        self.assertEqual(self.owner.request('/manage/addons/admin-probe/ping')['status'],200)
        url=self.create_post(title='Global slot')
        self.assertIn('admin global post',self.visitor.request(url)['body'])
        self.owner.request('/manage/settings')
        self.owner.post('/manage/settings',title='privado',description='',visibility='private')
        self.assertEqual(self.visitor.request(blogPath)['status'],404)
        self.assertEqual(self.owner.request(blogPath)['status'],200)
        self.assertEqual(self.visitor.request(path)['status'],200)

    def test_admin_request_observer_results_and_output(self):
        path='/_addons/admin-probe/redirect'
        response=self.visitor.request(path)
        self.assertEqual(response['status'],303)
        self.assertEqual(response['location'],'/')
        self.assertNotIn('OBSERVER OUTPUT',response['body'])
        def events():
            return [json.loads(line) for line in Path(self.env['BLOG_DATABASE']+'.observations').read_text().splitlines()]
        self.assertEqual(events()[-1]['status'],303)
        self.assertEqual(events()[-1]['path'],path)
        for path,status in [('/login?password=secret-query',200),('/missing-global-page',404),('/login',405)]:
            result=self.visitor.request(path,method='DELETE' if status==405 else 'GET')
            self.assertEqual(result['status'],status)
            event=events()[-1]
            self.assertEqual(event['status'],status)
            self.assertEqual(event['path'],path.split('?')[0])
            self.assertEqual(set(event),{'path','method','status','duration_ms'})
            self.assertGreaterEqual(event['duration_ms'],0)
            self.assertNotIn('secret-query',json.dumps(event))
            self.assertNotIn('OBSERVER OUTPUT',result['body'])
        self.visitor.request('/login');self.visitor.post('/login',username='missing-user',password='never-record-this')
        self.assertEqual(events()[-1]['status'],422)
        self.assertNotIn('never-record-this',Path(self.env['BLOG_DATABASE']+'.observations').read_text())
        if LIVE: return # Failure logging is inspected in the CLI harness below.
        errorLog=ROOT/'observer-error.log'
        result=subprocess.run(PHP+['-d','error_log='+str(errorLog),'tests/request.php'],cwd=ROOT,env=self.env,input=json.dumps(dict(path='/_addons/admin-probe/observer-error',method='GET',data={})),text=True,capture_output=True,check=True)
        response=json.loads(result.stdout)
        self.assertEqual(response['status'],200)
        self.assertNotIn('OBSERVER OUTPUT',response['body'])
        self.assertIn('Addon observer failed: admin-probe',errorLog.read_text())
        self.assertEqual(json.loads(Path(self.env['BLOG_DATABASE']+'.secondary-observations').read_text().splitlines()[-1])['path'],'/_addons/admin-probe/observer-error')

    def test_addon_type_validation(self):
        if LIVE: self.skipTest('Descriptor rejection logging is checked in the CLI harness')
        bad=ROOT/'extensions/bad-type';bad.mkdir()
        errorLog=ROOT/'addon-validation.log'
        variants=["'scope'=>'admin'", "'type'=>'unknown'", "'type'=>'user','slots'=>['home_after'=>static function() {}]", "'type'=>'user','request_finished'=>static function() {}", "'type'=>'user','routes'=>['platform'=>['GET'=>static function() {}]]"]
        try:
            for variant in variants:
                (bad/'addon.php').write_text("<?php return ['api'=>1,'label'=>['es'=>'invalid addon'],"+variant+"]; ")
                result=subprocess.run(PHP+['-d','error_log='+str(errorLog),'tests/request.php'],cwd=ROOT,env=self.env,input=json.dumps(dict(path='/',method='GET',data={})),text=True,capture_output=True,check=True)
                self.assertEqual(json.loads(result.stdout)['status'],200)
            self.assertEqual(errorLog.read_text().count('Addon unavailable: bad-type'),len(variants))
        finally:
            shutil.rmtree(bad)

    def test_addons_per_blog_and_privacy(self):
        response=self.owner.request('/manage/addons')
        self.assertEqual(response['status'],200)
        self.assertEqual(response['body'].count(' checked'),3)
        for name in ('fórmulas','diagramas mermaid','syntax highlighting'):
            self.assertIn(name,response['body'])
        self.assertEqual(self.visitor.request('/manage/addons')['status'],401)
        source='$x^2$\n\n```js\nconst pato = 42;\n```\n\n```mermaid\nflowchart LR; A-->B\n```'
        self.assertEqual(self.owner.post('/manage/sections/home',content=source)['status'],303)
        html=self.visitor.request('/'+self.name)['body']
        self.assertIn('<math ',html);self.assertIn('hljs-',html)
        self.assertEqual(self.owner.post('/manage/addons',addons=[])['status'],303)
        html=self.visitor.request('/'+self.name)['body']
        self.assertNotIn('<math ',html);self.assertNotIn('hljs-',html);self.assertNotIn('<svg',html)
        self.assertIn('$x^2$',html);self.assertIn('flowchart LR',html)
        style=self.visitor.request('/'+self.name+'/style.css')['body']
        self.assertNotIn('.math-block',style);self.assertNotIn('.hljs-',style);self.assertNotIn('.mermaid-',style)
        self.assertEqual(self.owner.request('/'+self.name+'/_addons/math/ping')['status'],404)
        self.owner.request('/manage/addons')
        self.assertEqual(self.owner.post('/manage/addons',addons=['math'])['status'],303)
        html=self.owner.request('/'+self.name+'/preview')['body']
        self.assertIn('<math ',html);self.assertNotIn('hljs-',html)
        other=Client(self.env,'other-addons');other.register('other-addons')
        other.request('/manage/sections/home')
        self.assertEqual(other.post('/manage/sections/home',content=source)['status'],303)
        self.assertIn('hljs-',self.visitor.request('/other-addons')['body'])
        self.assertEqual(self.owner.request('/manage/addons','POST',{'addons':['math']})['status'],403)
        self.assertEqual(self.owner.post('/manage/addons',addons=['../../bad'])['status'],422)
        self.assertEqual(self.owner.post('/manage/addons',addons='math')['status'],422)
        self.assertEqual(self.owner.request('/manage/addons',method='DELETE')['status'],405)
        self.owner.post('/manage/settings',title='privado',description='',visibility='private')
        self.assertEqual(self.visitor.request('/'+self.name+'/style.css')['status'],404)
        self.assertEqual(self.visitor.request('/'+self.name+'/preview')['status'],404)
        self.assertEqual(other.request('/'+self.name)['status'],404)
        self.assertIn('<math ',self.owner.request('/'+self.name+'/preview')['body'])

    def test_removed_addon_preserves_content_and_selection(self):
        self.owner.request('/manage/sections/home')
        self.owner.post('/manage/sections/home',content='$x^2$')
        folder=ROOT/'extensions/math';removed=ROOT/'math-removed'
        folder.rename(removed)
        try:
            self.assertNotIn('value="math"',self.owner.request('/manage/addons')['body'])
            html=self.visitor.request('/'+self.name)['body']
            self.assertNotIn('<math ',html);self.assertIn('$x^2$',html)
        finally:
            removed.rename(folder)
        self.assertIn('<math ',self.visitor.request('/'+self.name)['body'])

    def test_addon_contract_routes_slots_and_parser(self):
        self.owner.request('/manage/addons')
        self.assertEqual(self.owner.post('/manage/addons',addons=['probe','math'])['status'],303)
        self.owner.post('/manage/sections/home',content='%probe% $x^2$')
        html=self.visitor.request('/'+self.name)['body']
        self.assertIn('<code>PROBE</code>',html);self.assertIn('<math ',html)
        self.assertIn('class="addon-probe"',html)
        style=self.visitor.request('/'+self.name+'/style.css')['body']
        self.assertIn('.addon-probe',style)
        self.assertNotIn('addon-probe',self.owner.request('/manage/settings')['body'])
        path='/'+self.name+'/_addons/probe/ping'
        self.assertEqual(self.visitor.request(path)['status'],200)
        self.assertEqual(self.visitor.post(path)['status'],200)
        self.assertEqual(self.visitor.request(path,'POST',{})['status'],403)
        self.assertEqual(self.visitor.request(path,method='DELETE')['status'],405)
        self.assertEqual(self.owner.request('/manage/addons/probe/ping')['status'],200)
        self.assertEqual(self.visitor.request('/manage/addons/probe/ping')['status'],401)
        self.assertEqual(self.visitor.request('/'+self.name+'/_addons/probe/missing')['status'],404)
        post=self.create_post(title='Slot probe',status='published')
        self.assertIn('probe post',self.visitor.request(post)['body'])
        self.owner.request('/manage/settings')
        self.owner.post('/manage/settings',title='privado',description='',visibility='private')
        self.assertEqual(self.visitor.request(path)['status'],404)
        self.assertEqual(self.visitor.post(path)['status'],404)
        self.assertEqual(self.owner.request(path)['status'],200)
        self.owner.request('/manage/addons')
        self.owner.post('/manage/addons',addons=[])
        self.assertEqual(self.owner.request(path)['status'],404)
        self.assertEqual(self.owner.request('/manage/addons/probe/ping')['status'],404)
        self.assertNotIn('addon-probe',self.owner.request('/'+self.name)['body'])

    def create_section(self, title='Diario'):
        self.owner.request('/manage/sections/new')
        self.assertEqual(self.owner.post('/manage/sections/new', title=title, content='**introducción**')['status'],303)
        body=self.owner.request('/manage/sections')['body']
        return int(re.findall(r'/manage/sections/(\d+)/edit',body)[-1])
    def create_post(self, title='Mi entrada', section='', status='published'):
        self.owner.request('/'+self.name+'/new')
        response=self.owner.post('/'+self.name+'/new',title=title,body='# Texto\n\n**hola**',section_id=str(section),status=status)
        self.assertEqual(response['status'],303)
        return response['location']
    def test_registration_and_atomic_uniqueness(self):
        client=Client(self.env,'registration')
        client.request('/register')
        bad=client.post('/register',username='admin',new_password='una contraseña larga',confirm_password='una contraseña larga')
        self.assertEqual(bad['status'],422)
        self.assertIn('value="admin"',bad['body'])
        self.assertEqual(client.register(self.name)['status'],422)
        client.request('/register')
        mismatch=client.post('/register',username='available',new_password='una contraseña larga',confirm_password='diferente')
        self.assertEqual(mismatch['status'],422)
        self.assertEqual(self.visitor.request('/available')['status'],404)
        self.assertIn('editar inicio',self.owner.request('/'+self.name)['body'])
    def test_home_and_canonical_paths(self):
        response=self.visitor.request('/')
        self.assertEqual(response['status'],200)
        self.assertNotIn('<form',response['body'])
        self.assertIn('<a href="/login">entrar</a> o <a href="/register">registrarme</a>',response['body'])
        self.assertEqual(self.visitor.request('/?username=New-Name')['status'],200)
        self.assertEqual(self.visitor.request('/'+self.name+'/')['status'],308)
        for path in ['/no-such-user','/../storage/blog.sqlite','/assets/../index.php','/'+self.name+'/posts/nope','/a%2fb','//example.org','/health']:
            self.assertEqual(self.visitor.request(path)['status'],404,path)
        self.assertEqual(self.visitor.request('/', 'DELETE')['status'],405)
    def test_login_logout_and_session_regeneration(self):
        client=Client(self.env,'login-test');client.request('/login');old=client.session
        wrong=client.post('/login',username=self.name,password='wrong password')
        self.assertEqual(wrong['status'],422)
        response=client.post('/login',username=self.name,password='una contraseña larga')
        self.assertEqual(response['status'],303);self.assertNotEqual(client.session,old)
        self.assertEqual(client.request('/manage/sections')['status'],200)
        self.assertEqual(client.post('/logout')['status'],303)
        self.assertEqual(client.request('/manage/sections')['status'],401)
        self.assertEqual(client.request('/logout')['status'],405)
    def test_csrf_methods_payloads_and_auth(self):
        self.assertEqual(self.visitor.request('/manage')['status'],401)
        self.assertEqual(self.visitor.request('/'+self.name+'/new')['status'],401)
        bad=self.owner.request('/manage/settings','POST',{'csrf':'bad','title':'hacked'})
        self.assertEqual(bad['status'],403)
        self.assertEqual(self.owner.request('/manage/settings','PUT')['status'],405)
        self.assertEqual(self.owner.request('/manage/settings','POST',length=262145)['status'],413)
        self.assertEqual(self.owner.request('/manage/settings','POST',type='application/json')['status'],400)
        self.owner.request('/manage/settings')
        self.assertEqual(self.owner.request('/register','POST',{'csrf':self.owner.token,'username':['array']})['status'],400)
    def test_posts_edit_drafts_delete_and_markdown(self):
        url=self.create_post(status='draft')
        self.assertEqual(self.visitor.request(url)['status'],404)
        self.assertNotIn('Mi entrada',self.visitor.request('/'+self.name+'/blogs')['body'])
        body=self.owner.request(url)['body'];postid=re.search(r'/edit/(\d+)',body)[1]
        self.owner.request('/'+self.name+'/edit/'+postid)
        error=self.owner.post('/'+self.name+'/edit/'+postid,title='',body='keep my text',section_id='',status='published')
        self.assertEqual(error['status'],422);self.assertIn('keep my text',error['body'])
        changed=self.owner.post('/'+self.name+'/edit/'+postid,title='<nuevo>',body='<script>alert(1)</script>\n\n[bad](javascript:alert(1))\n\n**fuerte**',section_id='',status='published')
        self.assertEqual(changed['location'],url)
        published=self.visitor.request(url)
        self.assertEqual(published['status'],200)
        self.assertIn('&lt;nuevo&gt;',published['body']);self.assertIn('<strong>fuerte</strong>',published['body'])
        self.assertNotIn('<script',published['body']);self.assertNotIn('href="javascript:',published['body'])
        self.assertNotIn('/edit/',published['body'])
        delete='/'+self.name+'/delete/'+postid
        self.assertEqual(self.owner.request(delete)['status'],200)
        self.assertEqual(self.visitor.request(url)['status'],200)
        self.assertEqual(self.owner.post(delete)['status'],303)
        self.assertEqual(self.visitor.request(url)['status'],404)
    def test_cross_user_authorization(self):
        post=self.create_post();postid=re.search(r'/edit/(\d+)',self.owner.request(post)['body'])[1]
        section=self.create_section()
        other=Client(self.env,'other-'+self.name);other.register('other-'+str(self.counter));other.request('/manage/settings')
        for path in ['/'+self.name+'/new','/'+self.name+'/edit/'+postid,'/'+self.name+'/delete/'+postid,'/manage/sections/'+str(section)+'/edit','/manage/sections/'+str(section)+'/delete']:
            self.assertIn(other.request(path)['status'],[403,404],path)
            self.assertIn(other.post(path,title='stolen',body='stolen',section_id='',status='published')['status'],[403,404],path)
        ownnew='/other-'+str(self.counter)+'/new';other.request(ownnew)
        self.assertEqual(other.post(ownnew,title='stolen',body='body',section_id=str(section),status='published')['status'],404)
        self.assertEqual(other.request('/'+self.name+'/preview')['status'],403)
    def test_sections_reorder_edit_limit_and_delete(self):
        first=self.create_section('Diario');second=self.create_section('Proyectos')
        self.owner.request('/manage/sections/'+str(first)+'/edit')
        response=self.owner.post('/manage/sections/'+str(first)+'/edit',title='Diario nuevo',content='contenido')
        self.assertEqual(response['status'],303)
        self.assertEqual(self.visitor.request('/'+self.name+'/diario')['status'],200)
        self.owner.request('/manage/sections')
        self.assertEqual(self.owner.post('/manage/sections/'+str(second)+'/reorder',direction='up')['status'],303)
        body=self.owner.request('/manage/sections')['body'];self.assertLess(body.index('Proyectos'),body.index('Diario nuevo'))
        url=self.create_post(section=first)
        self.owner.request('/manage/sections/'+str(first)+'/delete')
        self.assertEqual(self.owner.post('/manage/sections/'+str(first)+'/delete')['status'],303)
        moved=self.visitor.request(url);self.assertEqual(moved['status'],302)
        self.assertEqual(self.visitor.request(moved['location'])['status'],200)
        self.assertIn('/posts/',moved['location'])
        for i in range(9): self.create_section('Sección '+str(i))
        self.owner.request('/manage/sections/new')
        self.assertEqual(self.owner.post('/manage/sections/new',title='undécima',content='')['status'],422)
    def test_css_persistence_scope_restore_and_preview(self):
        css='body { color: red; } @import "https://tracker.example/x";'
        self.owner.request('/manage/appearance')
        self.assertEqual(self.owner.post('/manage/appearance',css=css)['status'],303)
        style=self.visitor.request('/'+self.name+'/style.css')
        self.assertTrue(style['body'].endswith(css))
        self.assertIn('Content-Type: text/css; charset=utf-8',style['headers'])
        section=self.create_section();url=self.create_post(section=section)
        for path in ['/'+self.name,'/'+self.name+'/blogs','/'+self.name+'/diario',url]:
            response=self.visitor.request(path)
            self.assertIn('href="/'+self.name+'/style.css"',response['body'])
            csp=next(h for h in response['headers'] if h.startswith('Content-Security-Policy:'))
            self.assertIn("script-src 'none'",csp);self.assertIn("img-src 'none'",csp)
            self.assertNotIn("style-src 'self'",csp);self.assertNotIn('tracker.example',csp)
        management=self.owner.request('/manage/appearance')
        self.assertIn('sandbox="allow-same-origin"',management['body'])
        self.assertNotIn('href="/'+self.name+'/style.css"',management['body'])
        self.owner.request('/manage/sections/home')
        self.assertEqual(self.owner.post('/manage/sections/home',content='**Inicio de prueba**')['status'],303)
        preview=self.owner.request('/'+self.name+'/preview')
        self.assertEqual(preview['status'],200);self.assertNotIn('blog-owner',preview['body']);self.assertIn('<strong>Inicio de prueba</strong>',preview['body'])
        self.assertEqual(self.visitor.request('/'+self.name+'/preview')['status'],403)
        self.owner.request('/manage/appearance/restore')
        self.assertEqual(self.owner.post('/manage/appearance/restore')['status'],303)
        self.assertTrue(self.visitor.request('/'+self.name+'/style.css')['body'].endswith((ROOT/'public/assets/blog.css').read_text()))
    def test_privacy_and_no_cache(self):
        section=self.create_section();url=self.create_post(section=section)
        self.owner.request('/manage/settings')
        self.assertEqual(self.owner.post('/manage/settings',title='Privado',description='secreto',visibility='private')['status'],303)
        other=Client(self.env,'other-private');other.register('private-other')
        for path in ['/'+self.name,'/'+self.name+'/blogs','/'+self.name+'/inicio','/'+self.name+'/diario',url,'/'+self.name+'/style.css','/'+self.name+'/preview']:
            for client in [self.visitor,other]:
                response=client.request(path);self.assertEqual(response['status'],404)
                self.assertNotIn('secreto',response['body'])
                self.assertIn('Cache-Control: no-store, private',response['headers'])
            self.assertEqual(self.owner.request(path)['status'],308 if path.endswith('/inicio') else 200)
    def test_account_deletion_and_old_sessions(self):
        section=self.create_section();url=self.create_post(section=section)
        second=Client(self.env,'second-session');second.request('/login');second.post('/login',username=self.name,password='una contraseña larga')
        self.owner.request('/manage/settings/delete')
        self.assertEqual(self.owner.post('/manage/settings/delete',password='wrong',confirmation=self.name)['status'],422)
        self.assertEqual(self.owner.post('/manage/settings/delete',password='una contraseña larga',confirmation='wrong')['status'],422)
        self.assertEqual(self.visitor.request(url)['status'],200)
        deleted=self.owner.post('/manage/settings/delete',password='una contraseña larga',confirmation=self.name)
        self.assertEqual(deleted['status'],303);self.assertEqual(deleted['location'],'/')
        self.assertEqual(self.visitor.request(url)['status'],404)
        self.assertEqual(second.request('/manage/settings')['status'],401)
        self.assertEqual(self.owner.request('/manage/settings')['status'],401)
        self.assertEqual(self.visitor.register(self.name)['status'],303)
        self.assertEqual(second.request('/manage/settings')['status'],401)
    def test_authentication_rate_limit(self):
        client=Client(self.env,'rate-limit');client.request('/login')
        for _ in range(10): self.assertEqual(client.post('/login',username=self.name,password='incorrecta')['status'],422)
        response=client.post('/login',username=self.name,password='una contraseña larga')
        self.assertEqual(response['status'],429)
        self.assertTrue(any(h.startswith('Retry-After: ') for h in response['headers']))
    def test_registration_and_post_creation_limits(self):
        if LIVE:
            subprocess.run(PHP+['-r', 'require "app.php"; query("DELETE FROM rate_limits WHERE bucket LIKE ?", ["registration:%"]);'],cwd=ROOT,env=self.env,check=True,capture_output=True)
        client=Client(self.env,'registration-limit');client.request('/register')
        for _ in range(5):
            self.assertEqual(client.post('/register',username='admin',new_password='una contraseña larga',confirm_password='una contraseña larga')['status'],422)
        self.assertEqual(client.post('/register',username='available',new_password='una contraseña larga',confirm_password='una contraseña larga')['status'],429)
        self.owner.request('/'+self.name+'/new')
        for _ in range(30):
            self.assertEqual(self.owner.post('/'+self.name+'/new',title='',body='',section_id='',status='draft')['status'],422)
        self.assertEqual(self.owner.post('/'+self.name+'/new',title='valid',body='',section_id='',status='draft')['status'],429)
    def test_secure_cookie_configuration_and_strict_sessions(self):
        if LIVE:
            cookies=list(self.owner.cookies)
            self.assertTrue(cookies)
            cookie=cookies[0]
            self.assertTrue(cookie.has_nonstandard_attr('HttpOnly'))
            self.assertEqual(cookie.get_nonstandard_attr('SameSite'),'Lax')
        else:
            env=dict(self.env,BLOG_BASE_URL='https://blogs.example.test')
            client=Client(env,'secure-cookie');client.session='attacker-chosen-session'
            response=client.request('/login')
            self.assertNotEqual(response['session'],'attacker-chosen-session')
            self.assertTrue(response['cookie_params']['secure'])
            self.assertTrue(response['cookie_params']['httponly'])
            self.assertEqual(response['cookie_params']['samesite'],'Lax')
            self.assertEqual(response['cookie_params']['path'],'/')
    def test_deletion_reauthentication_is_limited(self):
        self.owner.request('/manage/settings/delete')
        for _ in range(10):
            self.assertEqual(self.owner.post('/manage/settings/delete',password='wrong',confirmation=self.name)['status'],422)
        self.assertEqual(self.owner.post('/manage/settings/delete',password='una contraseña larga',confirmation=self.name)['status'],429)
        self.assertEqual(self.visitor.request('/'+self.name)['status'],200)
    def test_homepage_is_one_editable_text_and_blogs_is_feed(self):
        home=self.visitor.request('/'+self.name)
        self.assertIn('href="/'+self.name+'/blogs">blogs</a>',home['body'])
        self.assertNotIn('blog-post-list',home['body'])
        self.assertNotIn('/manage/sections/home',home['body'])
        self.assertNotIn('blog-settings',home['body'])
        owner_home=self.owner.request('/'+self.name)['body']
        self.assertNotIn('href="/'+self.name+'/new"',owner_home)
        self.assertIn('<div class="blog-settings"><a href="/manage"',owner_home)
        self.assertRegex(self.owner.request('/'+self.name)['body'],r'<h2>inicio <a href="/manage/sections/home"[^>]*aria-label="editar inicio"[^>]*><svg[^>]*>.*?</svg></a></h2>')
        management=self.owner.request('/manage/sections')['body']
        self.assertIn('inicio',management);self.assertIn('blogs',management)
        self.assertIn('0 de 10',management)
        self.owner.request('/manage/sections/home')
        self.assertEqual(self.owner.request('/manage/sections/home','POST',{'csrf':'bad','content':'stolen'})['status'],403)
        content='# Mi inicio\n\n**bienvenido** <script>bad</script>'
        saved=self.owner.post('/manage/sections/home',content=content)
        self.assertEqual(saved['status'],303);self.assertEqual(saved['location'],'/'+self.name)
        self.create_post(title='Publicación visible')
        self.create_post(title='Borrador oculto',status='draft')
        home=self.visitor.request('/'+self.name)['body']
        self.assertIn('<strong>bienvenido</strong>',home);self.assertNotIn('<script>',home)
        self.assertNotIn('Publicación visible',home)
        feed=self.visitor.request('/'+self.name+'/blogs')['body']
        self.assertIn('<h2>blogs</h2>',feed);self.assertIn('Publicación visible',feed)
        self.assertNotIn('Borrador oculto',feed);self.assertNotIn('bienvenido',feed)
        self.assertIn('Borrador oculto',self.owner.request('/'+self.name+'/blogs')['body'])
        self.assertRegex(self.owner.request('/'+self.name+'/blogs')['body'],r'<h2>blogs <a href="/'+self.name+r'/new"[^>]*aria-label="nueva entrada"[^>]*><svg[^>]*>.*?</svg></a></h2>')
        self.owner.request('/manage/sections/home')
        self.assertEqual(self.owner.post('/manage/sections/home',content='texto reemplazado')['status'],303)
        updated=self.visitor.request('/'+self.name)['body']
        self.assertIn('texto reemplazado',updated);self.assertNotIn('bienvenido',updated)
        self.assertEqual(self.visitor.request('/manage/sections/home')['status'],401)
        other=Client(self.env,'home-other');other.register('home-other')
        self.assertNotIn('texto reemplazado',other.request('/home-other')['body'])
        self.assertEqual(self.visitor.request('/'+self.name+'/inicio')['location'],'/'+self.name)

    def test_pagination_and_invalid_page(self):
        for index in range(21): self.create_post(title='Página '+str(index))
        first=self.visitor.request('/'+self.name+'/blogs')
        self.assertEqual(len(re.findall(r'<li>',first['body'])),20)
        self.assertIn('?page=2',first['body'])
        second=self.visitor.request('/'+self.name+'/blogs?page=2')
        self.assertEqual(len(re.findall(r'<li>',second['body'])),1)
        bad=self.visitor.request('/'+self.name+'/blogs?page=-1')
        self.assertEqual(bad['status'],400)
        self.assertEqual(bad['body'].count('<!doctype html>'),1)
    def test_css_does_not_cross_blogs(self):
        self.owner.request('/manage/appearance')
        self.assertEqual(self.owner.post('/manage/appearance',css='body {color: hotpink}')['status'],303)
        other=Client(self.env,'css-other');other.register('css-other')
        self.assertNotIn('hotpink',other.request('/css-other/style.css')['body'])
        self.assertNotIn('/'+self.name+'/style.css',other.request('/css-other')['body'])
        self.assertNotIn('/'+self.name+'/style.css',self.owner.request('/login')['body'])
    def test_disabled_registration_and_configured_post_cap(self):
        if LIVE: self.skipTest('Configuration variations are exercised by the CLI request harness')
        customized=Client(dict(self.env,BLOG_SITE_NAME='Mi blog <&>',BLOG_SITE_DESCRIPTION='Escribir <sin ruido>'),'customized')
        body=customized.request('/')['body']
        self.assertIn('<h1>Mi blog &lt;&amp;&gt;</h1>',body)
        self.assertIn('<p>Escribir &lt;sin ruido&gt;</p>',body)
        self.assertNotIn('localhost/',body)
        self.assertNotIn('href="/register"',customized.request('/login')['body'])
        self.assertNotIn('min 3 letras',customized.request('/login')['body'])
        self.assertIn('min 3 letras',customized.request('/register')['body'])
        disabled=Client(dict(self.env,BLOG_REGISTRATION='0'),'disabled')
        self.assertEqual(disabled.request('/register')['status'],403)
        self.assertNotIn('name="username"',disabled.request('/')['body'])
        limited=Client(dict(self.env,BLOG_POST_LIMIT='1'),'limited');limited.session=self.owner.session
        limited.request('/'+self.name+'/new')
        self.assertEqual(limited.post('/'+self.name+'/new',title='First',body='body',section_id='',status='published')['status'],303)
        limited.request('/'+self.name+'/new')
        self.assertEqual(limited.post('/'+self.name+'/new',title='Second',body='body',section_id='',status='published')['status'],422)

    def test_size_limits_and_no_javascript(self):
        self.owner.request('/manage/appearance')
        self.assertEqual(self.owner.post('/manage/appearance',css='a'*65537)['status'],413)
        self.owner.request('/'+self.name+'/new')
        self.assertEqual(self.owner.post('/'+self.name+'/new',title='valid',body='a'*131073,section_id='',status='draft')['status'],413)
        for path in ['/','/register','/login','/manage/sections','/manage/appearance','/manage/settings','/'+self.name,'/'+self.name+'/new']:
            body=self.owner.request(path)['body']
            self.assertNotRegex(body,r'<script\b|\bon\w+\s*=|javascript:')
            self.assertIn('<html lang="es">',body)
            self.assertNotIn('>blogs.</a>',body)

if __name__=='__main__': unittest.main(verbosity=2)
