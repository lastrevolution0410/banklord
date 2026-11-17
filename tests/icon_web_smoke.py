from pathlib import Path
from playwright.sync_api import sync_playwright
import re,json,base64
root=Path('/mnt/data/wow079/backend/public')
html=(root/'app.html').read_text();css=(root/'app.css').read_text();js=(root/'app.js').read_text();locale=(root/'locales.js').read_text()
html=re.sub(r'<meta http-equiv="Content-Security-Policy"[^>]*>', '', html)
html=re.sub(r'<link rel="stylesheet"[^>]+>', '', html)
html=re.sub(r'<script defer src="[^"]+"></script>', '', html)
html=html.replace('</head>','<style>'+css+'</style></head>')
items=[{'stock_id':i+1,'wow_item_id':38938+i,'variant_key':'item:'+str(38938+i),'name':name,'physical_qty':5,'reserved_qty':0,'available_qty':5,'confidence':'verified','icon_url':None,'icon_file_id':136235,'icon_status':'file-id-unresolved','icon_quality':1,'category':'Sonstiges'} for i,name in enumerate(['Armschiene - Geringer Sturmangriff','Armschiene - Muskelkraft','Beulengras'])]
mock='''window.fetch=async function(url,opts){const u=String(url);let v={};
if(u.includes('mop.warcraftdb.com/api/v1/tooltip/item/'))v={icon:'inv_misc_herb_15',name:'Mock'};
else if(u.endsWith('/session'))v={csrf:'test',user_id:1};
else if(u.endsWith('/guilds'))v={guilds:[{id:1,name:'Demo Guild',realm:'Everlook',role:'admin'}]};
else if(u.includes('/items/')&&u.includes('/appearance'))v={appearance:{available:true,icon:null,quality:1,icon_file_id:136235}};
else if(u.includes('/items?'))v={items:TEST_ITEMS,page:1,has_next:false};
else if(u.endsWith('/favorites'))v={favorites:[]};else if(u.endsWith('/wishlist'))v={wishlist:[]};
else if(u.endsWith('/templates'))v={templates:[]};else if(u.endsWith('/notifications'))v={notifications:[]};else if(u.endsWith('/orders'))v={orders:[]};
return {ok:true,status:200,text:async()=>JSON.stringify(v),json:async()=>v};};'''
js=js.replace("if(location.protocol!=='https:'&&!['localhost','127.0.0.1'].includes(location.hostname))throw Error('HTTPS_REQUIRED');",'')
html=html.replace('</body>','<script>const TEST_ITEMS='+json.dumps(items)+';'+mock+'</script><script>'+locale+'</script><script>'+js+'</script></body>')
with sync_playwright() as pw:
    b=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox','--disable-dev-shm-usage'])
    page=b.new_page(viewport={'width':1536,'height':900})
    errs=[]; requests=[];page.on('pageerror',lambda e:errs.append(str(e)));page.on('request',lambda r:requests.append(r.url))
    png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAOaYGQUAAAAASUVORK5CYII=')
    page.route('https://wow.zamimg.com/**',lambda route:route.fulfill(status=200,body=png,content_type='image/png'))
    page.set_content(html,wait_until='domcontentloaded')
    page.wait_for_function("document.querySelectorAll('.catalog-item .wow-item-icon img').length === 3",timeout=15000)
    assert all(page.locator('.catalog-item .wow-item-icon img').evaluate_all('(nodes) => nodes.map(x=>x.complete && x.naturalWidth>0)'))
    assert not any('/medium/136235.jpg' in r for r in requests), 'Invalid numeric CDN URL was requested'
    assert any('inv_misc_herb_15.jpg' in r for r in requests)
    for width in (320,360,390,768,1024,1280,1536,1920):
        page.set_viewport_size({'width':width,'height':900})
        assert page.evaluate('document.documentElement.scrollWidth')<=width, width
    assert page.locator('.filter-disclosure').get_attribute('open') is not None
    assert not errs, errs
    print('PASS: 3 icons from public API response; no numeric CDN URLs; responsive widths; filters visible; no JS exceptions')
    b.close()
