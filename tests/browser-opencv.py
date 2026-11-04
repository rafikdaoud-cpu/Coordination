#!/usr/bin/env python3
"""Moteur OpenCV en Web Worker dans Chromium, avec la CSP stricte des pages Nextcloud (sans unsafe-eval) :
adresses déduites de docscan.js, chargement réussi, worker refusé par la page, CSP sur le fichier .js, fichier absent."""
from pathlib import Path
import os
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
BASE='https://cloud.test/custom_apps/coordination/js/'
STRICT="script-src 'self'; worker-src 'self'"
def run(p,page_csp=STRICT,serve_opencv=True,js_csp=None):
 page=p.new_page()
 def js(rel):
  headers={'Content-Security-Policy':js_csp} if js_csp else {}
  return lambda r:r.fulfill(path=str(root/'js'/rel),content_type='application/javascript',headers=headers)
 page.route(BASE+'docscan.js*',js('docscan.js'));page.route(BASE+'docscan-worker.js',js('docscan-worker.js'))
 page.route(BASE+'vendor/opencv.js',js('vendor/opencv.js') if serve_opencv else (lambda r:r.fulfill(status=404,body='nope')))
 page.route('https://cloud.test/apps/coordination/',lambda r:r.fulfill(content_type='text/html',headers={'Content-Security-Policy':page_csp},body=f'<!doctype html><meta charset="utf-8"><script src="{BASE}docscan.js?v=abc"></script>'))
 page.goto('https://cloud.test/apps/coordination/')
 urls=page.evaluate('CoordinationDocScan.engineUrls()')
 res=page.evaluate("""CoordinationDocScan.loadEngine(CoordinationDocScan.engineUrls()).then(async e=>{
   const w=480,h=360,d=new Uint8ClampedArray(w*h*4);
   for(let y=0;y<h;y++)for(let x=0;x<w;x++){const i=(y*w+x)*4,inside=x>120&&x<360&&y>60&&y<300;d[i]=d[i+1]=d[i+2]=inside?235:50;d[i+3]=255;}
   const q=await e.detect(d,w,h);return 'OK '+JSON.stringify(q);},e=>'KO '+e.message)""")
 page.close();return urls,res
with sync_playwright() as p:
 b=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BIN') or None,headless=True,args=['--no-sandbox'])
 urls,res=run(b)
 assert urls=={'opencv':BASE+'vendor/opencv.js','worker':BASE+'docscan-worker.js','docscan':BASE+'docscan.js?v=abc'},urls;print('PASS worker, docscan and OpenCV URLs derived from docscan.js location')
 assert res.startswith('OK [[') ,res;print('PASS OpenCV runs in the worker under a page CSP without unsafe-eval, and detects a sheet:',res[:60])
 urls,res=run(b,page_csp="script-src 'self'; worker-src 'none'")
 assert res.startswith('KO') and 'worker' in res,res;print('PASS worker blocked by the page is reported:',res)
 urls,res=run(b,js_csp="script-src 'self'")
 assert res.startswith('KO') and 'CSP' in res,res;print('PASS server CSP on .js files is reported:',res[:110])
 urls,res=run(b,serve_opencv=False)
 assert res.startswith('KO') and '404' in res,res;print('PASS missing OpenCV file is reported:',res)
 b.close()
