#!/usr/bin/env python3
"""Chargement d'OpenCV.js dans Chromium : adresse déduite du script, CSP refusant eval, fichier absent."""
from pathlib import Path
import os
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
BASE='https://cloud.test/custom_apps/coordination/js/'
def run(p,csp,serve_opencv=True):
 page=p.new_page()
 page.route(BASE+'docscan.js*',lambda r:r.fulfill(path=str(root/'js/docscan.js'),content_type='application/javascript'))
 page.route(BASE+'vendor/opencv.js',lambda r:r.fulfill(path=str(root/'js/vendor/opencv.js'),content_type='application/javascript') if serve_opencv else r.fulfill(status=404,body='nope'))
 page.route('https://cloud.test/apps/coordination/',lambda r:r.fulfill(content_type='text/html',headers={'Content-Security-Policy':csp},body=f'<!doctype html><script src="{BASE}docscan.js?v=abc"></script>'))
 page.goto('https://cloud.test/apps/coordination/')
 url=page.evaluate('CoordinationDocScan.opencvUrl()')
 res=page.evaluate("CoordinationDocScan.loadOpenCV(CoordinationDocScan.opencvUrl()).then(r=>'OK '+(typeof r.cv.Mat),e=>'KO '+e.message)")
 page.close();return url,res
with sync_playwright() as p:
 b=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BIN') or None,headless=True,args=['--no-sandbox'])
 url,res=run(b,"script-src 'self' 'unsafe-eval'")
 assert url==BASE+'vendor/opencv.js',url;print('PASS OpenCV URL derived from docscan.js location (custom_apps)')
 assert res=='OK function',res;print('PASS OpenCV loads when eval is allowed')
 url,res=run(b,"script-src 'self'")
 assert res.startswith('KO') and 'CSP' in res,res;print('PASS eval blocked by CSP is reported:',res)
 url,res=run(b,"script-src 'self' 'unsafe-eval'",serve_opencv=False)
 assert res.startswith('KO') and '404' in res,res;print('PASS missing file is reported:',res)
 b.close()
