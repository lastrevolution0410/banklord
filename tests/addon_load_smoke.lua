-- In-memory WoW API mock for load/init regression, not a game-client test.
local frames={}
local methods={}
local meta;meta={__index=function(self,key)
 if key=='GetFrameLevel' then return function()return 1 end end
 if key=='GetWidth' then return function()return self.width or 1200 end end
 if key=='GetHeight' then return function()return self.height or 900 end end
 if key=='GetEffectiveScale' then return function()return 1 end end
 if key=='GetCenter' then return function()return 0,0 end end
 if key=='GetValue' then return function()return self.value or 0 end end
 if key=='GetMinMaxValues' then return function()return 0,self.maximum or 0 end end
 if key=='IsShown' then return function()return self.shown~=false end end
 if key=='IsVisible' then return function()return self.shown~=false end end
 if key=='GetText' then return function()return self.text or '' end end
 if key=='GetStringWidth' then return function()return #tostring(self.text or '')*7 end end
 if key=='GetThumbTexture' then return function()return rawget(self,'thumb') end end
 if key=='GetHighlightTexture' then return function()return rawget(self,'highlight') end end
 if key=='CreateFontString' or key=='CreateTexture' then return function() return setmetatable({},meta) end end
 if key=='SetScript' then return function(_,name,callback)local scripts=rawget(self,'scripts') or {};rawset(self,'scripts',scripts);scripts[name]=callback end end
 if key=='SetText' then return function(_,value) self.text=value end end
 if key=='SetSize' then return function(_,w,h)self.width=w;self.height=h end end
 if key=='SetHeight' then return function(_,h)self.height=h end end
 if key=='SetWidth' then return function(_,w)self.width=w end end
 if key=='GetChecked' then return function() return self.checked==true end end
 if key=='SetChecked' then return function(_,v) self.checked=(v==true) end end
 if key=='SetValue' then return function(_,v)self.value=v end end
 if key=='SetMinMaxValues' then return function(_,min,max)self.maximum=max end end
 if key=='Hide' then return function()self.shown=false end end
 if key=='Show' then return function()self.shown=true;local scripts=rawget(self,'scripts');if scripts and scripts.OnShow then scripts.OnShow(self) end end end
 if key=='GetNumChildren' then return function()return 0 end end
 return function() end
end}
function CreateFrame(_,name,parent) local t=setmetatable({shown=true},meta);if name then frames[name]=t;_G[name]=t end;return t end
UIParent=setmetatable({width=1400,height=1000},meta)
Minimap=setmetatable({},meta)
DEFAULT_CHAT_FRAME=setmetatable({},meta)
GameTooltip=setmetatable({},meta)
UISpecialFrames={};SlashCmdList={};_G=_G
function GetGuildInfo()return 'Testgilde' end
function UnitName()return 'Tester' end
function GetRealmName()return 'Everlook' end
function GetItemInfo()return nil end
function GetItemIcon()return 134400 end
function time()return os.time() end
function date(...) return os.date(...) end
function GetCursorPosition()return 0,0 end
function GetLocale()return 'deDE' end
function GetBuildInfo()return '5.5.4','70531' end
function GetGuildBankTabInfo()return 'Tab',nil,true end
function GetNumGuildBankTabs()return 8 end
function GetCurrentGuildBankTab()return 1 end
function GetGuildBankItemInfo()return nil,nil end
function GetGuildBankItemLink()return nil end
C_Timer={After=function()end}
local root=arg[1] or '.'
-- Load exactly what the client loads: the file list of the .toc.
local modules={}
for line in io.lines(root..'/Banklord.toc') do
  if line:match('^[%w_]+%.lua%s*$') then modules[#modules+1]=line:match('^([%w_]+%.lua)') end
end
assert(#modules>=10,'toc module list incomplete')
for _,name in ipairs(modules) do
  local f,err=loadfile(root..'/'..name);assert(f,err)
  local ok,message=pcall(f);assert(ok,name..': '..tostring(message))
end
assert(type(Banklord.ToggleUI)=='function','UI init incomplete')
local A=Banklord
local initEvent=A.frame.scripts.OnEvent
assert(initEvent,'core OnEvent missing')
initEvent(A.frame,'ADDON_LOADED','Banklord')
assert(A.db,'savedvars init incomplete')
local btn=assert(frames.BanklordMinimapButton)
assert(btn.scripts.OnClick,'minimap click missing')
local window=assert(frames.BanklordWindow)
assert(window.shown==false,'Window should initially be hidden')
local ok,err=pcall(SlashCmdList.BANKLORD,'open');assert(ok,err)
assert(window.shown==true,'/banklord open must show window')
local ok2,err2=pcall(btn.scripts.OnClick,btn,'LeftButton');assert(ok2,err2)
assert(window.shown==false,'minimap click must close window')
print('PASS: all addon modules load, slash command opens, minimap click toggles')
-- An enchant scroll may have a generic GetItemInfo texture which differs
-- from the exact gild-bank tile. Capture must prefer the actual scanned slot.
GetItemInfo=function(link)
  return 'Armschiene - Werte',link,2,65,1,'Verzauberkunst','Verzauberung','', '', 135000,nil,0,0
end
local observed=A:CaptureItemMetadata({itemID=38938,itemKey='item:38938:0:0',
  itemLink='|cff1eff00|Hitem:38938:0:0|h[Armschiene - Werte]|h|r',iconTexture=444777})
assert(observed and observed.iconFileID==444777,
  'Bank-slot enchant icon must win over generic GetItemInfo texture')
print('PASS: captured bank-slot icon overrides generic enchant artwork')
-- The option must stay a visual SavedVariable; it must not affect bank scans.
local background=assert(frames.BanklordBackgroundCheckbox,'missing advanced settings checkbox')
assert(background.scripts.OnClick,'background checkbox has no click handler')
background:SetChecked(true)
local ok3,err3=pcall(background.scripts.OnClick,background);assert(ok3,err3)
assert(A.db.settings.solidBackground==true,'checkbox should persist opaque preference')
A:RefreshUI();assert(background:GetChecked()==true,'background preference lost after refresh')
background:SetChecked(false)
local ok4,err4=pcall(background.scripts.OnClick,background);assert(ok4,err4)
assert(A.db.settings.solidBackground==false,'checkbox should persist transparent preference')
print('PASS: advanced checkbox toggles persistent background preference')

-- In-game order: cart -> outbox -> companion answer -> outbox cleared, overview shown.
time=time or os.time;date=date or os.date
A.db.fullScans={{capturedAt=time(),items={{itemID=2589,itemKey='item:2589:0:0:0:0:0:0:0:0',count=20,
  itemLink='|cffffffff|Hitem:2589:0:0:0:0:0:0:0:0|h[Leinenstoff]|h|r'}}}}
A:AddCartDraftByKey('item:2589:0:0:0:0:0:0:0:0',5)
local ok5,err5=pcall(A.OpenCartUI,A);assert(ok5,err5)
local ok6,err6=pcall(SlashCmdList.BANKLORD,'bestellen');assert(ok6,err6)
assert(#A.db.orderOutbox==1,'order must be queued')
local queued=A.db.orderOutbox[1]
assert(queued.clientKey:match('^ig%-%d+%-[a-z0-9]+$') and queued.code=='WGB1|2589,5,item:2589:0:0:0:0:0:0:0:0','outbox entry malformed')
assert(next(A.db.localCartDraft)==nil,'cart must be emptied after submit')
assert(#A:GetOrderOverview()==1,'pending order must be listed')
Banklord_ServerState={generatedAt=time(),orders={{id=42,clientKey=queued.clientKey,status='reserved',createdAt=time(),
  lines={{itemId=2589,name='Leinenstoff',requested=5,approved=5,fulfilled=0,decision='approved'}}}},outbox={}}
A.db.orderOutbox[2]={clientKey='ig-1-zzzzzz',code='WGB1|1,1,item:1:',createdAt=time()}
Banklord_ServerState.outbox['ig-1-zzzzzz']={error='UNKNOWN_ITEM'}
A:ReconcileOrderOutbox()
assert(#A.db.orderOutbox==0,'answered orders must leave the outbox')
assert(#A.db.orderFailures==1 and A.db.orderFailures[1].error=='UNKNOWN_ITEM','failure must be kept for display')
local rows=A:GetOrderOverview()
assert(#rows==2 and rows[2].order and rows[2].order.id==42,'server order must be listed')
local ok7,err7=pcall(A.RefreshUI,A);assert(ok7,err7)
local ok8,err8=pcall(A.ShowOrderTooltip,A,UIParent,rows[2]);assert(ok8,err8)
print('PASS: in-game order outbox, server state reconciliation and overview')
