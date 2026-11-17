'use strict';
const $ = id => document.getElementById(id);
let csrf = null;
let reviewing = null;
let reviewPage = 1;
function info(message, error=false) {
  $('message').textContent = message;
  $('message').className = error ? 'error' : 'success';
}
async function request(url, init={}) {
  const headers = {...(init.headers || {})};
  if (init.method && init.method !== 'GET') headers['X-CSRF-Token'] = csrf;
  const r = await fetch(url, {...init, credentials:'same-origin', headers});
  let result;
  try { result = await r.json(); } catch { throw new Error('Serverantwort ist kein JSON.'); }
  if (!r.ok) throw new Error(result.error || 'Serverfehler: '+r.status);
  return result;
}
async function session() {
  if (location.protocol !== 'https:' && !['localhost','127.0.0.1'].includes(location.hostname)) {
    throw new Error('Anmeldung nur über HTTPS zulässig.');
  }
  const s=await request('/api/v1/session'); csrf=s.csrf;
  const loggedIn=Boolean(s.user_id);
  $('loginPanel').classList.toggle('hidden',loggedIn);
  $('importPanel').classList.toggle('hidden',!loggedIn);
  if (loggedIn) await guilds();
}
async function guilds() {
  const result = await request('/api/v1/guilds');
  $('guild').replaceChildren();
  for (const g of result.guilds.filter(x=>['admin','bank_manager'].includes(x.role))) {
    const option=document.createElement('option');option.value=String(g.id);
    option.textContent=`${g.name} – ${g.realm} (${g.role})`;$('guild').appendChild(option);
  }
  if (!$('guild').options.length) { $('upload').disabled=true; info('Keine Bankverwalterberechtigung vorhanden.',true);return; }
  $('upload').disabled=false; await history();
}
async function history() {
  const id=$('guild').value;
  if (!id) return;
  const r=await request('/api/v1/guilds/'+encodeURIComponent(id)+'/imports');
  $('history').replaceChildren();
  $('review').classList.add('hidden');
  if (!r.imports.length) { $('history').textContent='Noch keine Importe.';return; }
  for (const scan of r.imports) {
    const e=document.createElement('div');e.className='historyrow';
    const title=document.createElement('strong'); title.textContent=`#${scan.id} · ${scan.status} · ${scan.quality}`;
    const detail=document.createElement('span');detail.className='muted';
    detail.textContent=`Erfasst: ${scan.observed_at} UTC · Importiert: ${scan.imported_at} UTC`;
    const view=document.createElement('button');view.type='button';view.className='secondary';
    view.textContent='Positionen ansehen';view.addEventListener('click',()=>review(scan.id,1).catch(e=>info(e.message,true)));
    e.append(title,detail,view); $('history').append(e);
  }
}
async function review(snapshotId,page) {
  const r=await request('/api/v1/guilds/'+encodeURIComponent($('guild').value)+'/imports/'+
    encodeURIComponent(snapshotId)+'/review?page='+page);
  if (page===1) $('reviewItems').replaceChildren();
  reviewing=snapshotId;reviewPage=page;
  $('review').classList.remove('hidden');
  for (const item of r.items) {
    const e=document.createElement('div');e.className='historyrow';
    const title=document.createElement('strong'); title.textContent=`${item.item_name} · ${item.scanned_qty} Stück (${item.stacks} Stapel)`;
    const detail=document.createElement('span'); detail.className='muted';
    detail.textContent=`Item-ID ${item.wow_item_id} · Serverbestand: ${item.known_qty===null?'noch unbekannt':item.known_qty} · Status: ${item.confidence || 'nicht erfasst'}`;
    e.append(title,detail);$('reviewItems').append(e);
  }
  $('more').classList.toggle('hidden',!r.has_next);
}
$('more').addEventListener('click',()=>review(reviewing,reviewPage+1).catch(e=>info(e.message,true)));
$('loginForm').addEventListener('submit',async e=>{
  e.preventDefault();
  try {
    const r=await request('/api/v1/login',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({email:$('email').value,password:$('password').value})});
    csrf=r.csrf;$('password').value='';info('Angemeldet.');await session();
  } catch(err) {info(err.message,true);}
});
$('uploadForm').addEventListener('submit',async e=>{
  e.preventDefault();
  const file=$('savedvars').files[0];
  if (!file || file.name!=='Banklord.lua' || file.size>4_194_304) {
    info('Bitte Banklord.lua (max. 4 MiB) auswählen.',true);return;
  }
  const data=new FormData(); data.set('savedvars',file);
  $('upload').disabled=true;
  try {
    const r=await request('/api/v1/guilds/'+encodeURIComponent($('guild').value)+'/imports', {method:'POST', body:data});
    info(`Inventur #${r.import.id} ${r.import.created?'vorgemerkt':'war bereits vorhanden'}: `+
      `${r.import.tabs} Fächer, ${r.import.slots} Slots, ${r.import.variants} Varianten. `+
      `Status: ${r.import.status}. Der Lagerbestand wurde NICHT verändert.`);
    await history();
  } catch(err) {info('Import abgelehnt: '+err.message,true);}
  finally {$('upload').disabled=false;}
});
$('guild').addEventListener('change',()=>history().catch(e=>info(e.message,true)));
$('logout').addEventListener('click',async ()=>{
  try {await request('/api/v1/logout',{method:'POST'});csrf=null;await session();info('Abgemeldet.');}
  catch(err){info(err.message,true);}
});
session().catch(err=>info('Anmeldung derzeit nicht verfügbar: '+err.message,true));
