#!/usr/bin/env python3
"""Optional Firefox audit of a running blog, with website JavaScript disabled.
Uses Marionette's native protocol; no Selenium, npm or browser dependencies beyond Firefox.
ExecuteScript is test instrumentation, never application code sent to visitors.
"""
import base64, json, os, socket, subprocess, sys, tempfile, time
from pathlib import Path

class Browser:
    def __init__(self, port):
        self.sock=socket.create_connection(('127.0.0.1',port),timeout=15)
        self.buffer=b''; self.ident=0
        self.receive()
        self.command('WebDriver:NewSession',{'capabilities':{'alwaysMatch':{}}})
    def receive(self):
        while b':' not in self.buffer: self.buffer+=self.sock.recv(4096)
        size,data=self.buffer.split(b':',1);size=int(size)
        while len(data)<size: data+=self.sock.recv(65536)
        self.buffer=data[size:]
        return json.loads(data[:size])
    def command(self,method,params=None):
        self.ident+=1;message=json.dumps([0,self.ident,method,params or {}]).encode()
        self.sock.sendall(str(len(message)).encode()+b':'+message)
        result=self.receive()
        if result[2]: raise RuntimeError(result[2])
        value=result[3]
        return value.get('value',value) if isinstance(value,dict) else value
    def evaluate(self,script):
        return self.command('WebDriver:ExecuteScript',{'script':script,'args':[],'newSandbox':True,'sandbox':'default','scriptTimeout':10000})
    def close(self):
        self.command('WebDriver:DeleteSession');self.sock.close()

url=sys.argv[1] if len(sys.argv)>1 else 'http://localhost:8080/avz'
with tempfile.TemporaryDirectory(prefix='blog-browser-') as folder:
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    profile=Path(folder)
    (profile/'user.js').write_text(f'user_pref("marionette.port", {port});\nuser_pref("javascript.enabled", false);\nuser_pref("browser.shell.checkDefaultBrowser", false);\nuser_pref("datareporting.policy.dataSubmissionEnabled", false);\nuser_pref("toolkit.telemetry.enabled", false);\n')
    browser=None
    with (profile/'firefox.log').open('w') as log:
        firefox=subprocess.Popen(['firefox','--headless','--no-remote','--profile',folder,'--marionette'],stdout=log,stderr=log)
        try:
            for _ in range(100):
                if firefox.poll() is not None: raise RuntimeError((profile/'firefox.log').read_text())
                try: browser=Browser(port);break
                except ConnectionRefusedError: time.sleep(.1)
            if browser is None: raise RuntimeError('Firefox did not start')
            for width in [500,768,1280]:
                # Firefox enforces a 500px minimum desktop window width.
                browser.command('WebDriver:SetWindowRect',{'width':width,'height':900})
                browser.command('WebDriver:Navigate',{'url':url})
                result=browser.evaluate('return {viewport:innerWidth,page:document.documentElement.scrollWidth,links:[...document.querySelectorAll(".blog-main a[href^=\\"#\\"]")].map(a=>({href:a.getAttribute("href"),found:!!document.getElementById(decodeURIComponent(a.hash.slice(1)))})),tables:document.querySelectorAll("table").length,ids:[...document.querySelectorAll("[id]")].map(e=>e.id),diagrams:[...document.querySelectorAll(".mermaid-diagram")].map(s=>({namespace:s.namespaceURI,width:s.getBoundingClientRect().width,height:s.getBoundingClientRect().height,nodes:s.querySelectorAll(".mermaid-node").length,edges:s.querySelectorAll(".mermaid-edge").length})),codeScrollers:[...document.querySelectorAll("pre")].filter(e=>e.scrollWidth>e.clientWidth).length};')
                assert result['page']<=result['viewport'],result
                assert all(link['found'] for link in result['links']),result['links']
                assert len(result['ids'])==len(set(result['ids'])),result['ids']
                assert all(d['namespace']=='http://www.w3.org/2000/svg' and d['width']>100 and d['height']>50 and d['nodes']>0 for d in result['diagrams']),result['diagrams']
                print(json.dumps(result,ensure_ascii=False))
            if result['links']:
                element=browser.command('WebDriver:FindElement',{'using':'css selector','value':'.blog-main a[href^="#"]'})
                browser.command('WebDriver:ElementClick',{'id':element['element-6066-11e4-a52e-4f735466cecf']})
                click=browser.evaluate('const id=decodeURIComponent(location.hash.slice(1));const target=document.getElementById(id);return {id,top:target.getBoundingClientRect().top,scrollY,viewport:innerHeight};')
                assert -1<=click['top']<click['viewport'],click
                print('Native anchor click:',json.dumps(click,ensure_ascii=False))
            browser.command('WebDriver:SetWindowRect',{'width':500,'height':900})
            browser.command('WebDriver:Navigate',{'url':url})
            target=os.environ.get('BLOG_BROWSER_TARGET') or ('.blog-table:last-of-type' if result['tables'] else None)
            if target:
                table=browser.command('WebDriver:FindElement',{'using':'css selector','value':target})
                browser.command('WebDriver:ElementClick',{'id':table['element-6066-11e4-a52e-4f735466cecf']})
            screenshot=browser.command('WebDriver:TakeScreenshot',{'full':False})
            output=Path(os.environ.get('BLOG_BROWSER_SCREENSHOT','/tmp/blog-browser-audit.png'))
            output.write_bytes(base64.b64decode(screenshot))
            print('Screenshot:',output)
        finally:
            if browser: browser.close()
            firefox.terminate();firefox.wait(timeout=10)
