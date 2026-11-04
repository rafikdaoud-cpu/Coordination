#!/usr/bin/env python3
"""Real browser, synthetic API only: mobile list, checklist, scans PDF, errors and RDV/collection."""
from pathlib import Path
import os,sys,base64
# Photo JPEG 64x48 utilisée comme prise de vue simulée.
PHOTO=base64.b64decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAA0JCgsKCA0LCgsODg0PEyAVExISEyccHhcgLikxMC4pLSwzOko+MzZGNywtQFdBRkxOUlNSMj5aYVpQYEpRUk//2wBDAQ4ODhMREyYVFSZPNS01T09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT0//wAARCAAwAEADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDrvFPiV/D8tjFFp7Xsl4WVVWTacjbgAbTkndWX/wAJnrf/AEJuofm//wAbo8cf8jL4U/6/P/Z4q7SvS/cUqFOUqfM5X6vu10ZGrb1OL/4TPW/+hN1D83/+N0f8Jnrf/Qm6h+b/APxuu0orP6xh/wDnyvvl/mOz7nF/8Jnrf/Qm6h+b/wDxuj/hM9b/AOhN1D83/wDjddpRR9Yw/wDz5X3y/wAws+5xf/CZ63/0Juofm/8A8brU8LeJX8QS30UuntZSWZVWVpNxyd2QRtGCNtdBXF+B/wDkZfFf/X5/7PLWn7irQqSjT5XG3V90urFqmtQ8cf8AIy+FP+vz/wBnirtK4vxx/wAjL4U/6/P/AGeKu0rPEf7vR9H/AOlMa3YUUUVwlBRRRQAVxfgf/kZfFf8A1+f+zy12lcX4H/5GXxX/ANfn/s8td2H/AN3rei/9KRL3QeOP+Rl8Kf8AX5/7PFXaVz/inw0/iCWxli1BrKSzLMrLHuOTtwQdwwRtrL/4QzW/+hy1D8n/APjlafuKtCnGVTlcb9H3b6IWqb0O0ori/wDhDNb/AOhy1D8n/wDjlH/CGa3/ANDlqH5P/wDHKz+r4f8A5/L7pf5Du+x2lFcX/wAIZrf/AEOWofk//wAco/4QzW/+hy1D8n/+OUfV8P8A8/l90v8AILvsdpXF+B/+Rl8V/wDX5/7PLR/whmt/9DlqH5P/APHK1PC3hp/D8t9LLqDXsl4VZmaPacjdkk7jkndWn7ilQqRjU5nK3R90+qFq2tD/2Q==')
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
out=Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/coordination-previews');out.mkdir(parents=True,exist_ok=True)
def camera_video(path,w=640,h=480):
 """Fausse caméra (Y4M) : feuille blanche inclinée avec lignes de texte sur une table CLAIRE.
 Le détecteur rapide (option A) n'y voit rien : la prise automatique prouve qu'OpenCV (option B) fonctionne."""
 q=[(170,70),(470,95),(455,420),(150,400)]
 def inside(x,y):
  s=0
  for i in range(4):
   (ax,ay),(bx,by)=q[i],q[(i+1)%4];c=(bx-ax)*(y-ay)-(by-ay)*(x-ax)
   if c:
    if s and (c>0)!=(s>0):return False
    s=c
  return True
 Y=bytearray(w*h)
 for y in range(h):
  for x in range(w):Y[y*w+x]=(60 if (y%14<3 and 40<(x*7+y)%200<180) else 240) if inside(x,y) else 180+(x+y)//80
 with open(path,'wb') as f:
  f.write(b'YUV4MPEG2 W%d H%d F10:1 Ip A1:1 C420jpeg\n'%(w,h))
  for _ in range(3):f.write(b'FRAME\n'+bytes(Y)+bytes([128])*(w*h//2))
 return path
video=camera_video(str(out/'fake-camera.y4m'))
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BIN') or None,headless=True,args=['--no-sandbox','--use-fake-ui-for-media-stream','--use-fake-device-for-media-stream','--use-file-for-fake-video-capture='+video])
 for role in ['coord','back','manager']:
  for width,height in [(360,800),(390,844),(1280,900)]:
   page=browser.new_page(viewport={'width':width,'height':height});errors=[]
   page.on('pageerror',lambda e:errors.append(str(e)))
   page.on('dialog',lambda d:d.accept('Pièce illisible') if d.type=='prompt' else d.accept())
   # Origine HTTPS simulée : la caméra n'est accessible qu'en contexte sécurisé, comme sur Nextcloud.
   # CSP proche de Nextcloud avec les autorisations demandées par PageController (eval pour OpenCV.js).
   csp="default-src 'none'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; media-src 'self' blob:"
   page.route('https://coordination.test/js/vendor/opencv.js',lambda r:r.fulfill(path=str(root/'js/vendor/opencv.js'),content_type='application/javascript'))
   page.route('https://coordination.test/',lambda r:r.fulfill(content_type='text/html',headers={'Content-Security-Policy':csp},body='<!doctype html><html lang="fr"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="margin:0"><div id="coord-app-root" class="coord-app"></div></body></html>'))
   page.goto('https://coordination.test/')
   page.add_style_tag(content=(root/'css/app.css').read_text())
   page.evaluate('''role=>{
    window.OC={requestToken:'test',generateUrl:p=>'/'+p};window.fixture={role,failWrite:false};
    const user={uid:role,display_name:'Compte de démonstration',groups:[],is_admin:false,is_rcoordinateur:role==='manager',is_coordinateur:role==='coord'||role==='manager',is_backoffice:role==='back'||role==='manager',is_commercial:false,is_readonly:false};
    const initial=role==='back'?'collected':role==='manager'?'new':'assigned';
    const dossiers=Array.from({length:35},(_,i)=>({id:i+1,case_number:'DOS-'+String(i+1).padStart(3,'0'),client_name:'Client Démonstration '+(i+1),phone:'0555000000',address:'Adresse de démonstration',wilaya:'16 - Alger',commune:'Alger',bank:'Banque exemple',status:initial,assigned_coordinator:'coord',appointment_date:role==='back'?'2026-11-03':'',appointment_time:role==='back'?'10:00':'',appointment_notes:'',created_at:1793610000,custom_data:{}}));
    const backScan=[{id:7,checklist_id:1,original_name:'cni.pdf',size:245000,pages:2,uploaded_by:'coord',uploaded_at:1793610000}];
    const checklist=[{id:1,document_code:'cni',document_label_fr:'Carte d’identité',document_label_ar:'بطاقة الهوية',is_mandatory:true,coordinator_checked:role==='back',backoffice_checked:false,scans:role==='back'?backScan:[],scan_count:role==='back'?1:0},{id:2,document_code:'res',document_label_fr:'Justificatif de résidence',document_label_ar:'شهادة الإقامة',is_mandatory:true,coordinator_checked:role==='back',backoffice_checked:false,scans:[],scan_count:0}];
    window.fixture.uploads=[];
    window.fetch=async(path,opt={})=>{
     const u=new URL(path,'https://example.test'), key=u.pathname.replace('/apps/coordination/',''),body=opt.body&&typeof opt.body==='string'?JSON.parse(opt.body):{};let data={success:true};
     if(key==='api/config')data={success:true,current_user:user,wilayas:[{code:'16',name:'Alger'}],banks:['Banque exemple'],document_templates:[],custom_fields:[]};
     else if(key==='api/users-groups')data={success:true,coordinators:[{uid:'coord',display_name:'Coordinateur Test'},{uid:'evil',display_name:'</select><img src=x onerror="window.xss=1">'}],all_users:[{uid:'coord',display_name:'Coordinateur Test'}]};
     else if(key==='api/dossiers'&&u.searchParams.has('detail_id'))data={success:true,data:{dossier:dossiers[0],checklist:JSON.parse(JSON.stringify(checklist)),history:[],can_view_scans:true}};
     else if(key==='api/dossiers'){const offset=Number(u.searchParams.get('offset')||0),limit=Number(u.searchParams.get('limit')||30),rows=dossiers.filter(d=>u.searchParams.get('status')==='all'||d.status===u.searchParams.get('status'));data={success:true,data:rows.slice(offset,offset+limit),has_more:offset+limit<rows.length,next_offset:offset+limit};}
     else if(key.endsWith('/checklist')){if(window.fixture.failWrite){window.fixture.failWrite=false;data={success:false,error:'Connexion de test interrompue'};}else checklist.find(x=>x.id===body.checklist_item_id)[body.role==='coordinator'?'coordinator_checked':'backoffice_checked']=body.checked;}
     else if(key.endsWith('/scans')&&opt.body instanceof FormData){const f=opt.body.get('file'),head=new TextDecoder().decode(new Uint8Array(await f.arrayBuffer()).slice(0,5)),item=checklist.find(x=>x.id===Number(key.split('/')[4]));window.fixture.uploads.push({name:f.name,size:f.size,head});const sc={id:50+window.fixture.uploads.length,checklist_id:item.id,original_name:f.name,size:f.size,pages:1,uploaded_by:'coord',uploaded_at:1793610000};item.scans.push(sc);item.scan_count=item.scans.length;data={success:true,scan:sc};}
     else if(key.endsWith('/appointment'))Object.assign(dossiers[0],body,{status:'appointment_set'});
     else if(key.endsWith('/status'))dossiers[0].status=body.status;
     return{ok:true,status:200,text:async()=>JSON.stringify(data)};
    };
   }''',role)
   page.add_script_tag(content=(root/'js/communes.js').read_text());page.add_script_tag(content=(root/'js/scanpdf.js').read_text());page.add_script_tag(content="window.CoordinationDocScanConfig={opencvUrl:'/js/vendor/opencv.js'}");page.add_script_tag(content=(root/'js/docscan.js').read_text());page.add_script_tag(content=(root/'js/app.js').read_text())
   page.locator('.co-table tbody tr').first.wait_for()
   assert not page.evaluate('window.xss') and page.locator('#coord-app-root img').count()==0,'coordinator display name injected HTML'
   assert page.locator('.co-table tbody tr').count()==15
   search=page.query_selector('#search-input');search.fill('Client')
   page.wait_for_function('e=>!e.isConnected',arg=search)
   assert page.locator('#search-input').evaluate('e=>e===document.activeElement && e.value==="Client"'),'search loses mobile keyboard focus'
   search=page.query_selector('#search-input');search.fill('')
   page.wait_for_function('e=>!e.isConnected',arg=search)
   assert page.locator('.co-tabs .co-tab.active').evaluate('e=>{const a=e.getBoundingClientRect(),b=e.parentElement.getBoundingClientRect();return a.left>=b.left-2&&a.right<=b.right+2}'),'active status filter clipped'
   page.screenshot(path=str(out/f'{role}-{width}-list.png'),full_page=False)
   page.locator('#btn-load-more').click();page.wait_for_function("document.querySelectorAll('.co-table tbody tr').length===30");page.locator('#btn-load-more').click();page.wait_for_function("document.querySelectorAll('.co-table tbody tr').length===35")
   assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'),'page overflow'
   page.locator('.co-btn.open-detail').first.click();page.locator('.co-modal').wait_for()
   page.locator('[data-modal-tab=history]').click()
   assert page.locator('[data-modal-tab=history]').evaluate('e=>e.getBoundingClientRect().right <= innerWidth + 2'),'history tab unreachable on small screen'
   page.locator('[data-modal-tab=checklist]').click()
   if role=='manager':page.locator('[data-modal-tab=checklist]').click()
   assert page.locator('.co-piece-card').count()==2
   if role=='coord':
    # Étape 1 : RDV avant tout scan ou validation
    assert page.locator('[data-role=backoffice]').first.is_disabled()
    assert page.locator('[data-role=coordinator]').first.is_disabled(),'piece can be validated before the appointment'
    assert page.locator('.scan-photo-input').count()==0,'scan offered before the appointment'
    assert 'Étape 1' in page.locator('.co-step-banner').inner_text()
    page.screenshot(path=str(out/f'{role}-{width}-step1.png'),full_page=False)
    page.locator('.co-step-banner [data-goto-tab=appointment]').click()
    page.locator('#modal-app-date').fill('2026-11-03');page.locator('#modal-app-time').fill('10:00')
    page.locator('#btn-save-appointment').click()
    page.wait_for_function("document.querySelector('.co-tab.active[data-modal-tab=checklist]') && document.querySelector('.co-step-banner')?.textContent.includes('Étape 2')")
    # Étape 2 (repli, appareil photo natif et galerie) : la case reste bloquée tant que la pièce n'est pas scannée.
    assert page.locator('#piece-1 [data-role=coordinator]').is_disabled(),'piece can be validated before its scan'
    assert page.locator('#btn-action-collected').is_disabled()
    page.locator('#piece-1 .scan-photo-input').set_input_files([{'name':'photo1.jpg','mimeType':'image/jpeg','buffer':PHOTO}])
    page.locator('#piece-1 .co-scan-thumbs img').first.wait_for()
    page.locator('#piece-1 .scan-photo-input').set_input_files([{'name':'photo2.jpg','mimeType':'image/jpeg','buffer':PHOTO}])
    page.wait_for_function("document.querySelectorAll('#piece-1 .co-scan-thumbs img').length===2")
    page.locator('#piece-1 .co-scan-session').scroll_into_view_if_needed()
    assert page.locator('.btn-scan-send').evaluate('e=>{const r=e.getBoundingClientRect();return r.height>=44&&r.right<=innerWidth+2}'),'send button too small or off screen'
    page.screenshot(path=str(out/f'{role}-{width}-scan.png'),full_page=False)
    page.locator('.btn-scan-send').click()
    page.wait_for_function("document.querySelector('#piece-1 .co-scan-row') && !document.querySelector('#piece-1 [data-role=coordinator]').disabled")
    up=page.evaluate('window.fixture.uploads[0]')
    assert up['head']=='%PDF-' and up['name'].endswith('_cni.pdf') and up['size']>1000,up
    assert page.locator('#piece-1 .co-scan-row a[download]').get_attribute('href').endswith('/scans/51/file?download=1')
    page.evaluate('window.fixture.failWrite=true');page.locator('#piece-1 [data-role=coordinator]').click()
    page.wait_for_function("!document.querySelector('#piece-1 [data-role=coordinator]').checked && document.querySelector('.co-feedback:not([hidden])')?.textContent.includes('interrompue')")
    page.locator('#piece-2 .scan-pick-input').set_input_files([{'name':'residence.pdf','mimeType':'application/pdf','buffer':b'%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n'}])
    page.wait_for_function("!document.querySelector('#piece-2 [data-role=coordinator]').disabled")
    assert page.evaluate('window.fixture.uploads[1].name')=='residence.pdf'
    # Mode guidé : pièce 1 (déjà scannée) rescannée à la caméra, validée, puis pièce 2 validée, sans quitter la caméra.
    page.locator('.btn-guided-scan').click();page.locator('.co-docscan').wait_for()
    assert page.locator('.co-docscan-title-text').inner_text().startswith('Pièce 1/2')
    page.get_by_role('button',name='📷 Scanner à nouveau').click()
    page.locator('.co-docscan-bottom .co-docscan-chip').click()  # Auto coupé le temps qu'OpenCV se charge
    t0=page.evaluate('performance.now()')
    page.wait_for_function("document.querySelector('.co-docscan-engine')?.textContent==='Détection OpenCV'",timeout=60000)
    print(f'   OpenCV prêt en {(page.evaluate("performance.now()")-t0)/1000:.1f} s')
    page.locator('.co-docscan-bottom .co-docscan-chip').click()
    page.locator('.co-docscan-panel .co-guided-thumbs img').wait_for(timeout=30000)
    dims=page.locator('.co-docscan-panel .co-guided-thumbs img').evaluate('i=>i.decode().then(()=>[i.naturalWidth,i.naturalHeight])')
    assert dims[1]>dims[0] and 1.0<dims[1]/dims[0]<1.35,('sheet not straightened to its own shape',dims)
    assert page.locator('.co-guided-primary').evaluate('e=>{const r=e.getBoundingClientRect();return r.height>=50&&r.bottom<=innerHeight}'),'validate button not reachable'
    page.screenshot(path=str(out/f'{role}-{width}-guided.png'),full_page=False)
    page.get_by_role('button',name='✅ Valider et suivant').click()
    page.wait_for_function("document.querySelector('.co-docscan-title-text')?.textContent.startsWith('Pièce 2/2')")
    assert page.evaluate('window.fixture.uploads.length')==3 and page.evaluate('window.fixture.uploads[2].head')=='%PDF-'
    page.get_by_role('button',name='✅ Valider et terminer').click()
    page.wait_for_function("document.querySelector('.co-docscan-panel')?.textContent.includes('Toutes les pièces obligatoires')")
    page.get_by_role('button',name='Fermer et transmettre au BO').click()
    page.wait_for_function("!document.querySelector('.co-docscan') && document.querySelector('.co-checklist-progress')?.textContent.includes('✅ 2 / 2')")
    page.locator('.co-modal-body').evaluate('e=>e.scrollTop=0')
    page.screenshot(path=str(out/f'{role}-{width}-checklist.png'),full_page=False)
    page.locator('#btn-action-collected').click()
    page.wait_for_function("document.querySelector('.co-modal').textContent.includes('Collecté')")
   elif role=='back':
    assert page.locator('[data-role=coordinator]').first.is_disabled()
    assert page.locator('#btn-action-validate-bo').is_disabled()
    assert page.locator('#piece-1 .co-scan-row a:not([download])').get_attribute('href').endswith('/scans/7/file') and page.locator('#piece-1 a[download]').count()==1,'back-office cannot open/download scan'
    assert page.locator('.scan-photo-input').count()==0 and page.locator('.btn-scan-delete').count()==0,'back-office must not scan or delete'
    for item in page.locator('[data-role=backoffice]').all():item.check()
    page.wait_for_function("!document.getElementById('btn-action-validate-bo').disabled")
    page.locator('.co-modal-body').evaluate('e=>e.scrollTop=0')
    page.screenshot(path=str(out/f'{role}-{width}-checklist.png'),full_page=False)
    page.locator('#btn-action-validate-bo').click();page.wait_for_function("document.querySelector('.co-modal').textContent.includes('Validé')")
   else:page.screenshot(path=str(out/f'{role}-{width}-checklist.png'),full_page=False)
   assert page.evaluate("document.querySelector('.co-modal-footer').getBoundingClientRect().bottom <= innerHeight + 2"),'footer outside viewport'
   assert not errors,errors
   print(f'PASS {role} {width}px: list pagination, modal and workflow');page.close()
 browser.close()
