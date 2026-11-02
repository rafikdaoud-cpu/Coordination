#!/usr/bin/env python3
"""Real browser, synthetic API only: mobile list, checklist, errors and RDV/collection."""
from pathlib import Path
import os,sys
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
out=Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/coordination-previews');out.mkdir(parents=True,exist_ok=True)
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BIN') or None,headless=True,args=['--no-sandbox'])
 for role in ['coord','back','manager']:
  for width,height in [(360,800),(390,844),(1280,900)]:
   page=browser.new_page(viewport={'width':width,'height':height});errors=[]
   page.on('pageerror',lambda e:errors.append(str(e)))
   page.on('dialog',lambda d:d.accept('Pièce illisible') if d.type=='prompt' else d.accept())
   page.set_content('<!doctype html><html lang="fr"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="margin:0"><div id="coord-app-root" class="coord-app"></div></body></html>')
   page.add_style_tag(content=(root/'css/app.css').read_text())
   page.evaluate('''role=>{
    window.OC={requestToken:'test',generateUrl:p=>'/'+p};window.fixture={role,failWrite:false};
    const user={uid:role,display_name:'Compte de démonstration',groups:[],is_admin:false,is_rcoordinateur:role==='manager',is_coordinateur:role==='coord'||role==='manager',is_backoffice:role==='back'||role==='manager',is_commercial:false,is_readonly:false};
    const initial=role==='back'?'collected':role==='manager'?'new':'assigned';
    const dossiers=Array.from({length:35},(_,i)=>({id:i+1,case_number:'DOS-'+String(i+1).padStart(3,'0'),client_name:'Client Démonstration '+(i+1),phone:'0555000000',address:'Adresse de démonstration',wilaya:'16 - Alger',commune:'Alger',bank:'Banque exemple',status:initial,assigned_coordinator:'coord',appointment_date:role==='back'?'2026-11-03':'',appointment_time:role==='back'?'10:00':'',appointment_notes:'',created_at:1793610000,custom_data:{}}));
    const checklist=[{id:1,document_label_fr:'Carte d’identité',document_label_ar:'بطاقة الهوية',is_mandatory:true,coordinator_checked:role==='back',backoffice_checked:false},{id:2,document_label_fr:'Justificatif de résidence',document_label_ar:'شهادة الإقامة',is_mandatory:true,coordinator_checked:role==='back',backoffice_checked:false}];
    window.fetch=async(path,opt={})=>{
     const u=new URL(path,'https://example.test'), key=u.pathname.replace('/apps/coordination/',''),body=opt.body&&typeof opt.body==='string'?JSON.parse(opt.body):{};let data={success:true};
     if(key==='api/config')data={success:true,current_user:user,wilayas:[{code:'16',name:'Alger'}],banks:['Banque exemple'],document_templates:[],custom_fields:[]};
     else if(key==='api/users-groups')data={success:true,coordinators:[{uid:'coord',display_name:'Coordinateur Test'}],all_users:[{uid:'coord',display_name:'Coordinateur Test'}]};
     else if(key==='api/dossiers'&&u.searchParams.has('detail_id'))data={success:true,data:{dossier:dossiers[0],checklist,history:[]}};
     else if(key==='api/dossiers'){const offset=Number(u.searchParams.get('offset')||0),limit=Number(u.searchParams.get('limit')||30),rows=dossiers.filter(d=>u.searchParams.get('status')==='all'||d.status===u.searchParams.get('status'));data={success:true,data:rows.slice(offset,offset+limit),has_more:offset+limit<rows.length,next_offset:offset+limit};}
     else if(key.endsWith('/checklist')){if(window.fixture.failWrite){window.fixture.failWrite=false;data={success:false,error:'Connexion de test interrompue'};}else checklist.find(x=>x.id===body.checklist_item_id)[body.role==='coordinator'?'coordinator_checked':'backoffice_checked']=body.checked;}
     else if(key.endsWith('/appointment'))Object.assign(dossiers[0],body,{status:'appointment_set'});
     else if(key.endsWith('/status'))dossiers[0].status=body.status;
     return{ok:true,status:200,text:async()=>JSON.stringify(data)};
    };
   }''',role)
   page.add_script_tag(content=(root/'js/communes.js').read_text());page.add_script_tag(content=(root/'js/app.js').read_text())
   page.locator('.co-table tbody tr').first.wait_for()
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
    assert page.locator('[data-role=backoffice]').first.is_disabled()
    page.evaluate('window.fixture.failWrite=true');page.locator('[data-role=coordinator]').first.click()
    page.wait_for_function("!document.querySelector('[data-role=coordinator]').checked")
    assert 'interrompue' in page.locator('.co-feedback:visible').last.inner_text()
    for item in page.locator('[data-role=coordinator]').all():item.check()
    page.wait_for_function("document.querySelector('.co-checklist-progress').textContent.startsWith('2 / 2')")
    page.locator('.co-modal-body').evaluate('e=>e.scrollTop=0')
    page.screenshot(path=str(out/f'{role}-{width}-checklist.png'),full_page=False)
    page.locator('#btn-next-step').click();page.locator('#modal-app-date').fill('2026-11-03');page.locator('#modal-app-time').fill('10:00')
    page.locator('#btn-save-appointment').click();page.locator('#btn-action-collected').wait_for(state='visible');page.locator('#btn-action-collected').click()
    page.wait_for_function("document.querySelector('.co-modal').textContent.includes('Collecté')")
   elif role=='back':
    assert page.locator('[data-role=coordinator]').first.is_disabled()
    assert page.locator('#btn-action-validate-bo').is_disabled()
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
