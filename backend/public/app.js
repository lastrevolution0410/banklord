'use strict';
const $=id=>document.getElementById(id);
const T=key=>window.BLLang?.tr(key)||key;
const webLocale=()=>window.BLLang?.locale||'deDE';
const state={csrf:'',user:null,guilds:[],guild:null,items:[],cart:new Map(),tab:'inventory',orders:[],details:new Map(),favorites:new Set(),wishlist:[],templates:[],itemPage:1,hasNextItems:false,pendingOrder:null,selectedStockId:null,inventoryFilter:'all',categoryKey:'all',expandedCategories:new Set(),catalogPage:0,showEmpty:false,appearances:new Map(),appearanceRequests:new Map()};
const el=(tag,cls,text)=>{const x=document.createElement(tag);if(cls)x.className=cls;if(text!==undefined)x.textContent=String(text);return x;};
const add=(parent,...nodes)=>{parent.append(...nodes);return parent;};
const freshId=()=>{const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);return 'bl_'+Array.from(bytes,x=>x.toString(16).padStart(2,'0')).join('');};
let toastTimer;
function notice(message,error=false){const toast=$('toast');toast.textContent=message;toast.className='toast'+(error?' error':'');clearTimeout(toastTimer);toastTimer=setTimeout(()=>toast.classList.add('hidden'),7000);}
async function api(path,opts={}){
  const headers={...(opts.headers||{})};
  if(opts.method && opts.method!=='GET')headers['X-CSRF-Token']=state.csrf;
  const r=await fetch('/api/v1'+path,{...opts,headers,credentials:'same-origin',cache:'no-store'});
  const result=await r.json().catch(()=>({error:'INVALID_SERVER_RESPONSE'}));
  // Session expired or replaced (e.g. after an update): fetch a fresh token and retry once.
  if(result.error==='CSRF_INVALID'&&!opts._retried&&path!=='/session'){
    const fresh=await fetch('/api/v1/session',{credentials:'same-origin',cache:'no-store'}).then(x=>x.json()).catch(()=>null);
    if(fresh&&fresh.csrf){state.csrf=fresh.csrf;return api(path,{...opts,_retried:true});}
  }
  if(result.error==='DEMO_READ_ONLY'||result.error==='DEMO_HIDDEN')throw Error(result.error==='DEMO_HIDDEN'?'In der Demo nicht verfügbar.':'Demo: Das geht nur mit einem echten Konto. Änderungen sind hier deaktiviert.');
  if(result.error==='SETUP_REQUIRED'){location.href='install';throw Error('SETUP_REQUIRED');}
  if(!r.ok)throw Error(result.error||'HTTP_'+r.status);
  return result;
}
const post=(p,body,extra={})=>api(p,{method:'POST',headers:{'Content-Type':'application/json',...extra},body:JSON.stringify(body)});
const path=(suffix='')=>'/guilds/'+state.guild.id+'/'+suffix;
function administrator(){return state.guild&&['officer','bank_manager','admin'].includes(state.guild.role);}
function manager(){return state.guild&&['bank_manager','admin'].includes(state.guild.role);}
function cartTotal(){return [...state.cart.values()].reduce((a,x)=>a+x.quantity,0);}
function roleText(role){return {admin:'Administrator',bank_manager:'Bankverwalter',officer:'Offizier',member:'Gildenmitglied'}[role]||role;}
function qty(value){return Number(value||0).toLocaleString(webLocale()==='enUS'?'en-US':'de-DE');}
function setTab(tab){
  if(typeof hideTooltip==='function')hideTooltip();
  if(tab==='admin'&&!administrator())return;
  if(tab==='sync'&&!manager())return;
  if(tab==='procurement'&&!manager())return;
  if(tab==='updates'&&state.guild?.role!=='admin')return;
  if(tab==='updates')loadReleases().catch(e=>notice(e.message,true));
  state.tab=tab;
  for(const name of ['inventory','cart','orders','notifications','admin','sync','favorites','account','members','activity','procurement','updates'])$('view'+name[0].toUpperCase()+name.slice(1)).classList.toggle('hidden',tab!==name);
  document.querySelectorAll('.tabs button').forEach(b=>b.classList.toggle('active',b.dataset.tab===tab));
  if(tab==='cart')renderCart();
  if(tab==='orders'||tab==='admin'||tab==='inventory')loadOrders().catch(e=>notice(e.message,true));
  if(tab==='sync'){loadImports().catch(e=>notice(e.message,true));loadTokens().catch(e=>notice(e.message,true));loadScanAutomation().catch(e=>notice(e.message,true));loadPeerDrafts().catch(e=>notice(e.message,true));}
  if(tab==='notifications')loadNotifications().catch(e=>notice(e.message,true));
  if(tab==='favorites')loadPersonal().catch(e=>notice(e.message,true));
  if(tab==='account'){loadCharacters().catch(e=>notice(e.message,true));loadOrderTokens().catch(e=>notice(e.message,true));}
  if(tab==='members')loadMembers().catch(e=>notice(e.message,true));
  if(tab==='activity')loadActivity().catch(e=>notice(e.message,true));
  if(tab==='procurement')loadProcurement().catch(e=>notice(e.message,true));
}
function updateCartBadge(){$('cartCount').textContent=String(cartTotal());}
function switchGuild(){
  const id=Number($('guildSelector').value);state.guild=state.guilds.find(g=>Number(g.id)===id)||state.guilds[0];
  if(!state.guild)return;
  state.cart.clear();state.pendingOrder=null;state.details.clear();state.appearances.clear();state.appearanceRequests.clear();state.selectedStockId=null;state.inventoryFilter='all';state.categoryKey='all';state.expandedCategories.clear();state.catalogPage=0;appearanceLoading.queue=[];appearanceLoading.queued.clear();iconLookupByItemId.clear();updateCartBadge();
  $('guildTitle').textContent=state.guild.name;
  document.title=(window.BLLang?.text||String)('Gildenbank – '+state.guild.name+' – Banklord');
  $('guildSub').textContent=`${state.guild.realm} · ${roleText(state.guild.role)}`;$('userBadge').textContent='';$('guildSelector').classList.toggle('hidden',state.guilds.length<2);$('bellBtn').classList.remove('hidden');
  $('adminNavGroup').classList.toggle('hidden',!administrator());$('adminTab').classList.toggle('hidden',!administrator());$('syncTab').classList.toggle('hidden',!manager());$('membersTab').classList.toggle('hidden',state.guild.role!=='admin');$('activityTab').classList.toggle('hidden',!administrator());$('procurementTab').classList.toggle('hidden',!manager());$('updatesTab').classList.toggle('hidden',state.guild.role!=='admin');
  state.itemPage=1;setTab('inventory');loadPersonal().catch(()=>{});loadItems().catch(e=>notice('Bestand: '+e.message,true));loadNotifications().catch(()=>{});
}
async function initialize(){
  if(location.protocol!=='https:'&&!['localhost','127.0.0.1'].includes(location.hostname))throw Error('HTTPS_REQUIRED');
  const session=await api('/session');state.csrf=session.csrf;state.user=session.user_id;state.demo=Boolean(session.demo);
  $('demoBanner').classList.toggle('hidden',!state.demo);
  const logged=Boolean(state.user);document.body.classList.toggle('is-logged-in',logged);$('loginPage').classList.toggle('hidden',logged);$('workspace').classList.toggle('hidden',!logged);
  $('logoutBtn').classList.toggle('hidden',!logged);$('userBadge').textContent=logged?'':'Nicht angemeldet';if(!logged){$('bellBtn').classList.add('hidden');$('guildSelector').classList.add('hidden');$('guildTitle').textContent='Banklord';$('guildSub').textContent='Gildenbank · Bestellungen';}
  if(!logged){api('/demo').then(d=>$('demoBox').classList.toggle('hidden',!d.enabled)).catch(()=>{});return;}
  const data=await api('/guilds');state.guilds=data.guilds;
  $('guildSelector').replaceChildren();for(const g of data.guilds){const o=el('option','',`${g.name} · ${g.realm}`);o.value=g.id;$('guildSelector').append(o);}
  if(!data.guilds.length){notice('Für dein Konto ist noch keine freigeschaltete Gilde hinterlegt.',true);return;}
  switchGuild();loadNotifications().catch(()=>{});
}
async function loadItems(){
  if(!state.guild)return;
  const q=$('searchInput').value.trim();const data=await api(path('items')+'?q='+encodeURIComponent(q)+'&page='+state.itemPage+'&show_empty='+(state.showEmpty?'1':'0')+'&locale='+encodeURIComponent(webLocale()));state.items=data.items;state.hasNextItems=Boolean(data.has_next);state.catalogPage=0;renderItems();
}
// Presentation-only inventory filters: the server remains authoritative for search and availability.
function filteredInventory(){
  return state.items.filter(item=>{
    if(state.categoryKey!=='all' && !(
      item.category===state.categoryKey ||
      (item.category+'\u001f'+(item.subcategory||''))===state.categoryKey))return false;
    const available=item.available_qty!==null&&Number(item.available_qty)>0;
    if(state.inventoryFilter==='available')return available;
    if(state.inventoryFilter==='unconfirmed')return item.available_qty===null;
    if(state.inventoryFilter==='reserved')return Number(item.reserved_qty)>0;
    if(state.inventoryFilter==='favorites')return state.favorites.has(Number(item.stock_id));
    return true; // server query already applies optional zero-stock filter
  });
}
function renderItemTypeCategories(){
  const root=$('itemTypeCategories');root.replaceChildren();
  const cats=new Map();
  for(const item of state.items){
    const cat=item.category||'Weitere Gegenstände';
    if(!cats.has(cat))cats.set(cat,{count:0,subs:new Map()});
    const value=cats.get(cat);value.count++;
    if(item.subcategory){value.subs.set(item.subcategory,(value.subs.get(item.subcategory)||0)+1);}
  }
  const addCategory=(key,label,count,sub=false,expandable=false)=>{
    const selected=state.categoryKey===key;
    const btn=el('button','catalog-filter catalog-type'+(sub?' catalog-subtype':'')+(selected?' selected':''));
    btn.type='button';btn.setAttribute('aria-pressed',String(selected));
    const symbol=expandable?(state.expandedCategories.has(key)?'− ':'+ '):sub?'  ':'';
    btn.append(el('span','',symbol+label),el('span','filter-count',String(count)));
    btn.onclick=()=>{
      if(expandable){if(state.expandedCategories.has(key))state.expandedCategories.delete(key);else state.expandedCategories.add(key);}
      state.categoryKey=key;renderItems();
    };root.append(btn);
  };
  addCategory('all',T('all'),state.items.length);
  for(const [name,data] of [...cats.entries()].sort((a,b)=>a[0].localeCompare(b[0],webLocale()==='deDE'?'de':'en'))){
    addCategory(name,name,data.count,false,data.subs.size>0);
    if(state.expandedCategories.has(name))for(const [sub,count] of [...data.subs.entries()].sort((a,b)=>a[0].localeCompare(b[0]))){
      addCategory(name+'\u001f'+sub,sub,count,true,false);
    }
  }
}
function renderInventoryFilters(){
  const root=$('inventoryCategories');root.replaceChildren();
  const definitions=[
    ['all',T('all'),state.items.length],
    ['available',T('available'),state.items.filter(x=>x.available_qty!==null&&Number(x.available_qty)>0).length],
    ['reserved',T('reserved'),state.items.filter(x=>Number(x.reserved_qty)>0).length],
    ['unconfirmed',T('unconfirmed'),state.items.filter(x=>x.available_qty===null).length],
    ['favorites',T('star'),state.items.filter(x=>state.favorites.has(Number(x.stock_id))).length],
  ];
  for(const [key,label,count] of definitions){
    const btn=el('button','catalog-filter'+(state.inventoryFilter===key?' selected':''));
    btn.type='button';btn.setAttribute('aria-pressed',String(state.inventoryFilter===key));
    btn.append(el('span','',label),el('span','filter-count',String(count)));
    btn.onclick=()=>{state.inventoryFilter=key;state.catalogPage=0;renderItems();};root.append(btn);
  }
  const toggler=el('label','show-empty-switch');
  const check=el('input');check.type='checkbox';check.checked=state.showEmpty;check.id='showEmptyStock';
  check.onchange=()=>{state.showEmpty=check.checked;state.itemPage=1;loadItems().catch(e=>notice(e.message,true));};
  toggler.append(check,el('span','','Auch leere Bestände anzeigen'));root.append(toggler);
}
// Appearance metadata is untrusted and OPTIONAL: stock numbers always come from our guild API.
// No third-party JavaScript executes in the logged-in application.
const appearanceLoading={observer:null,timer:null,current:null,sticky:false,queue:[],queued:new Set(),active:0};
const iconLookupByItemId=new Map();
/* WarcraftDB advertises a CORS JSON tooltip endpoint with an icon field.
 * Resolve a STRICTLY trusted image URL or icon filename, never arbitrary remote HTML.
 * Numeric client FileDataIDs must NOT be used as Wowhead icon image filenames. */
function trustedIconUrl(value){
  if(value && typeof value==='object')value=value.url||value.src||value.name||value.iconName||null;
  if(typeof value!=='string')return null;
  const raw=value.trim();
  if(/^[A-Za-z][A-Za-z0-9_]{1,79}$/.test(raw))
    return 'https://wow.zamimg.com/images/wow/icons/medium/'+raw.toLowerCase()+'.jpg';
  try{
    const url=new URL(raw,'https://mop.warcraftdb.com');
    if(url.protocol!=='https:'||!['mop.warcraftdb.com','wow.zamimg.com'].includes(url.hostname))return null;
    if(url.port||url.username||url.password||url.search||url.hash)return null;
    if(!/^\/[A-Za-z0-9_./%-]{1,220}\.(?:png|jpg|jpeg|webp)$/i.test(url.pathname) || url.pathname.includes('..'))return null;
    return url.href;
  }catch(_e){return null;}
}

const WOW_QUALITY=['poor','common','uncommon','rare','epic','legendary','artifact','heirloom'];
function stockVisual(item){return state.appearances.get(Number(item.stock_id))||null;}
function qualityClass(visual){return Number.isInteger(visual?.quality)?' bl-quality-'+visual.quality:'';}
function loadIconInto(box,item,url){
  const safeUrl=trustedIconUrl(url);
  if(!safeUrl){box.title='Icon-Adresse ungültig oder nicht aufgelöst';return;}
  const img=el('img');img.alt='';img.loading='lazy';img.referrerPolicy='no-referrer';
  img.onerror=async()=>{
    if(box.firstChild!==img)return;
    img.remove();box.classList.remove('has-image');box.textContent='?';
    box.title='Icon nicht verfügbar – Dateiquelle prüfen';
    if(!box.dataset.iconFallbackAttempted){
      box.dataset.iconFallbackAttempted='1';
      const alternative=await fallbackIconFor(item.wow_item_id);
      if(alternative&&alternative!==safeUrl&&box.isConnected)loadIconInto(box,item,alternative);
    }
  };
  box.replaceChildren(img);box.classList.add('has-image');box.title='';img.src=safeUrl;
}
function makeItemIcon(item){
  const box=el('span','item-icon-placeholder wow-item-icon','');box.setAttribute('aria-hidden','true');
  box.dataset.stockIcon=String(item.stock_id);
  box.title=item.icon_status==='file-id-unresolved'?'Grafik-ID vorhanden, aber noch kein Iconname aufgelöst':'Icon wird geladen';box.textContent='?';
  const meta=stockVisual(item);
  const initialIcon=meta?.icon||item.icon_url||null;
  if(initialIcon)loadIconInto(box,item,initialIcon);
  else box.title='Iconname fehlt; Auflösung aus öffentlicher MoP-Gegenstandsdatenbank läuft, sofern erreichbar';
  return box;
}
// Public WoW item IDs only, no account or guild data is sent. This is a VISUAL
// fallback if the web host cannot reach the icon service or only FileDataIDs were
// captured. Warcraft DB documents cross-origin JSON responses for its tooltip API.
// Never execute third-party JavaScript and never trust response text as HTML.
function fallbackIconFor(wowItemID){
  const id=Number(wowItemID);
  if(!Number.isSafeInteger(id)||id<1||id>10000000)return Promise.resolve(null);
  if(iconLookupByItemId.has(id))return iconLookupByItemId.get(id);
  const work=(async()=>{
    try{
      const controller=new AbortController();
      const abort=setTimeout(()=>controller.abort(),3500);
      let result;
      try{
        const res=await fetch('https://mop.warcraftdb.com/api/v1/tooltip/item/'+id,
          {mode:'cors',credentials:'omit',referrerPolicy:'no-referrer',cache:'force-cache',signal:controller.signal});
        if(!res.ok)return null;
        const raw=await res.text();if(raw.length>100000)return null;
        result=JSON.parse(raw);
      }finally{clearTimeout(abort);}
      // Real provider response: {html, icon, name, quality}. Support a filename,
      // a safe absolute image URL, a relative image path, or nested icon descriptor.
      const value=result?.data&&typeof result.data==='object'?result.data:result;
      return trustedIconUrl(value?.icon)||trustedIconUrl(value?.iconName)||
        trustedIconUrl(result?.icon)||null;
    }catch(_error){
      // Cross-origin failures are common on shared hosting and browsers.
    }
    // Independent Wowhead JSON metadata fallback; no third-party JS or HTML.
    try{
      const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),3500);
      try{
        const res=await fetch('https://nether.wowhead.com/tooltip/item/'+id+'?dataEnv=8',
          {mode:'cors',credentials:'omit',referrerPolicy:'no-referrer',signal:controller.signal});
        if(!res.ok)return null;
        const raw=await res.text();if(raw.length>100000)return null;
        const data=JSON.parse(raw);
        return trustedIconUrl(data?.icon)||trustedIconUrl(data?.data?.icon)||null;
      }finally{clearTimeout(timer);}
    }catch(_error){return null;}
  })();iconLookupByItemId.set(id,work);return work;
}
async function appearanceFor(item){
  const id=Number(item.stock_id);
  if(state.appearances.has(id))return state.appearances.get(id);
  if(state.appearanceRequests.has(id))return state.appearanceRequests.get(id);
  const guildId=state.guild?.id;
  const requestedLocale=webLocale();
  const promise=(async()=>{
    let value={available:false,icon:null,quality:null,tooltip_html:null};
    try{const r=await api(path('items/'+id+'/appearance')+'?locale='+encodeURIComponent(requestedLocale));value=r.appearance||value;}catch(_err){/* graceful fall-back: no false metadata */}
    if(Number(state.guild?.id)!==Number(guildId)||requestedLocale!==webLocale())return value;
    // The localized client tooltip remains authoritative for display; only
    // the ICON may come from the separate public fallback service.
    if(!value.icon){
      const fallback=await fallbackIconFor(item.wow_item_id);
      if(fallback)value={...value,icon:fallback,icon_fallback:true};
    }
    if(Number(state.guild?.id)!==Number(guildId)||requestedLocale!==webLocale())return value;
    state.appearances.set(id,value);
    // Update only visual nodes; no rerender of the cart or selected input.
    for(const icon of document.querySelectorAll('[data-stock-icon="'+id+'"]')){
      if(value.icon)loadIconInto(icon,item,value.icon);
      else if(!icon.querySelector('img'))icon.title='Keine auflösbare Icon-Dateiquelle vorhanden (Datei-ID genügt nicht)';
    }
    for(const name of document.querySelectorAll('[data-stock-quality="'+id+'"]')){
      for(let n=0;n<8;n++)name.classList.remove('bl-quality-'+n);
      if(Number.isInteger(value.quality))name.classList.add('bl-quality-'+value.quality);
    }
    // A bank name is not necessarily the locale selected in the browser.
    // Client-captured names are intentionally stored per build + language.
    if(value.provider==='game-client-snapshot' && value.name){
      for(const target of document.querySelectorAll('[data-stock-localized-name="'+id+'"]')){
        target.textContent=value.name;
      }
    }
    if(appearanceLoading.current?.stock_id===id)paintTooltip(appearanceLoading.current);
    return value;
  })().finally(()=>{if(state.appearanceRequests.get(id)===promise)state.appearanceRequests.delete(id);});
  state.appearanceRequests.set(id,promise);return promise;
}
// Limit metadata lookups so shared hosting does not receive a request burst.
function queueAppearance(item){
  const id=Number(item.stock_id);
  if(state.appearances.has(id)||state.appearanceRequests.has(id)||appearanceLoading.queued.has(id))return;
  appearanceLoading.queue.push({item,guildId:Number(state.guild?.id)});
  appearanceLoading.queued.add(id);pumpAppearanceQueue();
}
function pumpAppearanceQueue(){
  while(appearanceLoading.active<4&&appearanceLoading.queue.length){
    const task=appearanceLoading.queue.shift();
    appearanceLoading.queued.delete(Number(task.item.stock_id));
    if(task.guildId!==Number(state.guild?.id))continue;
    if(state.appearances.has(Number(task.item.stock_id)))continue;
    appearanceLoading.active++;
    appearanceFor(task.item).finally(()=>{appearanceLoading.active--;pumpAppearanceQueue();});
  }
}
function observeAppearance(icon,item){
  if(typeof IntersectionObserver!=='function'){queueAppearance(item);return;}
  if(!appearanceLoading.observer)appearanceLoading.observer=new IntersectionObserver(entries=>{
    for(const entry of entries){if(!entry.isIntersecting)continue;
      appearanceLoading.observer.unobserve(entry.target);
      const stock=state.items.find(x=>Number(x.stock_id)===Number(entry.target.dataset.stockIcon));
      if(stock)queueAppearance(stock);
    }
  },{rootMargin:'100px'});
  appearanceLoading.observer.observe(icon);
}
function paintTooltip(item){
  const box=$('wowItemTooltip');if(!box)return;
  const visual=stockVisual(item);
  const name=el('strong','wow-tooltip-name'+qualityClass(visual),visual?.name||item.name);
  const link=el('span','wow-tooltip-level','WoW-Item #'+item.wow_item_id);
  const stock=el('div','wow-tooltip-inventory');
  stock.append(el('div','','Bankinventur: '+qty(item.physical_qty)+' Stück'),
    el('div','','Reserviert: '+qty(item.reserved_qty)+' Stück'),
    el('div','status-warn',item.available_qty===null?'Verfügbarkeit nicht bestätigt':'Serverseitig anforderbar: '+qty(item.available_qty)+' Stück'));
  const body=el('div','wow-tooltip-data');
  if(visual?.provider==='game-client-snapshot' && visual?.tooltip_locale===webLocale()
      && Array.isArray(visual.tooltip_lines) && visual.tooltip_lines.length){
    // No rich HTML from either the WoW client or a third-party service.
    // All strings are rendered as text nodes; colors are strictly normalized.
    for(const line of visual.tooltip_lines){
      const row=el('div','wow-client-tooltip-row');
      const left=el('span','',String(line.left||''));
      const right=el('span','',String(line.right||''));
      if(/^[0-9a-f]{6}$/.test(line.leftColor||'')) left.style.color='#'+line.leftColor;
      if(/^[0-9a-f]{6}$/.test(line.rightColor||'')) right.style.color='#'+line.rightColor;
      if(right.textContent)row.classList.add('has-right');
      row.append(left,right);body.append(row);
    }
  }else{
    body.append(el('p','muted',T('tooltipMissing')));
    if(visual?.provider==='game-client-snapshot' && Number.isInteger(visual.item_level)){
      body.append(el('p','',webLocale()==='deDE'?'Gegenstandsstufe: '+visual.item_level:'Item level: '+visual.item_level));
    }
  }
  const accuracy=el('div','wow-variant-note');
  if(visual?.variant_status==='partial'||visual?.variant_status==='unverified')accuracy.classList.add('wow-variant-warning');
  accuracy.textContent=visual?.provider==='game-client-snapshot' ? (webLocale()==='deDE' ? 'Quelle: lokalisierter WoW-Client-Snapshot (nicht unabhängig verifiziert).' : 'Source: localized WoW client snapshot (not independently verified).') : T('tooltipMissing');
  const note=el('p','wow-tooltip-hint',visual?.provider==='game-client-snapshot' ? ('WoW '+(visual?.client_version||'')+' · '+(visual?.tooltip_locale||'')+' · Build '+(visual?.client_build||'?')) : T('tooltipSource'));
  box.replaceChildren(name,link,body,accuracy,stock,note);
}
function placeTooltip(event){
  const box=$('wowItemTooltip');if(!box||box.classList.contains('hidden'))return;
  const margin=12, width=Math.min(365,window.innerWidth-2*margin);
  box.style.width=width+'px';
  let x=(event?.clientX??window.innerWidth/2)+20;
  let y=(event?.clientY??window.innerHeight/2)+12;
  if(x+width+margin>window.innerWidth)x=(event?.clientX??0)-width-18;
  x=Math.max(margin,Math.min(x,window.innerWidth-width-margin));
  if(y+box.offsetHeight+margin>window.innerHeight)y=Math.max(margin,(event?.clientY??0)-box.offsetHeight-12);
  box.style.left=x+'px';box.style.top=Math.max(margin,y)+'px';
}
function showTooltip(item,event,sticky=false){
  clearTimeout(appearanceLoading.timer);appearanceLoading.current=item;appearanceLoading.sticky=sticky;
  const box=$('wowItemTooltip');if(!box)return;
  paintTooltip(item);box.classList.remove('hidden');box.classList.toggle('is-sticky',sticky);placeTooltip(event);
  appearanceFor(item);
}
function hideTooltip(){clearTimeout(appearanceLoading.timer);appearanceLoading.current=null;appearanceLoading.sticky=false;$('wowItemTooltip')?.classList.add('hidden');$('wowItemTooltip')?.classList.remove('is-sticky');}
function tooltipBindings(node,item){
  node.addEventListener('pointerenter',e=>{
    if(e.pointerType==='touch')return;
    appearanceLoading.timer=setTimeout(()=>showTooltip(item,e),220);
  });
  node.addEventListener('pointerleave',hideTooltip);
  node.addEventListener('pointermove',e=>{if(appearanceLoading.current?.stock_id===item.stock_id)placeTooltip(e);});
  node.addEventListener('focus',()=>{showTooltip(item);});
  node.addEventListener('blur',hideTooltip);
}
window.addEventListener('keydown',e=>{if(e.key==='Escape')hideTooltip();});
window.addEventListener('scroll',()=>{if(!appearanceLoading.sticky)hideTooltip();},{capture:true,passive:true});
document.addEventListener('pointerdown',e=>{if(appearanceLoading.sticky&&!e.target.closest('.wow-detail-tooltip, .wow-item-tooltip'))hideTooltip();});
function renderItemDetail(){
  const root=$('itemDetail');root.replaceChildren();
  const item=state.items.find(x=>Number(x.stock_id)===Number(state.selectedStockId));
  if(!item){root.append(el('p','muted',T('selectItem')));return;}
  const header=el('div','item-detail-heading');
  const icon=makeItemIcon(item);
  const name=el('strong','item-detail-name'+qualityClass(stockVisual(item)),stockVisual(item)?.name||item.name);name.dataset.stockQuality=String(item.stock_id);name.dataset.stockLocalizedName=String(item.stock_id);
  header.append(icon,name);root.append(header);observeAppearance(icon,item);
  const note=el('div','item-detail-stats');
  note.append(el('p','','Inventur: '+qty(item.physical_qty)+' Stück'),el('p','','Reserviert: '+qty(item.reserved_qty)+' Stück'),
    el('p',item.available_qty===null?'status-warn':'status-available',item.available_qty===null?'Verfügbarkeit nicht bestätigt':'Anforderbar: '+qty(item.available_qty)+' Stück'),
    el('p','muted','Bestands-ID: '+item.stock_id+' · WoW-Item: '+item.wow_item_id));
  root.append(note);
  const tooltipBtn=el('button','secondary button-sm wow-detail-tooltip',T('openValues'));tooltipBtn.type='button';tooltipBtn.onclick=e=>{showTooltip(item,e,true);placeTooltip(e);};root.append(tooltipBtn);
  const link=el('a','item-link','WoW-Gegenstand ansehen ↗');link.href='https://www.wowhead.com/mop-classic/item='+encodeURIComponent(item.wow_item_id);link.target='_blank';link.rel='noopener noreferrer';root.append(link);
  const controls=el('div','detail-order');
  const caption=el('label','','Gewünschte Menge');const amount=el('input');amount.type='number';amount.min='1';amount.max='1000000';amount.value='1';amount.inputMode='numeric';amount.setAttribute('aria-label','Menge für '+item.name);
  caption.append(amount);
  const addBtn=el('button','','In Warenkorb legen');addBtn.type='button';
  addBtn.onclick=()=>{const n=Number(amount.value);if(!Number.isSafeInteger(n)||n<1||n>1000000){notice('Ungültige Menge.',true);return;}
    const id=Number(item.stock_id);const previous=state.cart.get(id)?.quantity||0;
    if(n+previous>1000000){notice('Maximale Anfragemenge überschritten.',true);return;}
    state.cart.set(id,{id,name:item.name,quantity:n+previous,max:1000000,available:item.available_qty===null?null:Number(item.available_qty)});
    state.pendingOrder=null;updateCartBadge();notice(item.name+' vorgemerkt. Erst eine Serverbestätigung erzeugt eine Bestellung.');
  };
  const fav=el('button','secondary button-sm',state.favorites.has(Number(item.stock_id))?'★ Favorit':'☆ Merken');
  fav.type='button';fav.onclick=async()=>{try{const id=Number(item.stock_id);const on=!state.favorites.has(id);
    await api(path('favorites/'+id),{method:on?'PUT':'DELETE'});if(on)state.favorites.add(id);else state.favorites.delete(id);
    renderItems();}catch(e){notice(e.message,true);}};
  const watch=el('button','secondary button-sm',T('wishlist'));watch.type='button';
  watch.onclick=async()=>{const n=Number(prompt('Gewünschte Stückzahl:','10'));if(!Number.isSafeInteger(n)||n<1||n>1000000)return;
    try{await api(path('wishlist/'+item.stock_id),{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify({quantity:n})});notice('Wunschmenge gespeichert.');}catch(e){notice(e.message,true);}};
  controls.append(caption,addBtn,el('button','secondary button-sm cart-jump','Warenkorb ansehen →'),el('p','muted detail-disclaimer','Ein Warenkorb ist zunächst ein Entwurf. Keine automatische Reservierung.'),fav,watch);
  if(manager()){
    const setMin=el('button','secondary button-sm',T('minStock'));setMin.type='button';setMin.onclick=()=>{$('restockStockId').value=item.stock_id;$('restockMin').value='0';$('restockTarget').value='0';setTab('procurement');};controls.append(setMin);
  }
  controls.querySelector('.cart-jump').onclick=()=>setTab('cart');
  root.append(controls);
}
function renderItems(){
  const root=$('inventoryItems');root.replaceChildren();$('itemPage').textContent=T('page')+' '+state.itemPage;$('prevItems').disabled=state.itemPage===1;$('nextItems').disabled=!state.hasNextItems;
  const confirmed=state.items.filter(x=>x.available_qty!==null);
  $('stockSummary').replaceChildren(add(el('div','stat'),el('strong','',qty(state.items.length)),el('span','','Einträge auf Seite '+state.itemPage)),
    add(el('div','stat'),el('strong','',qty(confirmed.length)),el('span','','Mit bestätigter Verfügbarkeit')),
    add(el('div','stat'),el('strong','','Serverbestand'),el('span','','Verbindliche Mengen nur nach Prüfung')));
  renderItemTypeCategories();renderInventoryFilters();const filtered=filteredInventory();
  if(!filtered.find(x=>Number(x.stock_id)===Number(state.selectedStockId)))state.selectedStockId=filtered.length?Number(filtered[0].stock_id):null;
  $('visibleItemCount').textContent=qty(filtered.length)+' Treffer auf dieser Seite';
  const dates=state.items.map(x=>x.observed_at).filter(Boolean).sort();
  const newest=dates.length?new Date(dates[dates.length-1].replace(' ','T')+'Z'):null;
  const displayDate=newest&&!Number.isNaN(newest.getTime())?newest.toLocaleString(webLocale()==='deDE'?'de-DE':'en-US'):null;
  $('stockFreshness').textContent=displayDate?('Letzte Inventur: '+displayDate+' · Serverbestand, keine Live-Bankabfrage'):'Serverbestand · Kein bestätigter Inventurzeitpunkt';
  if(!filtered.length){root.append(el('p','muted catalog-empty',state.items.length?'Keine passenden Positionen für diesen Filter auf der aktuellen Seite.':'Noch kein bestätigter Bestand oder keine passenden Gegenstände.'));renderItemDetail();return;}
  for(const item of filtered){
    const card=el('button','catalog-item'+(Number(item.stock_id)===Number(state.selectedStockId)?' selected':''));card.type='button';
    card.setAttribute('aria-pressed',String(Number(item.stock_id)===Number(state.selectedStockId)));
    /* Native browser title causes a second, overlapping tooltip. Use our accessible tooltip only. */
    const icon=makeItemIcon(item);const name=el('span','catalog-item-name'+qualityClass(stockVisual(item)||{quality:item.icon_quality}),stockVisual(item)?.name||item.name);name.dataset.stockQuality=String(item.stock_id);name.dataset.stockLocalizedName=String(item.stock_id);
    card.append(icon,name,el('span',item.available_qty===null?'catalog-item-qty unconfirmed':'catalog-item-qty',
      item.available_qty===null?T('stockUnverified'):qty(item.available_qty)+' Stk.'));
    card.onclick=()=>{hideTooltip();state.selectedStockId=Number(item.stock_id);root.querySelectorAll('.catalog-item').forEach(x=>{const selected=x===card;x.classList.toggle('selected',selected);x.setAttribute('aria-pressed',String(selected));});renderItemDetail();};root.append(card);
    observeAppearance(icon,item);tooltipBindings(card,item);
  }
  renderItemDetail();
}
function renderCart(){
  const root=$('cartItems');root.replaceChildren();if(state.cart.size===0){root.append(el('p','muted','Dein Warenkorb ist leer. Im Bankbestand kannst du Gegenstände auswählen.'));$('checkoutForm').classList.add('hidden');return;}
  $('checkoutForm').classList.remove('hidden');
  for(const [id,item] of state.cart){
    const card=el('article','cart-card');card.append(add(el('div'),el('div','item-title',item.name),el('div','muted','Bestellte Stückzahl: '+qty(item.quantity))));
    const actions=el('div','actions');const amount=el('input');amount.type='number';amount.min='1';amount.max=String(item.max);amount.value=String(item.quantity);amount.setAttribute('aria-label','Bestellmenge für '+item.name);
    amount.onchange=()=>{const number=Number(amount.value);if(!Number.isSafeInteger(number)||number<1||number>item.max){amount.value=String(item.quantity);notice('Ungültige Menge.',true);return;}item.quantity=number;state.pendingOrder=null;updateCartBadge();};
    const remove=el('button','secondary button-sm','Entfernen');remove.onclick=()=>{state.cart.delete(id);state.pendingOrder=null;updateCartBadge();renderCart();};add(actions,amount,remove);card.append(actions);root.append(card);
  }
}
async function submitOrder(event){
  event.preventDefault();if(!state.cart.size)return;
  const button=$('submitOrder');button.disabled=true;
  const lines=[...state.cart.values()].map(v=>({stock_id:v.id,quantity:v.quantity}));
  const request={lines,note:$('orderNote').value};const fingerprint=JSON.stringify(request);
  if(!state.pendingOrder||state.pendingOrder.fingerprint!==fingerprint||state.pendingOrder.guild!==Number(state.guild.id))
    state.pendingOrder={key:freshId(),fingerprint,guild:Number(state.guild.id)};
  try{const result=await post(path('orders'),request,{'Idempotency-Key':state.pendingOrder.key});
    state.cart.clear();state.pendingOrder=null;updateCartBadge();$('orderNote').value='';notice('Bestellung #'+result.id+' wurde vom Server bestätigt.');setTab('orders');}
  catch(e){notice('Bestellung nicht bestätigt: '+e.message+'. Warenkorb bleibt erhalten.',true);}
  finally{button.disabled=false;}
}
async function loadOrders(){if(!state.guild)return;const data=await api(path('orders'));state.orders=data.orders;renderOrders();}
const ORDER_STATUS={submitted:['Eingereicht','wait'],partial:['Teilweise genehmigt','ok'],reserved:['Genehmigt – liegt bereit','ok'],partially_fulfilled:['Teilweise ausgegeben','ok'],fulfilled:['Ausgegeben','done'],rejected:['Abgelehnt','bad'],cancelled:['Storniert','bad']};
function statusBadge(status){const [text,kind]=ORDER_STATUS[status]||[status,'wait'];return el('span','badge badge-'+kind,text);}
function when(utc){const d=new Date(String(utc).replace(' ','T')+'Z');if(Number.isNaN(d.getTime()))return String(utc);const loc=webLocale()==='enUS'?'en-US':'de-DE';
  const time=d.toLocaleTimeString(loc,{hour:'2-digit',minute:'2-digit'});const day=new Date();day.setHours(0,0,0,0);const diff=Math.round((day-new Date(d.getFullYear(),d.getMonth(),d.getDate()))/86400000);
  const en=webLocale()==='enUS';return diff===0?(en?'today ':'heute ')+time:diff===1?(en?'yesterday ':'gestern ')+time:d.toLocaleDateString(loc,{day:'2-digit',month:'2-digit'})+' '+time;}
function orderProgress(status){const step={submitted:0,partial:1,reserved:1,partially_fulfilled:1,fulfilled:2}[status];const bar=el('ol','progress');
  if(step===undefined)bar.classList.add('progress-stopped');
  ['Eingereicht','Genehmigt','Ausgegeben'].forEach((label,n)=>bar.append(el('li',step!==undefined&&n<=step?'done':'',label)));return bar;}
function orderGroup(status){return status==='submitted'?'review':['reserved','partial','partially_fulfilled'].includes(status)?'ready':'done';}
function orderCard(order,adminMode){
  const card=el('article','order-card2');const head=el('div','order-head');const title=el('div');
  title.append(el('div','item-title',(adminMode&&order.display_name?order.display_name+' · ':'')+'Bestellung #'+order.id),el('div','muted',when(order.created_at)+(order.note?' · „'+order.note+'“':'')));
  head.append(title,statusBadge(order.status));card.append(head);
  if(order.summary)card.append(el('p','order-summary',order.summary));
  card.append(orderProgress(order.status));
  const group=orderGroup(order.status);const box=el('div','order-detail hidden');
  const toggle=el('button',adminMode&&group!=='done'?'':'secondary button-sm',adminMode&&group==='review'?'Prüfen & genehmigen':adminMode&&group==='ready'?'Ausgabe bestätigen':'Details');
  toggle.type='button';toggle.onclick=()=>{if(!box.classList.contains('hidden')){box.classList.add('hidden');return;}box.classList.remove('hidden');showOrder(Number(order.id),adminMode,box).catch(e=>notice(e.message,true));};
  card.append(toggle,box);return card;
}
function renderOrders(){
  const root=$('orderList');root.replaceChildren();const admin=$('adminOrders');admin.replaceChildren();
  const mine=state.orders.filter(o=>Number(o.user_id)===Number(state.user));
  if(!mine.length)root.append(el('p','empty-state','Noch keine Bestellungen. Lege in der Gildenbank Gegenstände in den Warenkorb und sende sie ab.'));
  for(const order of mine)root.append(orderCard(order,false));
  if(!administrator())return;
  const filter=state.adminFilter||'review';const counts={review:0,ready:0,done:0};
  for(const order of state.orders){const g=orderGroup(order.status);counts[g]++;if(g===filter)admin.append(orderCard(order,true));}
  document.querySelectorAll('#adminFilter [data-count]').forEach(c=>c.textContent=String(counts[c.dataset.count]));
  const badge=$('openOrderCount');badge.textContent=String(counts.review+counts.ready);badge.classList.toggle('hidden',!(counts.review+counts.ready));
  if(!admin.children.length)admin.append(el('p','empty-state',{review:'Nichts zu prüfen.',ready:'Keine Aufträge, die auf Ausgabe warten.',done:'Noch keine erledigten Aufträge.'}[filter]));
}
async function showOrder(id,adminMode,target){
  const response=await api(path('orders/'+id));const box=target;if(!box)return;box.replaceChildren();
  if(response.order.note)box.append(el('p','muted','Nachricht: '+response.order.note));
  const lines=el('div','stack');for(const line of response.lines){
    lines.append(add(el('div','row'),el('div','item-title',line.name),el('div','muted',`Angefordert: ${qty(line.requested_qty)} · Genehmigt: ${qty(line.approved_qty)} · Ausgegeben: ${qty(line.fulfilled_qty)}${line.decision==='rejected'?' · abgelehnt':''}`)));
  }box.append(lines);
  if(adminMode&&administrator()&&response.order.status==='submitted'){
    const form=el('form','panel');form.append(el('h3','','Genehmigen oder ablehnen'));
    for(const line of response.lines){const label=el('label','',line.name+' · maximal '+line.requested_qty);const input=el('input');input.type='number';input.min='0';input.max=String(line.requested_qty);input.value=String(line.requested_qty);input.dataset.lineId=line.id;label.append(input);form.append(label);}
    const button=el('button','','Entscheidungen verbindlich speichern');form.append(button);
    form.onsubmit=async e=>{e.preventDefault();const decisions={};for(const input of form.querySelectorAll('[data-line-id]')){const v=Number(input.value);if(!Number.isSafeInteger(v)||v<0||v>Number(input.max)){notice('Ungültige Freigabemenge.',true);return;}decisions[input.dataset.lineId]=v;}
      button.disabled=true;try{const r=await post(path('orders/'+id+'/decide'),{decisions});notice('Gespeichert: '+(ORDER_STATUS[r.status]||[r.status])[0]);await loadOrders();}catch(err){notice(err.message,true);}finally{button.disabled=false;}};
    box.append(form);
  }
  if(adminMode&&manager()&&['reserved','partial','partially_fulfilled'].includes(response.order.status)){
    const form=el('form','panel');form.append(el('h3','','Physische Ausgabe bestätigen'));
    const remaining=response.lines.filter(l=>l.decision==='approved'&&Number(l.approved_qty)>Number(l.fulfilled_qty));
    for(const line of remaining){const row=el('div','split');row.append(el('strong','',line.name+' ('+(Number(line.approved_qty)-Number(line.fulfilled_qty))+' offen)'));
      const qtyInput=el('input');qtyInput.type='number';qtyInput.min='1';qtyInput.max=String(Number(line.approved_qty)-Number(line.fulfilled_qty));qtyInput.value=qtyInput.max;
      const send=el('button','secondary button-sm','Diese Ausgabe bestätigen');send.type='button';send.onclick=async()=>{
        if(!window.confirm('Nur bestätigen, wenn diese Menge im Spiel tatsächlich ausgegeben wurde.'))return;
        const q=Number(qtyInput.value);if(!Number.isSafeInteger(q)||q<1||q>Number(qtyInput.max)){notice('Ungültige Teilmenge.',true);return;}
        send.disabled=true;try{const r=await post(path('orders/'+id+'/fulfill'),{line_id:Number(line.id),quantity:q},{'Idempotency-Key':freshId()});notice('Ausgabe gebucht: '+(ORDER_STATUS[r.status]||[r.status])[0]);await loadOrders();}catch(e){notice(e.message,true);}finally{send.disabled=false;}
      };row.append(qtyInput,send);form.append(row);}
    box.append(form);
  }
  if(['submitted','reserved','partial','partially_fulfilled'].includes(response.order.status) && (Number(response.order.user_id)===Number(state.user)||administrator())) {
    const cancel=el('button','danger button-sm','Bestellung / Restmenge stornieren');cancel.onclick=async()=>{if(!confirm('Bestellung wirklich stornieren? Bereits ausgegebene Mengen bleiben gebucht.'))return;try{await post(path('orders/'+id+'/cancel'),{});notice('Bestellung storniert.');await loadOrders();}catch(err){notice(err.message,true);}};box.append(cancel);
  }
  box.classList.remove('hidden');box.scrollIntoView({behavior:'smooth',block:'nearest'});
}

async function loadNotifications(){
  if(!state.guild)return;
  const response=await api(path('notifications'));
  const root=$('notificationsList');root.replaceChildren();
  const unread=response.notifications.filter(x=>!x.read_at);
  $('notificationCount').textContent=String(unread.length);
  if(!response.notifications.length)root.append(el('p','muted','Noch keine Mitteilungen.'));
  const labels={'order.submitted':'Neue Bestellung eingereicht','order.decided':'Deine Bestellung wurde bearbeitet','order.fulfilled':'Gegenstände wurden als ausgegeben gebucht','order.cancelled':'Bestellung storniert'};
  for(const n of response.notifications){
    const card=el('article','order-card');card.append(add(el('div'),el('strong','',labels[n.kind]||n.kind),el('div','muted',`Auftrag #${n.order_id} · ${n.created_at} UTC${n.read_at?' · gelesen':''}`)));
    if(!n.read_at){const mark=el('button','secondary button-sm','Gelesen');mark.onclick=async()=>{await post(path('notifications/'+n.id+'/read'),{});await loadNotifications();};card.append(mark);}
    root.append(card);
  }
}

async function loadImports(){
  const r=await api(path('imports'));const root=$('importList');root.replaceChildren();
  if(!r.imports.length){root.append(el('p','muted','Noch keine Scans importiert.'));return;}
  for(const scan of r.imports){
    const card=el('div','row');card.append(el('strong','','#'+scan.id+' · '+scan.status+' · '+scan.quality),el('div','muted','Scanzeit: '+scan.observed_at+' UTC'));
    const view=el('button','secondary button-sm','Positionen prüfen');view.onclick=()=>reviewImport(scan.id).catch(e=>notice(e.message,true));card.append(view);root.append(card);
  }
}
let scanPreviewObserver=null;
async function reviewImport(id,page=1){
  // The icon preview is deliberately independent of approval or stock changes.
  if(scanPreviewObserver){scanPreviewObserver.disconnect();scanPreviewObserver=null;}
  const result=await api(path('imports/'+id+'/review')+'?locale='+encodeURIComponent(webLocale())+'&page='+page);
  const root=$('importReview');root.replaceChildren();root.classList.remove('hidden');
  root.append(el('h3','','Scan #'+id+' · '+result.import.status));
  const cover=result.icon_coverage;
  if(cover)root.append(el('p','muted',`Icon-Vorschau (${result.icon_locale||'deDE'}, Seite ${page}): ${cover.icon_available} mit Iconnamen · ${cover.file_id_only} nur Grafik-ID · ${cover.missing} ohne Icondaten. Fehlende Icons werden für sichtbare Zeilen optional über die öffentliche MoP-Gegenstandsdatenbank ergänzt; Bestände ändern sich dabei nicht.`));
  const missingIcons=[];
  const pendingIcons=[];
  let previewIconLoads=0;
  const pumpPreviewIcons=()=>{
    while(previewIconLoads<2&&pendingIcons.length){
      const job=pendingIcons.shift();previewIconLoads++;
      fallbackIconFor(job.id).then(url=>{
        if(!url || !job.box.isConnected)return;
        const img=el('img');img.src=url;img.alt='';img.loading='lazy';img.referrerPolicy='no-referrer';
        img.onerror=()=>{img.remove();job.box.textContent='?';job.box.title='Externe Grafik nicht erreichbar';};
        job.box.replaceChildren(img);
        job.box.title='Externes MoP-Icon nach Gegenstands-ID (nicht von Blizzard bestätigt)';
      }).finally(()=>{previewIconLoads--;pumpPreviewIcons();});
    }
  };
  for(const item of result.items){
    const line=el('div','row bl-scan-preview-row');
    const iconBox=el('span','bl-scan-preview-icon');
    if(item.preview_icon){
      const icon=el('img');icon.src=item.preview_icon;icon.alt='';icon.loading='lazy';icon.referrerPolicy='no-referrer';
      icon.onerror=()=>{icon.remove();iconBox.textContent='?';iconBox.title='Iconbild konnte nicht geladen werden';};
      iconBox.append(icon);
    }else{
      iconBox.textContent='?';
      iconBox.title=item.icon_status==='file_id_only'?'Grafik-ID aus WoW vorhanden; Iconname fehlt':'Keine Icondaten vom Client erfasst';
      missingIcons.push({box:iconBox,id:item.wow_item_id});
    }
    line.append(iconBox,el('div','item-title',`${item.item_name} · ${qty(item.scanned_qty)} Stück`),
      el('div','muted',`Serverbestand: ${item.known_qty===null?'unbekannt':qty(item.known_qty)} · ${item.confidence||'nicht erfasst'}`));
    root.append(line);
  }
  // Lazy-load only icons the user scrolls into view: no hundred-request burst.
  if(typeof IntersectionObserver==='function' && missingIcons.length){
    const jobs=new Map(missingIcons.map(job=>[job.box,job]));
    scanPreviewObserver=new IntersectionObserver(entries=>{
      for(const entry of entries){
        if(!entry.isIntersecting)continue;
        scanPreviewObserver?.unobserve(entry.target);
        const job=jobs.get(entry.target);
        if(job){pendingIcons.push(job);jobs.delete(entry.target);}
      }
      pumpPreviewIcons();
    },{rootMargin:'120px'});
    for(const job of missingIcons)scanPreviewObserver.observe(job.box);
  }else if(missingIcons.length){
    // Browsers without IntersectionObserver still get icons at a low request rate.
    pendingIcons.push(...missingIcons);pumpPreviewIcons();
  }
  if(page>1||result.has_next){
    const nav=el('div','split bl-scan-pages');
    if(page>1){const prev=el('button','secondary button-sm','Vorherige 100');prev.onclick=()=>reviewImport(id,page-1).catch(e=>notice(e.message,true));nav.append(prev);}
    nav.append(el('span','muted',`Scan-Positionen · Seite ${page} (je 100)`));
    if(result.has_next){const next=el('button','secondary button-sm','Nächste 100');next.onclick=()=>reviewImport(id,page+1).catch(e=>notice(e.message,true));nav.append(next);}
    root.append(nav);
  }
  const preflight=result.preflight||{};
  if(result.import.status==='pending'){
    const explanations={
      MOVEMENTS_AFTER_SCAN:'Nach dem Scan wurde auf der Website eine Ausgabe oder Korrektur gebucht. Dieser Scan zeigt noch den Stand davor und würde die Buchung doppelt zählen.',
      SNAPSHOT_TOO_OLD:'Dieser Scan ist älter als 12 Stunden (automatische Freigabe: 30 Minuten) oder die PC-Uhrzeit war falsch.',
      SCAN_OLDER_THAN_STOCK:'Der Bestand wurde bereits mit einem neueren Scan abgeglichen. Ein älterer Scan darf ihn nicht zurücksetzen.',
      RESERVATION_EXCEEDS_SCAN:'Laut Scan liegen weniger Gegenstände in der Bank, als für genehmigte Bestellungen reserviert sind. Bitte offene Bestellungen prüfen.',
      SNAPSHOT_NOT_LATEST:'Es wurde bereits eine neuere Inventur hochgeladen. Nur die neueste Inventur darf bestätigt werden.',
      SNAPSHOT_NOT_PENDING:'Diese Inventur ist nicht mehr zur Bestätigung zugelassen.',
      LEGACY_SCAN_REFRESH_REQUIRED:'Diese Inventur wurde vor der Korrektur des Erfassungszeitfensters hochgeladen. Bitte einen neuen vollständigen Scan übertragen und nur den neuen Eintrag prüfen.'
    };
    if(!preflight.can_confirm){
      const warning=el('div','danger-note',explanations[preflight.reason]||'Diese Inventur kann momentan nicht bestätigt werden. Bitte eine aktuelle Inventur durchführen.');
      if(preflight.conflict){
        const c=preflight.conflict;
        warning.append(el('p','',`Letzte kollidierende Buchung: ${({fulfill:'Ausgabe',manual_adjust:'manuelle Korrektur',scan_reconcile:'früherer Inventurabgleich'})[c.event_type]||c.event_type} · ${c.created_at} UTC · Buchung #${c.ledger_id}.`));
        if(c.clock_anomaly)warning.append(el('p','','Auffälligkeit: Diese Buchungszeit liegt mehr als fünf Minuten vor der Serverzeit in der Zukunft. Eine ältere Datenbank-Zeitzonenkonfiguration ist möglich. Keinen Zeitstempel manuell korrigieren; zunächst Datenbank-/Ledgerzeiten prüfen.'));
      }
      warning.append(el('p','','Die Buchungssperre bleibt aktiv. Bitte die Gildenbank nach dieser Buchung vollständig neu scannen, /reload ausführen und den neuen Scan übertragen. Der Scan muss nach der letzten Buchung beginnen und innerhalb von 12 Stunden bestätigt werden.'));
      const retry=el('button','secondary button-sm','Scanprüfung aktualisieren');
      retry.onclick=()=>reviewImport(id,page).catch(e=>notice(e.message,true));
      warning.append(retry);root.append(warning);
    }else{
      root.append(el('p','muted','Vorprüfung bestanden. Vor der tatsächlichen Bestätigung wird der Bestand unter Datenbanksperre erneut geprüft.'));
    }
  }
  if(result.import.status==='pending'&&preflight.can_confirm){
    const caution=el('p','danger-note','Nur bestätigen, nachdem die Gegenstände und Mengen im Spiel geprüft wurden. Ein falscher Scan kann andere Bestände überschreiben; Reservierungskonflikte werden serverseitig blockiert. Bestätigung nur bis 30 Minuten nach Scan.');
    const form=el('form','panel');
    const confirmationLabel=el('label','','Erneut mit Website-Passwort bestätigen');
    const password=el('input');password.type='password';password.autocomplete='current-password';password.required=true;
    confirmationLabel.append(password);
    const checkboxLabel=el('label','split');
    const checkbox=el('input');checkbox.type='checkbox';checkbox.style.width='auto';checkbox.required=true;
    checkboxLabel.append(checkbox,el('span','','Ich habe die Inventur persönlich überprüft und bestätige ihre Vollständigkeit.'));
    const btn=el('button','danger','Bestand ausdrücklich abgleichen');btn.type='submit';
    form.append(caution,confirmationLabel,checkboxLabel,btn);
    form.onsubmit=async event=>{event.preventDefault();
      if(!checkbox.checked||!password.value)return;
      btn.disabled=true;try{const response=await post(path('imports/'+id+'/confirm'),{confirmation:'BANKSTAND_MANUELL_GEPRUEFT',password:password.value});
        notice('Bestand serverseitig abgeglichen: '+response.reconciliation.changed_stocks+' Änderungen.');password.value='';await loadImports();await loadItems();}
      catch(e){notice('Bestandsabgleich abgelehnt: '+e.message,true);}finally{btn.disabled=false;password.value='';}
    };
    root.append(form);
  }
}
async function loadScanAutomation(){
 const root=$('autoScanControls'),out=$('autoScanStatus');if(!root||!manager())return;
 const cfg=await api(path('scan-automation'));
 out.textContent=cfg.enabled?'Automatische Bestandsverarbeitung ist aktiviert (nur mit eigenem Auto-Token).':'Automatische Bestandsverarbeitung ist derzeit deaktiviert.';
 root.replaceChildren();
 if(state.guild.role!=='admin')return;
 const info=el('p','muted',cfg.enabled?'Deaktivieren widerruft ALLE Auto-Tokens der Gilde.':'Einmalige Einrichtung: Administratorpasswort, bestätigte Einwilligung und separaten Auto-Token für deinen Companion verwenden.');
 const form=el('form','stack compact');const label=el('label','','Gerätename / Tokenbezeichnung');
 const device=el('input');device.value='Gildenbank-PC';device.maxLength=80;label.append(device);
 const pwLabel=el('label','','Administratorkennwort (nur zur erneuten Bestätigung)');const pw=el('input');pw.type='password';pw.required=true;pw.autocomplete='current-password';pwLabel.append(pw);
 const agreeLabel=el('label','');const agree=el('input');agree.type='checkbox';agree.required=true;agree.style.width='auto';agreeLabel.append(agree,document.createTextNode(' Ich akzeptiere, dass ein Vollscan ohne einzelne manuelle Freigabe Bestandsbuchungen auslösen kann. Konflikte bleiben gesperrt.'));
 const btn=el('button',cfg.enabled?'danger':'','');btn.type='submit';btn.textContent=cfg.enabled?'Automatik deaktivieren und Auto-Tokens widerrufen':'Automatik aktivieren & eigenen Companion-Schlüssel erzeugen';
 form.append(info,...(cfg.enabled?[]:[label]),pwLabel,agreeLabel,btn);
 form.onsubmit=async e=>{e.preventDefault();if(!agree.checked||!pw.value)return;
  btn.disabled=true;
  try{const r=await post(path('scan-automation'),{enabled:!cfg.enabled,password:pw.value,label:device.value,confirmation:'AUTOMATISCHER_BANKABGLEICH'});
   pw.value='';const credential=r.automation?.credential;
   if(credential)showConnectCode($('tokenResult'),credential.token,'Neuer Verbindungscode mit automatischer Freigabe – im Companion des Bankverwalters einfügen (ersetzt den bisherigen):');
   notice(cfg.enabled?'Automatik deaktiviert.':'Automatik aktiviert. Auto-Token im Companion speichern.');await loadScanAutomation();await loadTokens();
  }catch(err){notice('Automatikkonfiguration fehlgeschlagen: '+err.message,true);}finally{pw.value='';btn.disabled=false;}
 };
 root.append(form);
}
async function loadTokens(){
  const result=await api(path('bridge-tokens'));const root=$('tokenList');root.replaceChildren();
  for(const t of result.tokens){const row=el('div','row');row.append(el('strong','',t.label),el('div','muted',`#${t.id} · ${t.scope} · gültig bis ${t.expires_at} UTC · ${t.revoked_at?'widerrufen':'aktiv'}`));
    if(!t.revoked_at&&state.guild.role==='admin'){const btn=el('button','danger button-sm','Widerrufen');btn.onclick=async()=>{if(!confirm('Diesen Bridge-Schlüssel sofort sperren?'))return;await api(path('bridge-tokens/'+t.id),{method:'DELETE'});await loadTokens();};row.append(btn);}root.append(row);}
}
async function createToken(){
  const label=window.prompt('Name des Windows-Geräts für diesen Bridge-Schlüssel:','Bankverwalter-PC');if(!label)return;
  const result=await post(path('bridge-tokens'),{label});const data=result.credential;showConnectCode($('tokenResult'),data.token,'Verbindungscode für den Companion des Bankverwalters (gilt 90 Tage, lädt Scans zur Prüfung hoch):');
  await loadTokens();
}
function connectCode(token){const json=JSON.stringify({s:location.origin,g:Number(state.guild.id),t:token});return 'BL1C:'+btoa(json).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');}
function showConnectCode(root,token,intro){root.replaceChildren();root.classList.remove('hidden');
  const code=connectCode(token);const box=el('input');box.readOnly=true;box.value=code;box.onclick=()=>box.select();
  const copy=el('button','button-sm','Code kopieren');copy.type='button';copy.onclick=async()=>{try{await navigator.clipboard.writeText(code);copy.textContent='Kopiert ✓';}catch(_e){box.select();}};
  root.append(el('strong','',intro),el('p','muted','Im Companion in das Feld „Verbindungscode“ einfügen und speichern – fertig. Der Code ist nur jetzt sichtbar.'),box,copy);}
async function loadOrderTokens(){
  const result=await api(path('bridge-tokens'));const root=$('orderTokenList');root.replaceChildren();
  for(const t of result.tokens.filter(x=>x.scope==='member.orders'&&!x.revoked_at)){
    const row=el('div','row');row.append(el('strong','',t.label),el('div','muted',`gültig bis ${t.expires_at} UTC · zuletzt benutzt ${t.last_used_at?t.last_used_at+' UTC':'noch nie'}`));
    const btn=el('button','danger button-sm','Sperren');
    btn.onclick=async()=>{if(!confirm('Diesen Schlüssel sofort sperren? Der Companion kann danach keine Bestellungen mehr übertragen.'))return;
      await api(path('bridge-tokens/'+t.id),{method:'DELETE'});await loadOrderTokens();};
    row.append(btn);root.append(row);
  }
}
async function createOrderToken(){
  const label=window.prompt('Name dieses PCs (nur zur Wiedererkennung):','Mein PC');if(!label)return;
  const result=await post(path('bridge-tokens'),{label,scope:'member.orders'});const data=result.credential;
  showConnectCode($('orderTokenResult'),data.token,'Dein persönlicher Verbindungscode (gilt 180 Tage, nicht weitergeben):');
  await loadOrderTokens();
}
const UPSTREAM_ERR={UPSTREAM_UNREACHABLE:'Update-Server nicht erreichbar',UPSTREAM_HASH_MISMATCH:'Download beschädigt (Prüfsumme falsch)',MANIFEST_SIGNATURE_INVALID:'Signatur ungültig – Update abgelehnt',MANIFEST_INVALID:'Update-Beschreibung ungültig',SODIUM_MISSING:'PHP-Erweiterung sodium fehlt',UPSTREAM_TOO_LARGE:'Download zu groß'};
async function loadUpstream(){
  let box=$('upstreamBox');if(!box){box=el('div','card');box.id='upstreamBox';$('deviceList').parentElement.before(box);}
  box.replaceChildren(el('h3','','Website-Updates'));
  let st;try{st=await api(path('upstream-update'));}catch(e){box.append(el('p','muted','Status nicht verfügbar.'));return;}
  if(st.publisher){box.append(el('p','muted',`Diese Website ist der offizielle Update-Server (Version ${st.installed}). Neue Versionen kommen per Veröffentlichung vom Entwickler-Rechner.`));return;}
  box.append(el('p','muted',`Installiert: ${st.installed}${st.latest?' · neueste offizielle Version: '+st.latest:''} · Automatische Prüfung alle 6 Stunden: ${st.auto?'an':'aus'}`));
  if(st.checked_at)box.append(el('p',st.error?'error':'muted',st.error?`Letzte Prüfung ${when(st.checked_at)}: ${UPSTREAM_ERR[st.error]||st.error}`:`Letzte Prüfung ${when(st.checked_at)}: ${st.result==='installed'?'Version '+st.installed_now+' eingespielt (mit Backup)':st.result==='running'?'läuft gerade':'aktuell'}`));
  const b=el('button','button-sm','Jetzt nach Updates suchen');b.onclick=async()=>{b.disabled=true;b.textContent='Prüfe …';try{const r=await post(path('upstream-update'),{});if(r.result==='installed'){alert('Version '+r.installed_now+' wurde eingespielt. Die Seite lädt neu.');location.reload();return;}}finally{await loadUpstream();}};
  box.append(b);
}
async function loadDemoSetting(){
  let box=$('demoSettingBox');if(!box){box=el('div','card');box.id='demoSettingBox';$('deviceList').parentElement.before(box);}
  box.replaceChildren(el('h3','','Öffentliche Demo'));
  let st;try{st=await api(path('demo-setting'));}catch(e){box.append(el('p','muted','Nicht verfügbar.'));return;}
  box.append(el('p','muted','Besucher ohne Konto können sich Banklord mit einer eigenen Beispiel-Gilde ansehen. Deine echten Gildendaten sind dort nicht sichtbar, und Änderungen sind gesperrt.'));
  const b=el('button','button-sm',st.enabled?'Demo ausschalten':'Demo einschalten');
  b.onclick=async()=>{b.disabled=true;try{await post(path('demo-setting'),{enabled:!st.enabled});notice(st.enabled?'Demo ausgeschaltet.':'Demo eingeschaltet. Der Knopf erscheint auf der Anmeldeseite.');}catch(e){notice(e.message,true);}await loadDemoSetting();};
  box.append(el('p','',st.enabled?'Status: eingeschaltet':'Status: ausgeschaltet'),b);
}
async function loadReleases(){
  loadDemoSetting();
  loadUpstream();
  const r=await api(path('release-tokens'));const list=$('releaseTokenList');list.replaceChildren();
  for(const t of r.tokens.filter(x=>!x.revoked_at)){const row=el('div','row');row.append(el('strong','',t.label),el('div','muted',`zuletzt benutzt ${t.last_used_at?t.last_used_at+' UTC':'noch nie'}`));
    const b=el('button','danger button-sm','Sperren');b.onclick=async()=>{if(!confirm('Veröffentlichungs-Schlüssel sperren?'))return;await api(path('release-tokens/'+t.id),{method:'DELETE'});await loadReleases();};row.append(b);list.append(row);}
  const rel=$('releaseList');rel.replaceChildren();
  for(const p of r.publications)rel.append(el('div','row muted',`${p.published_at} UTC · Web ${p.web_version}${Number(p.web_applied)?' (eingespielt)':''} · Addon ${p.addon_version} · Companion ${p.companion_version} · Updater ${p.updater_version}`));
  if(!r.publications.length)rel.append(el('div','muted','Noch keine automatische Veröffentlichung.'));
  const cur=r.current&&r.current.versions||null;const dev=$('deviceList');dev.replaceChildren();
  if(cur)dev.append(el('p','muted',`Aktuell: Addon ${cur.addon} · Companion ${cur.companion} · Update-Dienst ${cur.updater}`));
  for(const d of r.devices){const stale=(Date.now()-new Date(String(d.last_seen).replace(' ','T')+'Z').getTime())>3*86400000;
    const ok=cur&&d.addon_version===cur.addon&&d.companion_version===cur.companion&&(!d.updater_version||d.updater_version===cur.updater);
    const row=el('div','row device-row');row.append(add(el('div'),el('strong','',d.device+' · '+d.display_name),el('div','muted',`Addon ${d.addon_version||'?'} · Companion ${d.companion_version||'?'} · Update-Dienst ${d.updater_version||'?'} · zuletzt ${when(d.last_seen)}`)),
      el('span','badge '+(ok?'badge-ok':stale?'badge-done':'badge-wait'),ok?'Aktuell':stale?'Länger offline':'Update ausstehend'));dev.append(row);}
  if(!r.devices.length)dev.append(el('p','empty-state','Noch kein PC hat sich gemeldet. Companions ab Version 0.5.0 melden sich automatisch.'));
}
async function createReleaseToken(){
  const label=window.prompt('Name des Geräts, das veröffentlichen darf:','Entwickler-Mac');if(!label)return;
  const r=await post(path('release-tokens'),{label});const root=$('releaseTokenResult');root.replaceChildren();root.classList.remove('hidden');
  root.append(el('strong','','Nur jetzt sichtbar. Auf dem Mac in die Datei ~/.banklord/publish-token speichern.'),el('p','',r.credential.token),
    el('p','muted','Der Schlüssel erlaubt nur das Hochladen. Veröffentlicht wird ausschließlich, was mit dem Release-Schlüssel signiert ist.'));
  await loadReleases();
}
async function importFile(event){
  event.preventDefault();const f=$('scanFile').files[0];if(!f||f.name!=='Banklord.lua'||f.size>4_194_304){notice('Banklord.lua mit maximal 4 MiB auswählen.',true);return;}
  const data=new FormData();data.set('savedvars',f);
  try{const result=await api(path('imports'),{method:'POST',body:data});notice('Scan #'+result.import.id+' als unbestätigte Inventur vorgemerkt.');await loadImports();}catch(e){notice('Import fehlgeschlagen: '+e.message,true);}
}
document.querySelectorAll('.tabs button').forEach(b=>b.addEventListener('click',()=>setTab(b.dataset.tab)));
$('bellBtn').onclick=()=>setTab('notifications');
$('refreshAdminOrders').onclick=()=>loadOrders().catch(e=>notice(e.message,true));
document.querySelectorAll('#activityFilter .chip').forEach(c=>c.onclick=()=>{state.activityFilter=c.dataset.filter;document.querySelectorAll('#activityFilter .chip').forEach(x=>x.classList.toggle('active',x===c));renderActivity();});
$('refreshActivity').onclick=()=>loadActivity().catch(e=>notice(e.message,true));
document.querySelectorAll('#adminFilter .chip').forEach(c=>c.onclick=()=>{state.adminFilter=c.dataset.filter;document.querySelectorAll('#adminFilter .chip').forEach(x=>x.classList.toggle('active',x===c));renderOrders();});
$('loginForm').addEventListener('submit',async e=>{e.preventDefault();try{const r=await post('/login',{email:$('email').value,password:$('password').value});state.csrf=r.csrf;$('password').value='';await initialize();notice('Erfolgreich angemeldet.');}catch(err){notice('Anmeldung fehlgeschlagen: '+err.message,true);}});
$('logoutBtn').addEventListener('click',async()=>{try{await post('/logout',{});state.cart.clear();state.details.clear();state.guild=null;await initialize();notice('Abgemeldet.');}catch(e){notice(e.message,true);}});
$('guildSelector').addEventListener('change',switchGuild);
let searchTimer;$('searchInput').addEventListener('input',()=>{state.itemPage=1;state.categoryKey='all';state.inventoryFilter='all';clearTimeout(searchTimer);searchTimer=setTimeout(()=>loadItems().catch(e=>notice(e.message,true)),280);});
$('checkoutForm').addEventListener('submit',submitOrder);
$('prevItems').onclick=()=>{if(state.itemPage>1){state.itemPage--;loadItems().catch(e=>notice(e.message,true));}};
$('nextItems').onclick=()=>{if(state.hasNextItems){state.itemPage++;loadItems().catch(e=>notice(e.message,true));}};
$('clearInventorySearch').onclick=()=>{$('searchInput').value='';state.itemPage=1;state.categoryKey='all';state.inventoryFilter='all';loadItems().catch(e=>notice(e.message,true));$('searchInput').focus();};
$('refreshOrders').onclick=()=>loadOrders().catch(e=>notice(e.message,true));
$('refreshNotifications').onclick=()=>loadNotifications().catch(e=>notice(e.message,true));
$('importForm').addEventListener('submit',importFile);
$('createToken').onclick=()=>createToken().catch(e=>notice(e.message,true));
$('createOrderToken').onclick=()=>createOrderToken().catch(e=>notice(e.message,true));
$('createReleaseToken').onclick=()=>createReleaseToken().catch(e=>notice(e.message,true));
// Light polling only while tab is visible, maximum once per 20 seconds. No open socket required.
setInterval(()=>{if(!state.guild||document.hidden)return;
  if(state.tab==='orders'||state.tab==='admin')loadOrders().catch(()=>{});
  if(state.tab==='inventory')loadItems().catch(()=>{});
  if(state.tab==='notifications')loadNotifications().catch(()=>{});
},20000);

// Member functions: server-validated, guild-scoped; no HTML from users is injected.
async function loadPersonal(){
  if(!state.guild)return;
  const [fav,wish,tmp]=await Promise.all([api(path('favorites')),api(path('wishlist')),api(path('templates'))]);
  state.favorites=new Set(fav.favorites.map(x=>Number(x.stock_id)));
  state.wishlist=wish.wishlist;state.templates=tmp.templates;
  const r=$('favoriteList');r.replaceChildren();
  for(const item of fav.favorites){const row=el('div','row');row.append(el('strong','',item.name));const b=el('button','secondary button-sm','Entfernen');b.onclick=async()=>{await api(path('favorites/'+item.stock_id),{method:'DELETE'});await loadPersonal();renderItems();};row.append(b);r.append(row);}if(!r.children.length)r.append(el('p','muted','Noch keine Favoriten.'));
  const w=$('wishlistList');w.replaceChildren();
  for(const item of wish.wishlist){const row=el('div','row');row.append(el('strong','',item.name+' · '+item.desired_qty+' Stück'));const b=el('button','secondary button-sm','Entfernen');b.onclick=async()=>{await api(path('wishlist/'+item.stock_id),{method:'DELETE'});await loadPersonal();};row.append(b);w.append(row);}if(!w.children.length)w.append(el('p','muted','Keine Wunschmengen.'));
  const t=$('templateList');t.replaceChildren();for(const item of tmp.templates){const row=el('div','row');row.append(el('strong','',item.title));const use=el('button','','In Warenkorb');use.onclick=async()=>{
    let added=0;for(const line of item.lines){let match;try{const result=await api(path('items/'+line.stock_id));match=result.item;}catch(err){notice('Vorlagenitem #'+line.stock_id+' nicht mehr vorhanden.',true);continue;}
      state.cart.set(Number(line.stock_id),{id:Number(line.stock_id),name:match.name,quantity:line.quantity,max:1000000});added++;}
    state.pendingOrder=null;
    updateCartBadge();notice(added+' Positionen übernommen. Verfügbarkeit beim Absenden erneut geprüft.');setTab('cart');};
    const del=el('button','secondary button-sm','Löschen');del.onclick=async()=>{await api(path('templates/'+item.id),{method:'DELETE'});await loadPersonal();};row.append(use,del);t.append(row);}
  if(!t.children.length)t.append(el('p','muted','Noch keine Vorlagen.'));
}
$('refreshFavorites').onclick=()=>loadPersonal().catch(e=>notice(e.message,true));
$('saveTemplate').onclick=async()=>{if(!state.cart.size)return notice('Warenkorb ist leer.',true);const title=$('templateName').value.trim();try{await post(path('templates'),{title,lines:[...state.cart.values()].map(x=>({stock_id:x.id,quantity:x.quantity}))});$('templateName').value='';await loadPersonal();notice('Bestellvorlage gespeichert.');}catch(e){notice(e.message,true);}};
async function loadCharacters(){const result=await api(path('characters'));const r=$('characterList');r.replaceChildren();for(const c of result.characters)r.append(add(el('div','row'),el('strong','',c.name+' – '+c.realm),el('span','tag',c.verification_status)));if(!r.children.length)r.append(el('p','muted','Noch kein Charakter zugeordnet.'));}
$('characterForm').onsubmit=async e=>{e.preventDefault();try{await post(path('characters'),{name:$('characterName').value,realm:state.guild.realm});$('characterName').value='';await loadCharacters();notice('Charakter eingetragen – Verifizierung ausstehend.');}catch(err){notice(err.message,true);}};
const ROLE_INFO={member:'kann Bestand ansehen und bestellen',officer:'kann zusätzlich alle Aufträge sehen und genehmigen',bank_manager:'kann zusätzlich Inventur, Ausgabe und Nachkauf verwalten',admin:'darf alles, auch Mitglieder und System'};
async function loadMembers(){
  if(state.guild.role!=='admin')return;
  const [m,c]=await Promise.all([api(path('members')),api(path('characters'))]);
  const names=new Map(m.members.map(x=>[Number(x.user_id),x.display_name]));
  const open=c.characters.filter(ch=>ch.verification_status==='unverified');
  const todo=$('characterAdminList');todo.replaceChildren();$('memberTodo').classList.toggle('hidden',!open.length);
  for(const ch of open){const row=el('div','row member-todo');
    row.append(el('span','',`${names.get(Number(ch.user_id))||'Ein Mitglied'} möchte den Charakter „${ch.name}“ zuordnen.`));
    for(const [label,status,cls] of [['Bestätigen','verified',''],['Ablehnen','revoked','danger']]){const b=el('button','button-sm '+cls,label);b.type='button';
      b.onclick=async()=>{try{await post(path('characters/'+ch.id+'/review'),{status});await loadMembers();notice(status==='verified'?'Charakter bestätigt.':'Charakter abgelehnt.');}catch(e){notice(e.message,true);}};row.append(b);}
    todo.append(row);}
  const chars=new Map();for(const ch of c.characters){if(ch.verification_status==='revoked')continue;const k=Number(ch.user_id);if(!chars.has(k))chars.set(k,[]);chars.get(k).push(ch.name+(ch.verification_status==='verified'?' ✓':' (ungeprüft)'));}
  const r=$('memberList');r.replaceChildren();
  for(const member of m.members){const row=el('div','row member-row');
    const info=add(el('div'),el('strong','',member.display_name),el('div','muted',member.email+(chars.has(Number(member.user_id))?' · '+chars.get(Number(member.user_id)).join(', '):'')));
    const sel=el('select');for(const key of ['member','officer','bank_manager','admin']){const o=el('option','',roleText(key));o.value=key;o.selected=key===member.role;sel.append(o);}
    const status=el('select');for(const [key,label] of [['active','Aktiv'],['disabled','Gesperrt']]){const o=el('option','',label);o.value=key;o.selected=key===member.status;status.append(o);}
    const save=el('button','button-sm','Speichern');save.type='button';save.disabled=true;
    const self=Number(member.user_id)===Number(state.user);if(self){sel.disabled=true;status.disabled=true;save.title='Eigene Rechte kann man nicht selbst ändern.';}
    const changed=()=>{save.disabled=self||(sel.value===member.role&&status.value===member.status);};sel.onchange=changed;status.onchange=changed;
    save.onclick=async()=>{if(status.value==='disabled'&&!confirm(member.display_name+' sperren? Das Konto kann sich danach nicht mehr anmelden und der Companion wird abgewiesen.'))return;
      try{await api(path('members/'+member.user_id),{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({role:sel.value,status:status.value})});await loadMembers();notice('Gespeichert.');}catch(err){notice(err.message,true);}};
    row.append(info,sel,status,save);r.append(row);}
}
$('inviteRole').onchange=()=>{$('inviteRoleInfo').textContent=roleText($('inviteRole').value)+' '+ROLE_INFO[$('inviteRole').value]+'.';};
$('issueInvite').onclick=async()=>{try{const r=await post(path('invitations'),{role:$('inviteRole').value});const out=$('inviteResult');out.classList.remove('hidden');
  const msg=`Hallo! Du bist zur Gildenbank von ${state.guild.name} eingeladen. Öffne ${location.origin}, klicke auf „Einladung erhalten? Konto erstellen“ und gib diesen Code ein: ${r.invitation.token} (48 Stunden gültig).`;
  const box=el('textarea');box.rows=3;box.readOnly=true;box.value=msg;const copy=el('button','button-sm','Nachricht kopieren');copy.type='button';
  copy.onclick=async()=>{try{await navigator.clipboard.writeText(msg);copy.textContent='Kopiert ✓';}catch(_e){box.select();}};
  out.replaceChildren(el('strong','','Fertige Nachricht – z. B. per Discord-Privatnachricht schicken:'),box,copy);}catch(e){notice(e.message,true);}};
const ACTIVITY={
  'order.create':['order','📜',e=>`${e.actor||'Jemand'} hat Bestellung #${e.entity_id} aufgegeben`],
  'order.decide':['order','✔️',e=>`${e.actor||'Ein Verwalter'} hat Bestellung #${e.entity_id}${e.owner?' von '+e.owner:''} geprüft`],
  'order.fulfill':['order','📦',e=>`${e.actor||'Ein Verwalter'} hat Gegenstände aus Bestellung #${e.entity_id}${e.owner?' an '+e.owner:''} ausgegeben`],
  'order.cancel':['order','✖️',e=>`${e.actor||'Jemand'} hat Bestellung #${e.entity_id}${e.owner&&e.owner!==e.actor?' von '+e.owner:''} storniert`],
  'scan.staged':['scan','🔍',e=>`Neuer Bankscan #${e.entity_id} hochgeladen${e.actor?' von '+e.actor:''}`],
  'scan.manually_confirmed':['scan','✅',e=>`${e.actor||'Ein Verwalter'} hat Bankscan #${e.entity_id} freigegeben${changes(e)}`],
  'scan.automatically_reconciled':['scan','⚙️',e=>`Bankscan #${e.entity_id} automatisch übernommen${changes(e)}`],
  'scan.retention_pruned':['scan','🧹',()=>'Alte Bankscans aufgeräumt (die letzten 5 bleiben)'],
  'scan.automation.enabled':['scan','⚙️',e=>`${e.actor||'Admin'} hat die automatische Scan-Freigabe eingeschaltet`],
  'scan.automation.disabled':['scan','⚙️',e=>`${e.actor||'Admin'} hat die automatische Scan-Freigabe ausgeschaltet`],
  'peer.drafts.staged':['order','✉️',()=>'Bestellentwürfe aus dem Spiel eingegangen'],
  'bridge.token.created':['people','🔑',e=>`${e.actor||'Jemand'} hat einen Companion-Verbindungscode erzeugt`],
  'bridge.token.revoked':['people','🔒',e=>`${e.actor||'Jemand'} hat einen Companion-Verbindungscode gesperrt`],
  'character.review':['people','🛡️',e=>`${e.actor||'Ein Verwalter'} hat einen Charakter geprüft`],
  'membership.change':['people','👥',e=>`${e.actor||'Ein Admin'} hat die Rechte eines Mitglieds geändert`],
  'restock.configure':['scan','📈',e=>`${e.actor||'Ein Verwalter'} hat einen Mindestbestand festgelegt`],
};
function changes(e){try{const d=JSON.parse(e.details_json||'{}');return Number.isInteger(d.changed_stocks)?` (${d.changed_stocks} Mengen geändert)`:'';}catch(_e){return '';}}
function dayLabel(utc){const d=new Date(String(utc).replace(' ','T')+'Z');const t=new Date();t.setHours(0,0,0,0);const diff=Math.round((t-new Date(d.getFullYear(),d.getMonth(),d.getDate()))/86400000);
  return diff===0?'Heute':diff===1?'Gestern':d.toLocaleDateString(webLocale()==='enUS'?'en-US':'de-DE',{weekday:'long',day:'2-digit',month:'2-digit'});}
function ledgerText(x){const n=Number(x.physical_delta),r=Number(x.reserved_delta);const parts=[];
  if(x.event_type==='fulfill')parts.push(`${Math.abs(n)} ausgegeben`);
  else if(x.event_type==='scan_reconcile')parts.push(`${n>0?'+':'−'}${Math.abs(n)} laut Bankscan`);
  else if(x.event_type==='manual_adjust')parts.push(`${n>0?'+':'−'}${Math.abs(n)} von Hand korrigiert`);
  if(x.event_type==='reserve')parts.push(`${Math.abs(r)} für eine Bestellung reserviert`);
  if(x.event_type==='release')parts.push(`${Math.abs(r)} Reservierung aufgehoben`);
  return `${x.name}: ${parts.join(', ')||x.event_type}`;}
let activityCache=[];
function renderActivity(){
  const filter=state.activityFilter||'all';const list=$('activityList');list.replaceChildren();let lastDay='';
  for(const e of activityCache){const [group,icon,text]=ACTIVITY[e.action]||['other','•',()=>e.action];if(filter!=='all'&&group!==filter)continue;
    const day=dayLabel(e.created_at);if(day!==lastDay){list.append(el('h4','timeline-day',day));lastDay=day;}
    const row=el('div','timeline-row');row.append(el('span','timeline-icon',icon),el('span','timeline-text',text(e)),el('span','timeline-time',when(e.created_at).replace(/^(heute|gestern) /,'')));list.append(row);}
  if(!list.children.length)list.append(el('p','empty-state','Für diesen Filter gibt es noch keine Einträge.'));
}
async function loadActivity(){
  const [dashboard,activity]=await Promise.all([api(path('dashboard')),api(path('activity'))]);
  const count=s=>dashboard.orders.filter(g=>s.includes(g.status)).reduce((a,g)=>a+Number(g.total),0);
  const root=$('dashboardSummary');root.replaceChildren();
  for(const [value,label] of [[dashboard.stock.items,'Gegenstandsarten'],[qty(dashboard.stock.physical),'Stück auf Lager'],[qty(dashboard.stock.reserved),'davon reserviert'],
    [count(['submitted']),'Bestellungen zu prüfen'],[count(['reserved','partial','partially_fulfilled']),'warten auf Ausgabe'],[count(['fulfilled']),'erledigt']])
    root.append(add(el('div','stat'),el('strong','',String(value)),el('span','',label)));
  activityCache=activity.events;renderActivity();
  const ledger=$('stockLedger');ledger.replaceChildren();
  if(manager()){const journal=await api(path('ledger'));
    for(const x of journal.entries)ledger.append(add(el('div','row'),el('span','',ledgerText(x)),el('span','muted',` · ${x.actor_name||'System'} · ${when(x.created_at)}`)));
    if(!ledger.children.length)ledger.append(el('p','muted','Noch keine Bestandsbewegungen.'));
  }else ledger.append(el('p','muted','Nur für Bankverwalter sichtbar.'));
}
$('registerForm').onsubmit=async e=>{e.preventDefault();try{const r=await post('/register',{invitation:$('registerCode').value,email:$('registerEmail').value,display_name:$('registerName').value,password:$('registerPass').value});state.csrf=r.csrf;$('registerPass').value='';await initialize();notice('Konto eingerichtet.');}catch(err){notice('Registrierung: '+err.message,true);}};
// Text-only addon cart transfer, never authoritative. Matches exact WoW item variation.
function parseAddonTransfer(input){
  if(input.length>20000||!input.startsWith('BL1|'))throw Error('Übertragungscode ungültig');
  const chunks=input.slice(4).split('|');if(chunks.length<1||chunks.length>50)throw Error('Zu viele Positionen');
  const output=[];for(const chunk of chunks){const match=chunk.match(/^(\d{1,10}),(\d{1,7}),(item:[0-9:\-]{1,150})$/);if(!match)throw Error('Fehlerhafter Code');const id=Number(match[1]),quantity=Number(match[2]);if(!Number.isSafeInteger(id)||!Number.isSafeInteger(quantity)||quantity<1||quantity>1000000)throw Error('Ungültige Mengen');output.push({id,quantity,variant:match[3]});}return output;
}
$('importAddonCart').onclick=async()=>{try{const transfer=parseAddonTransfer($('addonTransfer').value.trim());let count=0;for(const part of transfer){const resp=await api(path('items')+'?q='+part.id);const matches=resp.items.filter(x=>Number(x.wow_item_id)===part.id&&x.variant_key===part.variant);if(matches.length!==1)throw Error('Item/Variante '+part.id+' ist auf dem Server nicht eindeutig vorhanden');const item=matches[0];/* Unverified requests remain pending approval. */state.cart.set(Number(item.stock_id),{id:Number(item.stock_id),name:item.name,quantity:part.quantity,max:1000000});state.pendingOrder=null;count++;}updateCartBadge();renderCart();notice(count+' Positionen in den Web-Warenkorb übernommen. Bitte vor Bestellung prüfen.');}catch(err){notice(err.message,true);}};

initialize().then(()=>{if(manager())loadTokens().catch(()=>{});}).catch(err=>notice('Server derzeit nicht bereit: '+err.message,true));

// Extended bank management: manual worklists only; every mutation server-authorized.
let restockOptions=new Map();
async function loadProcurement(){
  if(!manager())return;
  const [restock,manifest]=await Promise.all([api(path('procurement/rules')),api(path('fulfillment/manifest'))]);
  restockOptions=new Map(restock.options.map(o=>[o.name,o]));const dl=$('restockOptions');dl.replaceChildren();
  for(const o of restock.options){const opt=el('option');opt.value=o.name;dl.append(opt);}
  const rules=[...restock.rules].sort((a,b)=>({shortage:0,unverified:1,ok:2}[a.status]-{shortage:0,unverified:1,ok:2}[b.status])||a.name.localeCompare(b.name));
  const short=rules.filter(x=>x.status==='shortage');
  const sum=$('restockSummary');sum.replaceChildren();
  for(const [v,l] of [[short.length,'Gegenstände fehlen'],[qty(short.reduce((a,x)=>a+Number(x.missing_qty||0),0)),'Stück nachkaufen'],[rules.length-short.length,'ausreichend oder unklar']])sum.append(add(el('div','stat'),el('strong','',String(v)),el('span','',l)));
  const r=$('restockList');r.replaceChildren();
  for(const rule of rules){
    const avail=Number(rule.physical_qty)-Number(rule.reserved_qty);
    const kind=rule.status==='shortage'?['badge-bad',`${qty(rule.missing_qty)} nachkaufen`]:rule.status==='ok'?['badge-ok','Genug da']:['badge-wait','Noch nicht gezählt'];
    const line=el('div','row restock-row');
    line.append(add(el('div'),el('strong','',rule.name),el('div','muted',`${qty(avail)} verfügbar${Number(rule.reserved_qty)?' ('+qty(rule.reserved_qty)+' reserviert)':''} · mindestens ${qty(rule.minimum_qty)}, auffüllen auf ${qty(rule.target_qty)}${rule.observed_at?' · Stand '+when(rule.observed_at):''}`)),el('span','badge '+kind[0],kind[1]));
    const edit=el('button','secondary button-sm','Ändern');edit.type='button';
    edit.onclick=()=>{$('restockItem').value=rule.name;$('restockStockId').value=rule.stock_id;$('restockMin').value=rule.minimum_qty;$('restockTarget').value=rule.target_qty;$('restockItem').scrollIntoView({behavior:'smooth',block:'center'});$('restockMin').focus();};
    line.append(edit);r.append(line);
  }
  if(!r.children.length)r.append(el('p','empty-state','Noch kein Mindestbestand festgelegt. Unten einen Gegenstand auswählen und Mengen eintragen.'));
  const f=$('fulfillmentManifest');f.replaceChildren();const byMember=new Map();
  for(const item of manifest.manifest){if(!byMember.has(item.display_name))byMember.set(item.display_name,[]);byMember.get(item.display_name).push(item);}
  for(const [name,items] of byMember)f.append(add(el('div','row'),el('strong','',name+': '),el('span','',items.map(i=>`${qty(i.to_issue)}× ${i.name} (#${i.order_id})`).join(' · '))));
  if(!f.children.length)f.append(el('p','muted','Gerade ist nichts zu übergeben.'));
}
$('restockItem').addEventListener('input',()=>{const o=restockOptions.get($('restockItem').value);$('restockStockId').value=o?o.id:'';});
$('restockForm').onsubmit=async e=>{
  e.preventDefault();
  const stock=Number($('restockStockId').value),minimum=Number($('restockMin').value),target=Number($('restockTarget').value);
  if(!stock){notice('Bitte einen Gegenstand aus der Vorschlagsliste wählen.',true);return;}
  if(!Number.isSafeInteger(stock)||stock<1||!Number.isSafeInteger(minimum)||minimum<0||!Number.isSafeInteger(target)||target<minimum||target>1000000){notice('Mindestbestand und Ziel prüfen.',true);return;}
  try{await api(path('procurement/rules/'+stock),{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify({minimum,target})});await loadProcurement();notice('Mindestbestand gespeichert.');}
  catch(err){notice(err.message,true);}
};
$('downloadProcurement').onclick=async()=>{
  try{
    const r=await fetch('/api/v1'+path('procurement/export.csv'),{credentials:'same-origin',cache:'no-store'});
    if(!r.ok)throw Error('CSV-Ausgabe: '+r.status);
    const blob=await r.blob(),url=URL.createObjectURL(blob),a=document.createElement('a');
    a.href=url;a.download='bl-nachbeschaffung.csv';document.body.append(a);a.click();a.remove();URL.revokeObjectURL(url);
  }catch(err){notice(err.message,true);}
};

// Addon whispers are NEVER authoritative. Admin sees only unverified drafts.
async function loadPeerDrafts(){
 if(!manager())return;
 const data=await api(path('peer-drafts'));
 const root=$('peerDraftList');root.replaceChildren();
 if(!data.drafts.length){root.append(el('p','muted','Noch keine übertragenen Ingame-Entwürfe.'));return;}
 for(const row of data.drafts){
  const box=el('div','panel');
  box.append(el('strong','',row.sender+' · '+row.received_at+' · ungeprüfter Entwurf'));
  const area=el('textarea');area.value=row.transfer_code;area.readOnly=true;area.rows=2;area.setAttribute('aria-label','Nicht bestätigter Warenkorb-Transfercode');
  box.append(area,el('p','muted','Nicht als Bestellauftrag freigegeben. Absender nur nach separater Anmeldung/Prüfung zuordnen.'));
  root.append(box);
 }
}
$('refreshPeerDrafts').onclick=()=>loadPeerDrafts().catch(e=>notice(e.message,true));

// Changing UI locale invalidates all item visuals because tooltips are locale-keyed.
document.addEventListener('banklord:language-changed',()=>{
 state.appearances.clear();state.appearanceRequests.clear();appearanceLoading.queue=[];appearanceLoading.queued.clear();
 hideTooltip();if(state.guild)renderItems();
});
document.addEventListener('DOMContentLoaded',()=>{
 const s=$('languagePicker');if(!s)return;
 s.value=webLocale();s.addEventListener('change',()=>window.BLLang?.set(s.value));
});

// Display only informational release metadata, never make authorization decisions
// or install unverified files from JavaScript. Real signature validation belongs
// to the separate Windows Updater. Web upgrades remain admin+password protected.
document.getElementById('checkUpdateFeed')?.addEventListener('click',async()=>{
 const target=$('updateFeedStatus');target.textContent='Updatekanal wird abgefragt …';
 try{
  const response=await fetch('/updates/preview/manifest.json',{credentials:'omit',cache:'no-store'});
  if(!response.ok)throw Error('HTTP '+response.status);
  const data=await response.json();
  if(typeof data?.payload!=='string'||typeof data?.signature!=='string'||data.payload.length>20000)throw Error('Ungültiges Veröffentlichungsformat');
  const payload=JSON.parse(atob(data.payload));
  const safe=v=>typeof v==='string'&&v.length<=90?v:'unbekannt';
  target.textContent='Kanal: '+safe(payload.channel)+'\nClient: '+safe(payload.client)+'\nAddon: '+safe(payload.addon?.version)+'\nCompanion: '+safe(payload.companion?.version)+'\nManifest gefunden – kryptografische Prüfung erfolgt ausschließlich im Windows-Updater.';
 }catch(e){target.textContent='Updatekanal nicht abrufbar: '+e.message;}
});

// Browser stock view is a server snapshot, not live bank data. Refresh while
// visible so new auto-reconciled scans appear without requiring F5.
setInterval(()=>{
 if(document.visibilityState!=='visible'||!state.guild)return;
 if(state.tab==='inventory')loadItems().catch(()=>{});
 else if(state.tab==='sync')loadImports().catch(()=>{});
},45000);

$('demoBtn').onclick=async()=>{const b=$('demoBtn');b.disabled=true;try{await post('/demo/start',{});location.reload();}catch(e){notice(e.message,true);b.disabled=false;}};
$('demoEndBtn').onclick=async()=>{try{await post('/logout',{});}finally{location.reload();}};

(()=>{const ua=navigator.userAgent||'',a=$('downloadMain');if(!a)return;
  if(/Mac OS X|Macintosh/.test(ua)){a.href='/api/v1/download/setup?os=mac-arm64';a.textContent='⬇ Banklord für macOS herunterladen';}
  else if(/Linux/.test(ua)&&!/Android/.test(ua)){a.href='/api/v1/download/setup?os=linux-x64';a.textContent='⬇ Banklord für Linux herunterladen';}})();
