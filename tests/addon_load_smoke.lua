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
 if key=='SetText' then return function(_,value) self.text=value;SEEN_TEXT=SEEN_TEXT or {};SEEN_TEXT[tostring(value)]=true end end
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
local allFrames={}
function CreateFrame(_,name,parent) local t=setmetatable({shown=true},meta);allFrames[#allFrames+1]=t;if name then frames[name]=t;_G[name]=t end;return t end
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
function GetBuildInfo()return '5.5.4','70531','Jul 1 2026',50504 end
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
assert(queued.clientKey:match('^ig%-%d+%-[a-z0-9]+$') and queued.code=='BL1|2589,5,item:2589:0:0:0:0:0:0:0:0','outbox entry malformed')
assert(next(A.db.localCartDraft)==nil,'cart must be emptied after submit')
assert(queued.urgent==nil,'normal order is not urgent')
-- "/bl bestellen dringend" marks the order urgent; an empty cart leaves no urgent flag behind.
pcall(SlashCmdList.BANKLORD,'bestellen dringend');assert(#A.db.orderOutbox==1 and not A.orderUrgent,'empty cart: nothing queued, flag reset')
A:AddCartDraftByKey('item:2589:0:0:0:0:0:0:0:0',1)
local oku,erru=pcall(SlashCmdList.BANKLORD,'bestellen dringend');assert(oku,erru)
assert(#A.db.orderOutbox==2 and A.db.orderOutbox[2].urgent==true and not A.orderUrgent,'urgent order queued')
table.remove(A.db.orderOutbox,2)
assert(#A:GetOrderOverview()==1,'pending order must be listed')
Banklord_ServerState={generatedAt=time(),orders={{id=42,clientKey=queued.clientKey,status='reserved',createdAt=time(),
  lines={{itemId=2589,name='Leinenstoff',requested=5,approved=5,fulfilled=0,decision='approved'}}}},outbox={}}
A.db.orderOutbox[2]={clientKey='ig-1-zzzzzz',code='BL1|1,1,item:1:',createdAt=time()}
Banklord_ServerState.outbox['ig-1-zzzzzz']={error='UNKNOWN_ITEM'}
A:ReconcileOrderOutbox()
SEEN_TEXT={};local okr,errr=pcall(A.RefreshUI,A);assert(okr,errr)
assert(SEEN_TEXT['Rechtsklick auf eine Bestellung: stornieren'] or SEEN_TEXT['Right-click an order: cancel'],'visible hint: right-click cancels an open order')
assert(#A.db.orderOutbox==0,'answered orders must leave the outbox')
assert(#A.db.orderFailures==1 and A.db.orderFailures[1].error=='UNKNOWN_ITEM','failure must be kept for display')
local rows=A:GetOrderOverview()
assert(#rows==2 and rows[2].order and rows[2].order.id==42,'server order must be listed')
local ok7,err7=pcall(A.RefreshUI,A);assert(ok7,err7)
local ok8,err8=pcall(A.ShowOrderTooltip,A,UIParent,rows[2]);assert(ok8,err8)
do
  local list=Banklord_ServerState.orders;local n=#list
  list[n+1]={id=43,status='rejected',guild=list[1].guild,realm=list[1].realm,createdAt=time()}
  list[n+2]={id=44,status='fulfilled',guild=list[1].guild,realm=list[1].realm,createdAt=time()}
  local r1,_,hid=A:GetOrderOverview();assert(#r1==2 and hid==2,'finished orders hidden by default: '..#r1..'/'..tostring(hid))
  A.db.showAllOrders=true;local r2,_,hid2=A:GetOrderOverview();assert(#r2==4 and hid2==0,'show all lists finished orders')
  SEEN_TEXT={};local okf,errf=pcall(A.RefreshUI,A);assert(okf,errf)
  A.db.showAllOrders=nil;list[n+1]=nil;list[n+2]=nil
end
print('PASS: in-game order outbox, server state reconciliation and overview')

-- Bank manager: alert, withdraw from the guild bank, prepare and send mail.
do
  local calls={}
  local oldAfter=C_Timer.After;C_Timer.After=function(_,fn) fn() end
  local said,oldAdd={},DEFAULT_CHAT_FRAME.AddMessage
  DEFAULT_CHAT_FRAME.AddMessage=function(_,m) said[#said+1]=m end
  local function lastChat() return said[#said] or '' end
  local oldOpen=A.IsGuildBankOpen
  local bags={[0]={}}
  C_Container={GetContainerNumSlots=function(b) return b==0 and 4 or 0 end,
    GetContainerItemInfo=function(b,s) local i=bags[b] and bags[b][s];return i and {itemID=i[1],stackCount=i[2],isLocked=false} end,
    PickupContainerItem=function(b,s) calls[#calls+1]='pick'..b..s end,
    SplitContainerItem=function(b,s,n) calls[#calls+1]='split'..b..s..'x'..n end}
  local bank={[1]={[3]={2589,20}}}
  GetNumGuildBankTabs=function() return 1 end
  GetGuildBankTabInfo=function() return 'Tab',nil,true end
  GetGuildBankItemLink=function(t,s) local i=bank[t][s];return i and ('|Hitem:'..i[1]..':0|h') end
  GetGuildBankItemInfo=function(t,s) local i=bank[t][s];return nil,i and i[2],false end
  GetCurrentGuildBankTab=function() return 1 end
  SplitGuildBankItem=function(t,s,n) calls[#calls+1]='bsplit'..n;bank[t][s][2]=bank[t][s][2]-n;bags[0][1]={2589,n} end
  AutoStoreGuildBankItem=function() calls[#calls+1]='bstore' end
  A.IsGuildBankOpen=function() return true end
  Banklord_ServerState.manage={{id=7,status='submitted',createdAt=time(),requester='Aria',recipient='Borin-Everlook',
    lines={{itemId=2589,name='Leinenstoff',qty=5}},urgent=true}}
  Banklord_ServerState.manageResults={}
  A.db.manageSeen=nil
  A:ReconcileManage()
  assert(A:IsBankManager() and #A:OpenManageOrders()==1,'manager sees the open order')
  assert(lastChat():find('#7',1,true),'new order alerted: '..lastChat())
  assert(lastChat():find('Dringend',1,true) or lastChat():find('Urgent',1,true),'urgent order alerted as urgent: '..lastChat())
  assert(A:UpdateMinimapBadge()==1 and A.minimapFlash==true,'minimap badge shows 1 and blinks for a new order')
  A:ToggleUI();A:ToggleUI()
  local okb,errb=pcall(A.ShowTab,A,'bank');assert(okb,errb)
  assert(A.minimapFlash==false and A:UpdateMinimapBadge()==1,'opening Banklord stops blinking, count stays')
  A:ShowManagePanel('bank')
  assert(A.manageMode=='bank','panel mode '..tostring(A.manageMode))
  -- Full bags: stop cleanly, remember progress, resume takes only the rest.
  do
    local oldSlots=C_Container.GetContainerNumSlots
    C_Container.GetContainerNumSlots=function() return 0 end
    A.db.manageMine[7]={ts=time(),est=true}
    A:StartWithdraw({Banklord_ServerState.manage[1]})
    assert(A.withdraw==nil and lastChat():find('Taschen voll',1,true),'full bags stop with a clear message: '..lastChat())
    assert(not A.db.manageTaken[7],'order not marked as taken when nothing fit')
    C_Container.GetContainerNumSlots=oldSlots
    A.db.manageProgress={[7]={[2589]=3}}
    local stockNow=A._bankStock()
    assert(A._shortfall({id=7,lines={{itemId=2589,qty=5}}},{[2589]=2})==nil,'shortfall counts what was already withdrawn')
    A.db.manageProgress={};A.db.manageMine[7]=nil;calls={}
  end
  assert(A._shortfall(Banklord_ServerState.manage[1],A._bankStock())==nil,'20 in the bank cover 5')
  assert(A._shortfall({lines={{itemId=2589,name='Leinenstoff',qty=25}}},A._bankStock()):find('5x Leinenstoff',1,true),'shortfall names what is missing')
  local st=A._bankStock()
  assert(A._shortfall({lines={{itemId=2589,qty=15}}},st,true)==nil and A._shortfall({lines={{itemId=2589,qty=10}}},st,true),'withdraw all consumes stock across orders')
  local fired=false;local oldRN=RaidNotice_AddMessage;RaidNotice_AddMessage=function() fired=true end;RaidWarningFrame={}
  A:Alert('x');assert(not fired,'no big screen text');RaidNotice_AddMessage=oldRN;RaidWarningFrame=nil
  A:ManageAll()
  assert(calls[1]=='bsplit5' and calls[2]=='pick01','splits 5 from the bank stack into an empty bag slot: '..table.concat(calls,','))
  assert(A.db.manageTaken[7],'order marked as taken')
  calls={}
  local name,subject,attached='', '',{}
  SendMailNameEditBox={SetText=function(_,v) name=v end};SendMailSubjectEditBox={SetText=function(_,v) subject=v end}
  ClickSendMailItemButton=function(i) attached[#attached+1]=i end
  A._manageEvents.scripts.OnEvent(nil,'MAIL_SHOW')
  A:ManageAll()
  assert(name=='Borin' and subject=='Banklord #7' and #attached==1 and calls[1]=='pick01','mail prepared for same-realm recipient: '..name..' '..subject)
  A.mailSending=7
  A._manageEvents.scripts.OnEvent(nil,'MAIL_SEND_SUCCESS')
  assert(#A.db.manageOutbox==1 and A.db.manageOutbox[1].order==7 and A.db.manageOutbox[1].action=='deliver','handover queued')
  assert(#A:OpenManageOrders()==0,'sent order hidden')
  Banklord_ServerState.manageResults={[A.db.manageOutbox[1].key]={status='fulfilled',error='unknown'}}
  Banklord_ServerState.manage={}
  A:ReconcileManage()
  assert(#A.db.manageOutbox==0,'answered action leaves the outbox')
  A:ReceiveManageMessage('BLNEW1','N|Kaelis-Everlook|2|7','GUILD','Kaelis')
  assert(lastChat():find('Kaelis',1,true),'live alert shown')
  assert(A:UpdateMinimapBadge()==1 and A.minimapFlash==true,'live order counts on the badge and blinks')
  -- Kept over /reload until the loaded state is newer than the announcement.
  A.liveNewOrders=nil;assert(A:LiveNewOrders()==1 and #A.db.liveNotices==1,'live order survives a reload (saved)')
  Banklord_ServerState.generatedAt=time()+5;assert(A:LiveNewOrders()==0,'gone once the loaded state has it')
  do -- one offer to load the new state ~25 s after loading
    local fns,shown={},nil;local oldAfter=C_Timer.After;C_Timer.After=function(_,fn) fns[#fns+1]=fn end
    local oldOffer=A.OfferReload;A.OfferReload=function(_,t) shown=t;return true end
    A.db.liveNotices={{at=time()+60,who='Kaelis'}};A.pickupOffered=nil;A:OfferPickup();A:OfferPickup()
    assert(#fns==1,'offered once per load');local ob,om=A.bankOpen,A.mailOpen;A.bankOpen,A.mailOpen=nil,nil;fns[1]();A.bankOpen,A.mailOpen=ob,om
    assert(shown and (shown:find('1 neue',1,true) or shown:find('1 new',1,true)),'manager is offered to load new orders: '..tostring(shown))
    A.db.liveNotices={};A.OfferReload=oldOffer;C_Timer.After=oldAfter;A.pickupOffered=nil
  end
  A:UpdateMinimapBadge(false)
  local okc,errc=pcall(SlashCmdList.BANKLORD,'bestellungen');assert(okc,errc)
  -- Reject in game: queued for the website, announced to other managers, hidden from the lists.
  Banklord_ServerState.manage={{id=9,status='submitted',createdAt=time(),requester='Aria',recipient='Borin-Everlook',lines={{itemId=2589,name='Leinenstoff',qty=1}}}}
  local sentMsgs={};local oldSend=C_ChatInfo;C_ChatInfo={SendAddonMessage=function(_,m) sentMsgs[#sentMsgs+1]=m end};local oldIG=IsInGuild;IsInGuild=function() return true end
  local oldAfter2=C_Timer.After;C_Timer.After=function(_,f) f() end
  A.db.manageMine[9]={ts=time(),est=true}
  StaticPopupDialogs=nil
  A:RejectOrder(Banklord_ServerState.manage[1])
  local queued=false;for _,r in ipairs(A.db.manageOutbox) do if r.order==9 and r.action=='reject' then queued=true end end
  assert(queued,'reject queued for the website')
  local rsent=false;for _,m in ipairs(sentMsgs) do if m=='R|9' then rsent=true end end
  assert(rsent,'other managers are told: '..table.concat(sentMsgs,' '))
  assert(#A:OpenManageOrders()==0,'rejected order leaves the list')
  A:ReceiveManageMessage('BLNEW1','R|12','GUILD','Kaelis')
  assert(A:OrderBlocked(12)=='abgelehnt','order rejected by another manager is blocked')
  C_ChatInfo=oldSend;IsInGuild=oldIG;C_Timer.After=oldAfter2;A.db.manageOutbox={}
  C_Timer.After=oldAfter;A.IsGuildBankOpen=oldOpen;C_Container=nil;DEFAULT_CHAT_FRAME.AddMessage=oldAdd
  Banklord_ServerState.manage=nil
end
print('PASS: bank manager alert, guild bank withdrawal and mail handover')
do
  IsInGuild=function() return true end
  GetGuildInfo=function() return 'Hausmeister','Rang',1,nil end
  A:RememberCharacter()
  local ch=A.db.characters['Tester-Everlook']
  assert(ch and ch.guild=='Hausmeister' and ch.guildRealm=='Everlook','character remembered with guild')
  Banklord_ServerState.manage={{id=1,guild='Hausmeister',guildRealm='Everlook',recipient='',requester='A',lines={}},
    {id=2,guild='Andere',guildRealm='Everlook',recipient='',requester='B',lines={}}}
  local open=A:OpenManageOrders()
  assert(#open==1 and open[1].id==1,'only orders of the current guild are shown')
  A:QueueManageAction(1,'reject')
  assert(A.db.manageOutbox[#A.db.manageOutbox].guild=='Hausmeister','manager action tagged with guild')
  A.db.manageOutbox={};A.db.manageDone={};Banklord_ServerState.manage=nil;IsInGuild=nil;GetGuildInfo=nil
end
print('PASS: characters and orders are matched to the current guild')
do
  local sent={}
  C_ChatInfo=C_ChatInfo or {}
  local oldSend=C_ChatInfo.SendAddonMessage
  C_ChatInfo.SendAddonMessage=function(p,m,ch,t) sent[#sent+1]={p,m,ch,t} end
  IsInGuild=function() return true end
  GetGuildInfo=function() return 'Hausmeister','Rang',1,nil end
  GetNumGuildMembers=function() return 2 end
  GetGuildRosterInfo=function(i) return ({'Neuling-Everlook','Borin-Everlook'})[i] end
  local key=string.rep('ab',32)
  Banklord_Claim={key=key}
  Banklord_ServerState.connections={}
  assert(A:SendJoinRequest() and sent[#sent][2]=='K|'..key and sent[#sent][3]=='GUILD','member asks on the guild channel')
  -- Manager side: confirms a roster member, ignores strangers.
  Banklord_ServerState.manage={}
  Banklord_ServerState.connections={{server='https://bank.example',guildId=12,guild='Hausmeister',guildRealm='Everlook'}}
  A:ReceiveJoinMessage('BLJOIN1','K|'..key,'GUILD','Fremder-Everlook')
  assert(not (A.db.claims or {})[key],'stranger not confirmed')
  A:ReceiveJoinMessage('BLJOIN1','K|'..key,'GUILD','Neuling-Everlook')
  assert(A.db.claims[key].character=='Neuling-Everlook' and A.db.claims[key].guild=='Hausmeister','roster member confirmed')
  -- Same account, two guilds: manager tools only where the account is a manager.
  do
    local conns=Banklord_ServerState.connections
    Banklord_ServerState.connections={{server='https://bank.example',guildId=12,guild='Hausmeister',guildRealm='Everlook',manager=true},
      {server='https://bank.example',guildId=13,guild='Twinkgilde',guildRealm='Everlook',manager=false}}
    assert(A:IsBankManager(),'manager in Hausmeister')
    local oldInfo=GetGuildInfo;GetGuildInfo=function() return 'Twinkgilde','Rang',5,nil end
    assert(not A:IsBankManager(),'alt in another guild is a normal member')
    local before=A.db.claims[string.rep('cd',32)]
    A:ReceiveJoinMessage('BLJOIN1','K|'..string.rep('cd',32),'GUILD','Neuling-Everlook')
    assert(A.db.claims[string.rep('cd',32)]==before,'no confirmations from a guild where you are not a manager')
    GetGuildInfo=function() return nil end;assert(not A:IsBankManager(),'no guild: no manager tools')
    GetGuildInfo=oldInfo;Banklord_ServerState.connections=conns
  end
  assert(sent[#sent][2]=='S|https://bank.example|12' and sent[#sent][3]=='WHISPER','answer whispered with website address')
  -- Member side: accepts the answer only from a guild member.
  A:ReceiveJoinMessage('BLJOIN1','S|https://evil.example|1','WHISPER','Fremder-Everlook')
  assert(not (A.db.joinOffers or {})['https://evil.example|1'],'offer from outside the guild ignored')
  A:ReceiveJoinMessage('BLJOIN1','S|https://bank.example|12','WHISPER','Borin')
  assert(A.db.joinOffers['https://bank.example|12'].by=='Borin-Everlook','offer stored')
  assert(not A:SendJoinRequest(),'no request once connected to this guild')
  C_ChatInfo.SendAddonMessage=oldSend;IsInGuild=nil;GetGuildInfo=nil;GetNumGuildMembers=nil;GetGuildRosterInfo=nil
  Banklord_ServerState.manage=nil;Banklord_ServerState.connections=nil;Banklord_Claim=nil
end
-- One click instead of /reload: popup with "Transfer now", never in combat.
do
  local shown,reloaded=nil,false
  StaticPopupDialogs={};StaticPopup_Show=function(n) shown=n end;StaticPopup_Visible=function() return false end
  ReloadUI=function() reloaded=true end;InCombatLockdown=function() return true end
  assert(A:OfferReload('x')==false and shown==nil,'no popup in combat')
  InCombatLockdown=function() return false end
  assert(A:OfferReload('50% fertig')==true and shown=='BANKLORD_SYNC','popup shown')
  StaticPopupDialogs.BANKLORD_SYNC.OnAccept();assert(reloaded,'accept reloads the interface')
  StaticPopupDialogs=nil;StaticPopup_Show=nil;ReloadUI=nil;InCombatLockdown=nil;StaticPopup_Visible=nil
end
print('PASS: joining by in-game confirmation (request, roster check, answer)')

-- Several bank managers: reservations on the guild channel, never twice.
do
  local sent={}
  local oldSend=C_ChatInfo.SendAddonMessage
  C_ChatInfo.SendAddonMessage=function(p,m,ch) sent[#sent+1]=m end
  IsInGuild=function() return true end
  local oldAfter=C_Timer.After
  local later={}
  C_Timer.After=function(_,fn) later[#later+1]=fn end
  local function run() local l=later;later={};for _,fn in ipairs(l) do fn() end end
  A.db.manageMine,A.db.manageLocks,A.db.manageSent,A.db.manageTaken={}, {}, {}, {}
  local o={id=21,status='submitted',requester='Aria',recipient='Borin',lines={{itemId=2589,name='Leinenstoff',qty=1}}}
  local won
  -- Another manager reserved first (older timestamp) while we wait: we lose.
  A:ClaimOrders({o},function(w) won=w end)
  assert(sent[#sent]:match('^C|%d+|0|21$'),'claim announced: '..tostring(sent[#sent]))
  local myTs=A.db.manageMine[21].ts
  A:ReceiveManageMessage('BLNEW1',('C|%d|0|21'):format(myTs-1),'GUILD','Zora-Everlook')
  run()
  assert(not won and not A.db.manageMine[21],'older competing claim wins')
  assert(A:OrderBlocked(21),'order now blocked for us')
  -- She releases it; now we reserve and keep it against a later claim.
  A:ReceiveManageMessage('BLNEW1','X|21','GUILD','Zora')
  assert(not A:OrderBlocked(21),'released order is free again')
  A:ClaimOrders({o},function(w) won=w end)
  run()
  assert(won and won[1].id==21 and A.db.manageMine[21].est,'uncontested claim established')
  sent={}
  A:ReceiveManageMessage('BLNEW1',('C|%d|0|21'):format(A.db.manageMine[21].ts-50),'GUILD','Kaelis')
  assert(A.db.manageMine[21] and sent[1] and sent[1]:match('|1|21$'),'established claim defended: '..tostring(sent[1]))
  -- Done message from anyone: the order disappears and can never be mailed again.
  A:ReceiveManageMessage('BLNEW1','D|21','GUILD','Zora')
  assert(A.db.manageSent[21] and not A.db.manageMine[21] and A:OrderBlocked(21),'sent order locked for good')
  won=nil;A:ClaimOrders({o},function(w) won=w end);run()
  assert(not won,'sent order cannot be claimed again')
  -- A manager logging in later asks; we repeat the shipment.
  sent={}
  A:ReceiveManageMessage('BLNEW1','Q','GUILD','Late');run()
  local repeated=false
  for _,m in ipairs(sent) do if m:match('^D|.*21') then repeated=true end end
  assert(repeated,'shipment repeated for late managers')
  -- Own echoes are ignored.
  A:ReceiveManageMessage('BLNEW1','D|99','GUILD','Tester')
  assert(not A.db.manageSent[99],'own message ignored')
  C_ChatInfo.SendAddonMessage=oldSend;IsInGuild=nil;C_Timer.After=oldAfter
end
print('PASS: several bank managers coordinate reservations and never send twice')

-- Help & feedback in game: validation, outbox, status from the website.
do
  A.db.feedbackOutbox=nil
  local ok,why=A:QueueFeedback('bug',{title='x',text='kurz',area='addon'})
  assert(not ok and why,'too short report refused')
  ok=A:QueueFeedback('bug',{title='Fenster|bleibt offen',text='Nach dem Schließen\nbleibt es sichtbar.',area='addon'})
  assert(ok and #A.db.feedbackOutbox==1,'bug report queued')
  local row=A.db.feedbackOutbox[1]
  assert(row.key:match('^fb%-%d+%-[a-z0-9]+$') and row.title=='Fenster/bleibt offen' and row.addon==A.version and row.build:find('5.5.4',1,true),'row carries key, versions, no pipes')
  ok=A:QueueFeedback('language',{language='Français',help='yes',text=''})
  assert(ok and A.db.feedbackOutbox[2].title:find('Français',1,true) and #A.db.feedbackOutbox[2].text>=10,'language request gets title and text')
  local rows=A:FeedbackOverview()
  assert(#rows==2 and rows[1].status=='queued','waiting reports listed')
  Banklord_ServerState.feedback={{key=row.key,number=12,kind='bug',title=row.title,status='planned',reply='Kommt bald.'}}
  Banklord_ServerState.feedbackResults={[row.key]={status='new',number=12},[A.db.feedbackOutbox[2].key]={status='pending'}}
  A:ReconcileFeedback()
  assert(#A.db.feedbackOutbox==1 and A.db.feedbackOutbox[1].language=='Français','forwarded report leaves the outbox, unsent one stays')
  rows=A:FeedbackOverview()
  assert(#rows==2 and rows[2].status=='planned' and rows[2].reply=='Kommt bald.','status and answer shown')
  local okc,errc=pcall(SlashCmdList.BANKLORD,'feedback');assert(okc,errc)
  Banklord_ServerState.feedback=nil;Banklord_ServerState.feedbackResults=nil;A.db.feedbackOutbox=nil
end
print('PASS: help & feedback in game (queue, validation, status)')

-- Order rules: approval, partial delivery, shipping notice, member cancel, view-only, tooltip, shopping list.
do
  local sent={}
  local oldCI=C_ChatInfo;C_ChatInfo={SendAddonMessage=function(p,m,ch,t) sent[#sent+1]={p,m,ch,t} end}
  local oldIG,oldGI=IsInGuild,GetGuildInfo;IsInGuild=function() return true end;GetGuildInfo=nil
  local oldAfter=C_Timer.After;C_Timer.After=function(_,f) f() end
  local said,oldAdd={},DEFAULT_CHAT_FRAME.AddMessage;DEFAULT_CHAT_FRAME.AddMessage=function(_,m) said[#said+1]=m end
  local function lastChat() return said[#said] or '' end
  StaticPopupDialogs=nil
  for _,k in ipairs({'manageOutbox','manageDone','manageMine','manageLocks','manageSent','manageTaken','manageProgress','manageRejected','manageDelivered','manageApproved','manageCancelled','orderShipped','orderCancelPending'}) do A.db[k]={} end
  -- Approval: officers see "approve", bank managers wait.
  Banklord_ServerState={generatedAt=time()-60,manage={
    {id=31,status='submitted',approval=2,requester='Aria',recipient='Borin-Everlook',lines={{itemId=2589,name='Leinenstoff',qty=4}}},
    {id=32,status='submitted',approval=1,requester='Aria',recipient='Borin-Everlook',lines={{itemId=2589,name='Leinenstoff',qty=4}}}},
    manageResults={},orders={{id=40,status='submitted',createdAt=time(),lines={}}},
    rules={{viewOnlyTabs={2},weeklyLimit=20,weeklyUsed=18}},restock={{itemId=2589,name='Leinenstoff',have=3,target=50,missing=47}}}
  local _,_,act,possible=A:OrderRowState(Banklord_ServerState.manage[1],{[2589]=10})
  assert(act=='approve' and possible,'officer gets approve action')
  local st2,_,act2=A:OrderRowState(Banklord_ServerState.manage[2],{[2589]=10})
  assert(act2==nil and st2:find('Offizier',1,true),'manager waits for an officer: '..tostring(st2))
  A:ManageOne(Banklord_ServerState.manage[1])
  assert(A.db.manageOutbox[1].action=='approve' and A.db.manageOutbox[1].order==31 and not A.db.manageDone[31],'approval queued, order stays open')
  local _,_,act3=A:OrderRowState(Banklord_ServerState.manage[1],{[2589]=10})
  assert(act3=='withdraw','approved order can be withdrawn')
  -- Partial delivery: 4 wanted, bank holds 2 -> "take part".
  local o=Banklord_ServerState.manage[1]
  local _,_,pact,pok=A:OrderRowState(o,{[2589]=2})
  assert(pact=='partial' and pok,'partial withdraw offered')
  A.db.manageMine[31]={ts=time(),est=true}
  A:RecordPartialDelivery(31,{[2589]=3})
  local open;for _,x in ipairs(A:OpenManageOrders()) do if x.id==31 then open=x end end
  assert(open and open.lines[1].qty==1,'partly sent order shows only the rest')
  Banklord_ServerState.manage[1].lines[1].qty=1
  open=nil;for _,x in ipairs(A:OpenManageOrders()) do if x.id==31 then open=x end end
  assert(open and open.lines[1].qty==1,'server already booked it: nothing subtracted twice')
  A:RecordPartialDelivery(31,{[2589]=1})
  open=nil;for _,x in ipairs(A:OpenManageOrders()) do if x.id==31 then open=x end end
  assert(open==nil,'second part after a booked first part counts from the server state')
  A.db.manageDelivered={}
  sent={}
  A:MarkOrderPartlySent(31,{[2589]=1})
  local box=A.db.manageOutbox[#A.db.manageOutbox]
  assert(box.action=='deliver' and box.lines[1].itemId==2589 and box.lines[1].qty==1 and not A.db.manageDone[31],'partial delivery queued with lines')
  local psent=false;for _,m in ipairs(sent) do if m[2]=='P|31|2589:1' then psent=true end end
  assert(psent,'other managers learn about the partial delivery')
  A:ReceiveManageMessage('BLNEW1','P|32|2589:4','GUILD','Zora-Everlook')
  open=nil;for _,x in ipairs(A:OpenManageOrders()) do if x.id==32 then open=x end end
  assert(open==nil,'fully delivered by another manager: gone from the list')
  -- Shipping notice: whisper to the recipient, member sees "on its way".
  sent={}
  A:NotifyRecipient({id=40,recipient='Borin-Everlook'},false)
  assert(sent[1] and sent[1][2]=='S|40|0' and sent[1][3]=='WHISPER','recipient is whispered: '..tostring(sent[1] and sent[1][2]))
  GetNumGuildMembers=function() return 1 end;GetGuildRosterInfo=function() return 'Kaelis-Everlook' end
  A:ReceiveManageMessage('BLNEW1','S|40|0','WHISPER','Kaelis-Everlook')
  assert(lastChat():find('#40',1,true) and lastChat():find('unterwegs',1,true),'member alerted: '..lastChat())
  local rows=A:GetOrderOverview()
  local shown=false;for _,r in ipairs(rows) do if r.order and r.order.id==40 and r.status:find('Unterwegs',1,true) then shown=true end end
  assert(shown,'order shows as on its way')
  A:ReceiveManageMessage('BLNEW1','S|41|0','WHISPER','Fremder-Everlook')
  assert(not A.db.orderShipped[41],'notice from outside the guild ignored')
  -- Member cancels own order; managers block it only when the recipient announces it.
  sent={}
  A:CancelOwnOrder(40)
  local c=A.db.manageOutbox[#A.db.manageOutbox]
  assert(c.action=='cancel' and c.order==40 and A.db.orderCancelPending[40],'cancel queued')
  assert(sent[#sent][2]=='Z|40','managers told about the cancellation')
  A:ReceiveManageMessage('BLNEW1','Z|31','GUILD','Fremder')
  assert(not A.db.manageCancelled[31],'only the recipient may cancel')
  A:ReceiveManageMessage('BLNEW1','Z|31','GUILD','Borin')
  assert(A:OrderBlocked(31)=='vom Mitglied storniert','cancelled order blocked for managers')
  A:CancelOwnOrder(99);assert(lastChat():find('#99',1,true),'unknown order refused')
  -- Weekly limit, view-only tabs, tooltip, shopping list.
  local limit,used=A:WeeklyLimit();assert(limit==20 and used==18,'weekly limit from the rules')
  A.db.fullScans={{capturedAt=time(),items={{itemID=2589,itemKey='item:2589:0',count=7,itemLink='|cff|Hitem:2589:0|h[Leinenstoff]|h|r'},{itemID=6948,itemKey='item:6948:0',count=1,itemLink='|cff|Hitem:6948:0|h[Ruhestein]|h|r'}},
    tabs={[1]={items={{itemKey='item:2589:0',count=5}}},[2]={items={{itemKey='item:2589:0',count=2},{itemKey='item:6948:0',count=1}}}}}}
  assert(A:OrderableCount('item:2589:0',7)==5 and A:OrderableCount('item:6948:0',1)==0,'view-only share not orderable')
  A.db.localCartDraft={}
  A:AddCartDraftByKey('item:6948:0',1);assert(next(A.db.localCartDraft)==nil and lastChat():find('Nur ansehen',1,true),'view-only item refused')
  A:AddCartDraftByKey('item:2589:0',6);assert(next(A.db.localCartDraft)==nil,'more than the orderable share refused')
  A:AddCartDraftByKey('item:2589:0',5);assert(A.db.localCartDraft['item:2589:0'].quantity==5,'orderable share accepted')
  local lines={};local tt={AddLine=function(_,t) lines[#lines+1]=t end}
  A:AddTooltipStock(tt,2589);assert(lines[1]=='Gildenbank: 7','tooltip shows the bank count')
  A:ToggleTooltipStock();A:AddTooltipStock(tt,2589);assert(#lines==1,'tooltip line can be turned off');A:ToggleTooltipStock()
  local list=A:ShoppingList();local auct,tsm=A:ShoppingExport(list)
  assert(#list==1 and auct=='Banklord^"Leinenstoff";;0;0;0;0;0;0;0;0;;#;;47' and tsm=='i:2589','shopping list exports')
  local oks,errs=pcall(A.ShowShoppingList,A);assert(oks,errs)
  do local f=BanklordShoppingList;local body=f.body;f.edit:SetText('penis');f.edit.scripts.OnTextChanged(f.edit,true)
     assert(f.edit:GetText()==body,'shopping list cannot be edited') end
  local okr,errr=pcall(A.ShowTab,A,'cart');assert(okr,errr)
  C_ChatInfo=oldCI;IsInGuild,GetGuildInfo=oldIG,oldGI;C_Timer.After=oldAfter;DEFAULT_CHAT_FRAME.AddMessage=oldAdd
  GetNumGuildMembers,GetGuildRosterInfo=nil,nil
  A.db.localCartDraft={};A.db.fullScans=nil;Banklord_ServerState={};for _,k in ipairs({'manageOutbox','manageDone','orderShipped','orderCancelPending'}) do A.db[k]={} end
end
assert(A:GameFlavor()=='mop_classic' and BanklordDB.gameFlavor=='mop_classic','game version recorded for the server')
do -- WoW Forever: interface 1.6x or its own project id; Classic Era stays Classic Era.
  local old=GetBuildInfo
  GetBuildInfo=function() return '1.60.1','1','','16001' end;assert(A:GameFlavor()=='forever','Forever by interface')
  GetBuildInfo=function() return '1.15.8','1','','11508' end;assert(A:GameFlavor()=='classic_era','Classic Era unchanged')
  WOW_PROJECT_CAMELOT,WOW_PROJECT_ID=18,18;assert(A:GameFlavor()=='forever','Forever by project id')
  -- Forever: guild found by name even if the website stored a realm.
  local oldGI,oldIn,oldState=GetGuildInfo,IsInGuild,Banklord_ServerState
  GetGuildInfo=function() return 'Ewig','Rang',1,nil end;IsInGuild=function() return true end
  Banklord_ServerState={connections={{server='https://x.example',guildId=4,guild='Ewig',guildRealm='Everlook'}}}
  local g,r=A:CurrentGuild();assert(g=='Ewig' and r=='' and A:ConnectionFor(g,r) and A.SameGuild({guild='Ewig',guildRealm='Everlook'}),'Forever guild matched by name')
  GetGuildInfo,IsInGuild,Banklord_ServerState=oldGI,oldIn,oldState
  WOW_PROJECT_CAMELOT,WOW_PROJECT_ID=nil,nil;GetBuildInfo=old
end
-- Bank ledger: item and gold log, bank gold and TSM prices end up in BanklordDB.bankLog.
do
  local oldOpen=A.bankOpen;A.bankOpen=true
  GetGuildInfo=function() return 'Testgilde' end;GetRealmName=function() return 'Everlook' end
  GetNumGuildBankTabs=function() return 2 end
  GetNumGuildBankTransactions=function(tab) return tab==1 and 2 or 0 end
  GetGuildBankTransaction=function(tab,i)
    if i==1 then return 'deposit','Aria','|cff|Hitem:2589::|h[Leinenstoff]|h|r',20,1,nil,0,0,0,3 end
    return 'move','Borin','|cff|Hitem:2589::|h[Leinenstoff]|h|r',5,1,2,0,0,1,0
  end
  GetNumGuildBankMoneyTransactions=function() return 1 end
  GetGuildBankMoneyTransaction=function() return 'withdraw','Kaelis',150000,0,0,0,2 end
  GetGuildBankMoney=function() return 1234567 end
  TSM_API={GetCustomPriceValue=function(src,item) return item=='i:2589' and 1250 or nil end}
  A.db.fullScans={{items={{itemID=2589,count=25},{itemID=52190,count=1}}}}
  A:ReadBankLog()
  local log=assert(A.db.bankLog,'bank log stored')
  assert(log.guild=='Testgilde' and log.money==1234567 and #log.items==2 and #log.gold==1,'log entries read')
  assert(log.items[1].i==2589 and log.items[1].n=='Leinenstoff' and log.items[2].to==2 and log.gold[1].q==150000,'entry details')
  assert(log.items[1].at%3600==0 and log.items[1].at<=time()-3*3600+3600,'times rounded to the hour')
  assert(#log.prices==1 and log.prices[1].c==1250 and log.prices[1].s=='tsm','TSM price captured')
  local queried={};QueryGuildBankLog=function(t) queried[#queried+1]=t end
  A:CaptureBankLog();assert(#queried==3 and queried[3]==(MAX_GUILDBANK_TABS or 8)+1,'all tab logs and the gold log requested')
  -- In-game cash book: read-only window with tabs, columns and coins like the game.
  local coin=A:CoinText(16615761)
  assert(coin:find('1.661|TInterface\\MoneyFrame\\UI-GoldIcon',1,true) and coin:find('57|TInterface\\MoneyFrame\\UI-SilverIcon',1,true) and coin:find('61|TInterface\\MoneyFrame\\UI-CopperIcon',1,true),'coins like in game: '..coin)
  assert(A:CoinText(4000000):find('^400|T') and not A:CoinText(4000000):find('Silver'),'whole gold without silver/copper')
  assert(A:CoinText(0):find('^0|T.*Copper'),'zero shows 0 copper')
  assert(A:FormatGold(0)=='0c' and A:FormatGold(10000)=='1g','plain gold formatting')
  local gold=assert(A:BankLogRows('gold'))
  assert(#gold==1 and gold[1].cells[2]=='Kaelis' and gold[1].cells[3]:find('entnommen',1,true) and gold[1].cells[4]:find('15|T',1,true),'gold row: time, player, action, amount')
  local items=assert(A:BankLogRows('items'))
  assert(#items==2 and items[1].cells[4]:find('Leinenstoff',1,true),'item rows')
  local over=assert(A:BankLogRows('overview'))
  assert(#over==1 and over[1].cells[1]=='Kaelis' and over[1].cells[3]:find('15|T',1,true),'overview per player')
  A.db.bankLog.items[#A.db.bankLog.items+1]={t='deposit',p='Ohne',i=7,q=1,tab=1,at=0};A.db.bankLog.gold[#A.db.bankLog.gold+1]={p='X',q='kaputt',at=0}
  local found=false;for _,r in ipairs(A:BankLogRows('items')) do if r.cells[4]:find('Item 7',1,true) then found=true end end
  assert(found and #A:BankLogRows('gold')==2,'entries without name, action or number still shown')
  for _,k in ipairs({'overview','gold','items'}) do local okb,errb=pcall(A.ShowBankLog,A,k);assert(okb,errb) end
  assert(BanklordCashBook and BanklordCashBook.kind=='items','cash book window with tabs')
  local okl,errl=pcall(SlashCmdList.BANKLORD,'kasse');assert(okl,errl)
  TSM_API=nil;A.bankOpen=oldOpen;A.db.bankLog=nil
  local msg,oldAdd='',DEFAULT_CHAT_FRAME.AddMessage;DEFAULT_CHAT_FRAME.AddMessage=function(_,m) msg=m end
  local okn,errn=pcall(A.ShowBankLog,A);DEFAULT_CHAT_FRAME.AddMessage=oldAdd;assert(okn,errn)
  assert(msg:find('Gildenbank',1,true) or msg:find('guild bank',1,true),'hint without log: '..msg)
end
print('PASS: order rules in game (approval, partial delivery, shipping notice, cancel, view-only, tooltip, shopping list)')

-- Localization: client language decides, BanklordDB.settings.locale overrides.
local EN=assert(A._localeEN,'EN table not exposed')
-- 1) Every literal A:Tr('...') in the addon has an English entry.
local literalCount=0
for _,name in ipairs(modules) do
  local fh=assert(io.open(root..'/'..name,'rb'));local src=fh:read('*a');fh:close()
  for quote,body in src:gmatch("A:Tr%((['\"])(.-)%1%)") do
    local value=assert(loadstring('return '..quote..body..quote))()
    assert(EN[value],name..': missing English text for '..value)
    literalCount=literalCount+1
  end
end
assert(literalCount>=100,'too few translated literals found: '..literalCount)
local chat={}
DEFAULT_CHAT_FRAME.AddMessage=function(_,m) chat[#chat+1]=m end
local function lastChat() return chat[#chat] or '' end
-- 2) English client: key texts in English, every display string reached has an entry.
local missing={}
setmetatable(EN,{__index=function(_,k) if type(k)=='string' and k:find('%a%a') then missing[k]=true end end})
GetLocale=function() return 'enUS' end
A.db.settings.locale=nil
assert(A:Tr('Warenkorb')=='Cart' and A:Tr('Bankverwaltung')=='Bank management','enUS must give English')
assert(A:Tr('%d Positionen  ·  %d Stück  ·  lokal'):format(2,7)=='2 lines  ·  7 pcs.  ·  local','format string')
A:ApplyUILocale()
local function captions()
  local set={}
  for _,f in ipairs(allFrames) do local c=rawget(f,'caption');if c and c.text then set[c.text]=f end end
  return set
end
local cap=captions()
assert(cap['Add to cart'] and cap['Rescan bank'] and cap['Submit order'],'UI buttons must be English after ApplyUILocale')
for _,tabName in ipairs({'Items','Cart','Orders','Settings'}) do
  local b=assert(cap[tabName],'tab button missing: '..tabName)
  local okc,errc=pcall(b.scripts.OnClick,b);assert(okc,errc)
end
for _,cmd in ipairs({'status','stop','korb','liste','eingang','ausgang','debug','unknown-cmd','scan'}) do
  local okc,errc=pcall(SlashCmdList.BANKLORD,cmd);assert(okc,errc)
end
assert(lastChat():find('guild bank first',1,true),'scan without bank must answer in English: '..lastChat())
for _,row in ipairs(A:GetOrderOverview()) do A:ShowOrderTooltip(UIParent,row) end
local list={};for k in pairs(missing) do list[#list+1]=k end;table.sort(list)
assert(#list==0,'untranslated display texts: '..table.concat(list,' | '))
setmetatable(EN,nil)
assert(A:TrReason('Bankabfrage fehlgeschlagen: boom')=='bank query failed: boom','reason prefix translation')
-- 3) German client: German.
GetLocale=function() return 'deDE' end
assert(A:Tr('Warenkorb')=='Warenkorb' and A:Tr('Zum Warenkorb')=='Zum Warenkorb','deDE must stay German')
-- 4) Override wins in both directions, set via slash command.
local okl,errl=pcall(SlashCmdList.BANKLORD,'lang en');assert(okl,errl)
assert(A.db.settings.locale=='enUS' and A:Tr('Warenkorb')=='Cart','override enUS must win over deDE client')
assert(lastChat():find('/reload',1,true) and lastChat():find('Language',1,true),'lang change must mention /reload in English')
assert(captions()['Add to cart'],'lang en must relabel the UI')
GetLocale=function() return 'frFR' end
SlashCmdList.BANKLORD('lang de')
assert(A.db.settings.locale=='deDE' and A:Tr('Warenkorb')=='Warenkorb','override deDE must win over frFR client')
assert(captions()['Zum Warenkorb'],'lang de must relabel the UI')
SlashCmdList.BANKLORD('lang auto')
assert(A.db.settings.locale==nil and A:Tr('Warenkorb')=='Cart','auto must follow the client (frFR -> English)')
A.db.settings.locale='xxXX';initEvent(A.frame,'ADDON_LOADED','Banklord')
assert(A.db.settings.locale==nil,'invalid saved locale must be dropped')
print('PASS: locale (client language, /banklord lang override, '..literalCount..' translated literals)')
