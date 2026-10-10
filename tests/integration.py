#!/usr/bin/env python3
"""Full front-controller requests with real PDO/file sessions, no network needed."""
import http.cookiejar, json, os, re, socket, subprocess, sys, tempfile, time, unittest
import urllib.error, urllib.parse, urllib.request
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
PHP = ['php', '-d', 'extension=pdo_sqlite']
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
        cls.temp = tempfile.TemporaryDirectory(prefix='blog-integration-')
        cls.env = dict(os.environ, BLOG_DATABASE=cls.temp.name+'/test.sqlite', BLOG_SESSION_PATH=cls.temp.name+'/sessions', BLOG_ARGON_MEMORY='8192', BLOG_ARGON_TIME='1')
        subprocess.run(PHP+['bin/install.php'], cwd=ROOT, env=cls.env, check=True, capture_output=True)
        cls.counter = 0
        cls.server = None
        if LIVE:
            sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
            cls.env['BLOG_BASE_URL']=cls.env['BLOG_TEST_HTTP']=f'http://127.0.0.1:{port}'
            cls.server_log=open(cls.temp.name+'/server.log','w')
            cls.server=subprocess.Popen(PHP+['-S',f'127.0.0.1:{port}','-t','public','bin/dev-router.php'],cwd=ROOT,env=cls.env,stdout=cls.server_log,stderr=cls.server_log)
            for _ in range(100):
                try:
                    urllib.request.urlopen(cls.env['BLOG_TEST_HTTP']+'/',timeout=1).close();break
                except urllib.error.URLError: time.sleep(.05)
            else: raise RuntimeError('HTTP test server did not start')
    @classmethod
    def tearDownClass(cls):
        if cls.server:
            cls.server.terminate();cls.server.wait(timeout=5);cls.server_log.close()
        cls.temp.cleanup()
    def setUp(self):
        if LIVE:
            subprocess.run(PHP+['-r', 'require "src/bootstrap.php"; query("DELETE FROM rate_limits");'],cwd=ROOT,env=self.env,check=True,capture_output=True)
        type(self).counter += 1
        self.name = 'owner-'+str(self.counter)
        self.owner = Client(self.env, 'owner-'+str(self.counter))
        response = self.owner.register(self.name)
        self.assertEqual(response['status'],303)
        self.assertEqual(response['location'],'/'+self.name)
        self.visitor = Client(self.env, 'visitor-'+str(self.counter))
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
        self.assertIn('nueva entrada',self.owner.request('/'+self.name)['body'])
    def test_home_and_canonical_paths(self):
        response=self.visitor.request('/?username=New-Name')
        self.assertEqual(response['location'],'/register?username=new-name')
        self.assertEqual(response['status'],303)
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
        self.assertNotIn('Mi entrada',self.visitor.request('/'+self.name)['body'])
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
        self.assertEqual(style['body'],css)
        self.assertIn('Content-Type: text/css; charset=utf-8',style['headers'])
        section=self.create_section();url=self.create_post(section=section)
        for path in ['/'+self.name,'/'+self.name+'/diario',url]:
            response=self.visitor.request(path)
            self.assertIn('href="/'+self.name+'/style.css"',response['body'])
            csp=next(h for h in response['headers'] if h.startswith('Content-Security-Policy:'))
            self.assertIn("script-src 'none'",csp);self.assertIn("img-src 'none'",csp)
            self.assertNotIn("style-src 'self'",csp);self.assertNotIn('tracker.example',csp)
        management=self.owner.request('/manage/appearance')
        self.assertIn('sandbox="allow-same-origin"',management['body'])
        self.assertNotIn('href="/'+self.name+'/style.css"',management['body'])
        preview=self.owner.request('/'+self.name+'/preview')
        self.assertEqual(preview['status'],200);self.assertNotIn('blog-owner',preview['body']);self.assertIn('Mi entrada',preview['body'])
        self.assertEqual(self.visitor.request('/'+self.name+'/preview')['status'],403)
        self.owner.request('/manage/appearance/restore')
        self.assertEqual(self.owner.post('/manage/appearance/restore')['status'],303)
        self.assertEqual(self.visitor.request('/'+self.name+'/style.css')['body'],(ROOT/'public/assets/blog.css').read_text())
    def test_privacy_and_no_cache(self):
        section=self.create_section();url=self.create_post(section=section)
        self.owner.request('/manage/settings')
        self.assertEqual(self.owner.post('/manage/settings',title='Privado',description='secreto',visibility='private')['status'],303)
        other=Client(self.env,'other-private');other.register('private-other')
        for path in ['/'+self.name,'/'+self.name+'/diario',url,'/'+self.name+'/style.css','/'+self.name+'/preview']:
            for client in [self.visitor,other]:
                response=client.request(path);self.assertEqual(response['status'],404)
                self.assertNotIn('secreto',response['body'])
                self.assertIn('Cache-Control: no-store, private',response['headers'])
            self.assertEqual(self.owner.request(path)['status'],200)
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
        client=Client(self.env,'registration-limit');client.request('/register')
        for _ in range(5):
            self.assertEqual(client.post('/register',username='admin',new_password='una contraseña larga',confirm_password='una contraseña larga')['status'],422)
        self.assertEqual(client.post('/register',username='available',new_password='una contraseña larga',confirm_password='una contraseña larga')['status'],429)
        self.owner.request('/'+self.name+'/new')
        for _ in range(30):
            self.assertEqual(self.owner.post('/'+self.name+'/new',title='',body='',section_id='',status='draft')['status'],422)
        self.assertEqual(self.owner.post('/'+self.name+'/new',title='valid',body='',section_id='',status='draft')['status'],429)
    def test_size_limits_and_no_javascript(self):
        self.owner.request('/manage/appearance')
        self.assertEqual(self.owner.post('/manage/appearance',css='a'*65537)['status'],413)
        self.owner.request('/'+self.name+'/new')
        self.assertEqual(self.owner.post('/'+self.name+'/new',title='valid',body='a'*131073,section_id='',status='draft')['status'],413)
        for path in ['/','/register','/login','/manage/sections','/manage/appearance','/manage/settings','/'+self.name,'/'+self.name+'/new']:
            body=self.owner.request(path)['body']
            self.assertNotRegex(body,r'<script\b|\bon\w+\s*=|javascript:')
            self.assertIn('<html lang="es">',body)

if __name__=='__main__': unittest.main(verbosity=2)
