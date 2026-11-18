-- Bank manager tools: order alerts, taking orders out of the guild bank and
-- preparing the mail for each order. Everything works through the game API,
-- not through Blizzard's frames, so other guild bank or mail addons (combined
-- tab views, Postal, ...) may replace those frames without breaking this.
local A=Banklord
local PREFIX='BLNEW1'
local MAX_ROWS=10

-- Bags -------------------------------------------------------------------
local function bagSlots(bag)
  if C_Container and C_Container.GetContainerNumSlots then return C_Container.GetContainerNumSlots(bag) or 0 end
  return GetContainerNumSlots and GetContainerNumSlots(bag) or 0
end
local function bagItem(bag,slot)
  if C_Container and C_Container.GetContainerItemInfo then
    local i=C_Container.GetContainerItemInfo(bag,slot)
    if i then return i.itemID,i.stackCount,i.isLocked end
    return nil
  end
  if not GetContainerItemInfo then return nil end
  local _,count,locked,_,_,_,link,_,_,id=GetContainerItemInfo(bag,slot)
  return id or (link and tonumber(link:match('item:(%d+)'))),count,locked
end
local function lastBag() return (NUM_BAG_SLOTS or 4)+((NUM_REAGENTBAG_SLOTS or 0)>0 and 1 or 0) end
local function eachBagSlot(fn)
  for bag=0,lastBag() do for slot=1,bagSlots(bag) do if fn(bag,slot) then return end end end
end
local function bagCount(itemId)
  local n=0
  eachBagSlot(function(bag,slot) local id,count=bagItem(bag,slot); if id==itemId then n=n+(count or 0) end end)
  return n
end
local function emptyBagSlot()
  local found
  eachBagSlot(function(bag,slot) if not bagItem(bag,slot) then found={bag,slot};return true end end)
  if found then return found[1],found[2] end
end
local function pickupBag(bag,slot) local f=(C_Container and C_Container.PickupContainerItem) or PickupContainerItem; if f then f(bag,slot) end end
local function splitBag(bag,slot,n) local f=(C_Container and C_Container.SplitContainerItem) or SplitContainerItem; if f then f(bag,slot,n) end end

-- Data -------------------------------------------------------------------
local function state() return type(Banklord_ServerState)=='table' and Banklord_ServerState or {} end
function A:IsBankManager() return type(state().manage)=='table' end
local function store(name) A.db[name]=A.db[name] or {}; return A.db[name] end

-- Guild of the logged-in character: name, realm (nil until the game knows it).
function A:CurrentGuild()
  if not (GetGuildInfo and IsInGuild and IsInGuild()) then return nil end
  local name,_,_,realm=GetGuildInfo('player')
  if not name or name=='' then return nil end
  return name,(realm and realm~='' and realm) or (GetRealmName and GetRealmName()) or ''
end

-- Remember character + guild so the website can offer it as order recipient.
function A:RememberCharacter()
  if not self.db or not UnitName then return end
  local name,realm=UnitName('player'),GetRealmName and GetRealmName() or ''
  if not name or name=='' or realm=='' then return end
  local chars,key=store('characters'),name..'-'..realm:gsub('%s','')
  local guild,guildRealm=self:CurrentGuild()
  if guild then chars[key]={guild=guild,guildRealm=guildRealm,seen=time()}
  elseif IsInGuild and not IsInGuild() then chars[key]=nil end
end

-- True when an order/state entry belongs to the current guild (or carries no tag).
local function sameGuild(entry)
  local g,r=A:CurrentGuild()
  if not g or type(entry)~='table' or not entry.guild then return true end
  local n=function(s) return tostring(s or ''):gsub("[%s'%-]",''):lower() end
  return n(entry.guild)==n(g) and (not entry.guildRealm or entry.guildRealm=='' or n(entry.guildRealm)==n(r))
end
A.SameGuild=sameGuild

-- Open orders the manager still has to handle (sent ones are hidden at once).
function A:OpenManageOrders()
  local out={}
  if not self.db then return out end
  local done=store('manageDone')
  for _,o in ipairs(state().manage or {}) do
    if type(o)=='table' and not done[o.id] and not store('manageSent')[o.id] and sameGuild(o) then out[#out+1]=o end
  end
  return out
end

local function summary(o)
  local parts={}
  for i,l in ipairs(o.lines or {}) do
    if i>2 then parts[#parts+1]='…';break end
    parts[#parts+1]=('%dx %s'):format(l.qty or 0,l.name or '?')
  end
  return table.concat(parts,', ')
end

local function randomTag()
  local s='';for _=1,6 do local n=math.random(0,35);s=s..(n<10 and tostring(n) or string.char(87+n)) end;return s
end

-- Queue a handover/rejection; the companion uploads it after /reload.
function A:QueueManageAction(orderId,action)
  local box=store('manageOutbox')
  local g,r=self:CurrentGuild()
  box[#box+1]={key=('ma-%d-%s'):format(time(),randomTag()),order=orderId,action=action,guild=g,guildRealm=r}
  store('manageDone')[orderId]=true
end

-- Called once after login: drop answered actions, report errors, alert new orders.
function A:ReconcileManage()
  local results=state().manageResults or {}
  local box,keep=store('manageOutbox'),{}
  local done=store('manageDone')
  for _,row in ipairs(box) do
    local r=results[row.key]
    if not r then keep[#keep+1]=row
    elseif r.error and r.error~='unknown' then
      done[row.order]=nil
      self:Message(A:Tr('Bestellung #%d konnte nicht gebucht werden: %s'):format(row.order,A:TrReason(r.error)))
    end
  end
  self.db.manageOutbox=keep
  local open={}
  for _,o in ipairs(state().manage or {}) do open[o.id]=true end
  for id in pairs(done) do if not open[id] then done[id]=nil end end
  if not self:IsBankManager() then return end
  local seen,new=store('manageSeen'),{}
  for _,o in ipairs(self:OpenManageOrders()) do if not seen[o.id] then new[#new+1]=o;seen[o.id]=true end end
  for id in pairs(seen) do if not open[id] then seen[id]=nil end end
  if #new>0 and C_Timer then
    C_Timer.After(8,function()
      for i,o in ipairs(new) do
        if i>3 then A:Message(A:Tr('… und %d weitere neue Bestellungen.'):format(#new-3));break end
        A:Alert(A:Tr('Neue Bestellung #%d von %s: %s'):format(o.id,o.recipient~='' and o.recipient or o.requester,summary(o)))
      end
    end)
  end
end

function A:Alert(text)
  self:Message(text)
  if RaidNotice_AddMessage and RaidWarningFrame then
    pcall(RaidNotice_AddMessage,RaidWarningFrame,'Banklord: '..text,ChatTypeInfo and ChatTypeInfo.RAID_WARNING or {r=1,g=.82,b=0})
  end
  if PlaySound then pcall(PlaySound,(SOUNDKIT and SOUNDKIT.RAID_WARNING) or 8959) end
end

-- Live alert to bank managers who are online when a member orders in game.
function A:BroadcastNewOrder(character,code)
  local lines,pieces=0,0
  for part in tostring(code):sub(5):gmatch('[^|]+') do
    local qty=tonumber(part:match('^%d+,(%d+)')) or 0
    lines=lines+1;pieces=pieces+qty
  end
  local payload=('N|%s|%d|%d'):format(tostring(character):gsub('|',''):sub(1,80),lines,pieces)
  local send=(C_ChatInfo and C_ChatInfo.SendAddonMessage) or SendAddonMessage
  if send and IsInGuild and IsInGuild() then pcall(send,PREFIX,payload,'GUILD') end
end

function A:ReceiveManageMessage(prefix,payload,channel,sender)
  if prefix~=PREFIX or channel~='GUILD' or type(payload)~='string' or #payload>250 then return end
  if payload:match('^[CDXQ]') then return self:ReceiveCoordination(payload,sender) end
  if not self:IsBankManager() then return end
  local who,lines,pieces=payload:match('^N|([^|]+)|(%d+)|(%d+)$')
  if not who then return end
  self:Alert(A:Tr('%s hat bestellt: %d Positionen, %d Stück. Sichtbar nach /reload.'):format(who,tonumber(lines),tonumber(pieces)))
end

-- Coordination between bank managers ---------------------------------------
-- Nothing is ever taken out or mailed without a reservation. A manager
-- announces "C" (claim) for the order ids on the guild addon channel and waits
-- CLAIM_WINDOW seconds for objections. Conflicts are decided the same way on
-- every client: an established reservation beats a new one, otherwise the older
-- timestamp (server time), then the lower name wins. Whoever already holds an
-- order answers every competing claim at once. After the mail is sent the
-- sender announces "D" (done); every Banklord in the guild remembers it for 30
-- days and repeats it to managers who log in later ("Q"), so a manager who was
-- offline cannot send the same order again.
local CLAIM_WINDOW,CLAIM_TTL,SENT_TTL=3,6*3600,30*86400
local function now() return (GetServerTime and GetServerTime()) or time() end
local function lname(s) return (tostring(s or ''):gsub("[%s'%-]",''):lower()) end
local function short(n) n=tostring(n or ''); if Ambiguate then n=Ambiguate(n,'none') end; return (n:match('^[^-]+') or n) end
local function meName() return (UnitName and UnitName('player')) or '?' end
local function guildSend(payload)
  local f=(C_ChatInfo and C_ChatInfo.SendAddonMessage) or SendAddonMessage
  if f and IsInGuild and IsInGuild() then pcall(f,PREFIX,payload,'GUILD') end
end
-- Several ids per message (addon messages are limited to 255 bytes).
local function sendIds(head,ids)
  local chunk={}
  local function flush() if #chunk>0 then guildSend(head..table.concat(chunk,',')) end;chunk={} end
  for _,id in ipairs(ids) do chunk[#chunk+1]=tostring(id);if #chunk>=25 then flush() end end
  flush()
end
local function idList(s)
  local out={}
  for n in tostring(s or ''):gmatch('%d+') do if #out<40 then out[#out+1]=tonumber(n) end end
  return out
end

-- Why this order may not be handled here (nil = free or our own reservation).
function A:OrderBlocked(id)
  if store('manageSent')[id] then return A:Tr('bereits versendet') end
  if store('manageMine')[id] then return nil end
  local l=store('manageLocks')[id]
  if l and now()-(l.seen or 0)<CLAIM_TTL then return A:Tr('in Arbeit bei %s'):format(l.by) end
end

-- Reserve orders, wait for objections, then continue with the won ones.
function A:ClaimOrders(orders,fn)
  local mine,want,fresh=store('manageMine'),{},{}
  for _,o in ipairs(orders) do
    local why=self:OrderBlocked(o.id)
    if why then self:Message(A:Tr('Bestellung #%d: %s.'):format(o.id,why))
    else
      want[#want+1]=o
      if not (mine[o.id] and mine[o.id].est) then mine[o.id]={ts=now()};fresh[#fresh+1]=o.id end
    end
  end
  if #want==0 then return end
  if #fresh==0 then return fn(want) end
  sendIds(('C|%d|0|'):format(now()),fresh)
  self:Message(A:Tr('Reserviere %d Bestellung(en) bei den anderen Verwaltern …'):format(#fresh))
  C_Timer.After(CLAIM_WINDOW,function()
    local won={}
    for _,o in ipairs(want) do
      local c=mine[o.id]
      if c and not store('manageSent')[o.id] then c.est=true;won[#won+1]=o
      else A:Message(A:Tr('Bestellung #%d übernimmt bereits ein anderer Verwalter.'):format(o.id)) end
    end
    if #won>0 then fn(won) end
    A:RefreshManagePanel()
  end)
end

function A:ReleaseOrders(ids)
  local mine,out=store('manageMine'),{}
  for _,id in ipairs(ids) do if mine[id] then mine[id]=nil;out[#out+1]=id end end
  sendIds('X|',out)
end

function A:MarkOrderSent(id)
  store('manageSent')[id]=now()
  store('manageMine')[id]=nil
  store('manageLocks')[id]=nil
  sendIds('D|',{id})
end

local function lostOrder(id,by)
  local mine=store('manageMine')
  local had=mine[id] and mine[id].est
  mine[id]=nil
  if A.mailPrepared==id then
    A.mailPrepared,A.mailAll=nil,false
    if ClearSendMail then pcall(ClearSendMail) end
  end
  if had then A:Alert(A:Tr('Bestellung #%d übernimmt jetzt %s. Bitte entnommene Gegenstände zurücklegen.'):format(id,by)) end
end

function A:ReceiveCoordination(payload,sender)
  local from=short(sender)
  if from=='' or lname(from)==lname(meName()) then return end
  local kind,rest=payload:match('^([CDXQ])|?(.*)$')
  if kind=='C' then
    local ts,est,ids=rest:match('^(%d+)|([01])|([%d,]+)$')
    ts=tonumber(ts);if not ts then return end
    local mine,locks,answer=store('manageMine'),store('manageLocks'),{}
    for _,id in ipairs(idList(ids)) do
      local m=mine[id]
      local theirs=est=='1'
      local theyWin=true
      if m then
        if (m.est or false)~=theirs then theyWin=theirs
        else theyWin=ts<m.ts or (ts==m.ts and lname(from)<lname(meName())) end
      end
      if store('manageSent')[id] then answer[#answer+1]=-id
      elseif m and not theyWin then answer[#answer+1]=id
      else
        if m then lostOrder(id,from) end
        locks[id]={by=from,ts=ts,est=theirs,seen=now()}
      end
    end
    local again,done={},{}
    for _,id in ipairs(answer) do if id<0 then done[#done+1]=-id else again[#again+1]=id end end
    if #done>0 then sendIds('D|',done) end
    -- Answer with our own (older or established) reservations.
    local byTs={}
    for _,id in ipairs(again) do local m=mine[id];local k=m.ts..'|'..(m.est and 1 or 0);byTs[k]=byTs[k] or {};table.insert(byTs[k],id) end
    for k,list in pairs(byTs) do sendIds('C|'..k..'|',list) end
  elseif kind=='D' then
    for _,id in ipairs(idList(rest)) do
      if not store('manageSent')[id] then
        store('manageSent')[id]=now()
        store('manageLocks')[id]=nil
        if store('manageMine')[id] then lostOrder(id,from) end
        store('manageTaken')[id]=nil
      end
    end
    self:RefreshManagePanel()
  elseif kind=='X' then
    local locks=store('manageLocks')
    for _,id in ipairs(idList(rest)) do if locks[id] and lname(locks[id].by)==lname(from) then locks[id]=nil end end
    self:RefreshManagePanel()
  elseif kind=='Q' then
    -- Somebody logged in: repeat what we know, a little later to spread the load.
    if self.coordReplyAt and now()-self.coordReplyAt<30 then return end
    self.coordReplyAt=now()
    C_Timer.After(math.random(1,4),function() A:AnnounceCoordination() end)
  end
end

-- Repeat own reservations and known shipments (after login or on request).
function A:AnnounceCoordination()
  local byTs={}
  for id,m in pairs(store('manageMine')) do
    if m.est then local k=m.ts..'|1';byTs[k]=byTs[k] or {};table.insert(byTs[k],id) end
  end
  for k,list in pairs(byTs) do sendIds('C|'..k..'|',list) end
  local sent={}
  for id,t in pairs(store('manageSent')) do if now()-t<7*86400 then sent[#sent+1]=id end end
  table.sort(sent);while #sent>50 do table.remove(sent,1) end
  sendIds('D|',sent)
end

-- After login: forget old entries, drop stale reservations, ask the guild.
function A:InitCoordination()
  local t=now()
  for id,v in pairs(store('manageSent')) do if t-v>SENT_TTL then store('manageSent')[id]=nil end end
  for id,l in pairs(store('manageLocks')) do if t-(l.seen or 0)>CLAIM_TTL then store('manageLocks')[id]=nil end end
  for _,m in pairs(store('manageMine')) do if t-m.ts>CLAIM_TTL then m.est=false end end
  if C_Timer then C_Timer.After(6,function() guildSend('Q');A:AnnounceCoordination() end) end
end

-- Guild bank -------------------------------------------------------------
local function bankSlotsPerTab() return MAX_GUILDBANK_SLOTS_PER_TAB or 98 end
local function findBankStack(itemId)
  local tabs=GetNumGuildBankTabs and GetNumGuildBankTabs() or 0
  for tab=1,tabs do
    local _,_,viewable=GetGuildBankTabInfo(tab)
    if viewable then
      for slot=1,bankSlotsPerTab() do
        local link=GetGuildBankItemLink(tab,slot)
        if link and tonumber(link:match('item:(%d+)'))==itemId then
          local _,count,locked=GetGuildBankItemInfo(tab,slot)
          if not locked and (count or 0)>0 then return tab,slot,count end
        end
      end
    end
  end
end

function A:WithdrawOrders(orders)
  if not self:IsGuildBankOpen() then return self:Message(A:Tr('Bitte zuerst die Gildenbank öffnen.')) end
  if self.withdraw or self.claiming then return self:Message(A:Tr('Entnahme läuft bereits.')) end
  self.claiming=true
  self:ClaimOrders(orders,function(won) A.claiming=nil;A:StartWithdraw(won) end)
  if self.claiming and not C_Timer then self.claiming=nil end
  C_Timer.After(CLAIM_WINDOW+1,function() A.claiming=nil end)
end

function A:StartWithdraw(orders)
  if not self:IsGuildBankOpen() then return self:Message(A:Tr('Entnahme abgebrochen: Gildenbank geschlossen.')) end
  local queue={}
  for _,o in ipairs(orders) do
    for _,l in ipairs(o.lines or {}) do queue[#queue+1]={item=l.itemId,name=l.name,qty=l.qty,order=o.id} end
  end
  self.withdraw={queue=queue,i=1,orders=orders,missing={}}
  self:WithdrawStep()
end

function A:WithdrawStep()
  local w=self.withdraw
  if not w then return end
  if not self:IsGuildBankOpen() then self.withdraw=nil;return self:Message(A:Tr('Entnahme abgebrochen: Gildenbank geschlossen.')) end
  local job=w.queue[w.i]
  if not job then
    self.withdraw=nil
    local taken,release=store('manageTaken'),{}
    for _,o in ipairs(w.orders) do if not w.missing[o.id] then taken[o.id]=true else release[#release+1]=o.id end end
    if #release>0 then self:ReleaseOrders(release) end
    self:Message(A:Tr('Entnahme fertig. Jetzt zum Briefkasten gehen.'))
    return self:RefreshManagePanel()
  end
  if job.qty<=0 or not store('manageMine')[job.order] then w.i=w.i+1;return self:WithdrawStep() end
  local tab,slot,count=findBankStack(job.item)
  if not tab then
    w.missing[job.order]=true
    self:Message(A:Tr('Nicht genug %s in der Gildenbank (es fehlen %d).'):format(job.name or '?',job.qty))
    w.i=w.i+1;return self:WithdrawStep()
  end
  if GetCurrentGuildBankTab and GetCurrentGuildBankTab()~=tab and SetCurrentGuildBankTab then
    SetCurrentGuildBankTab(tab)
    if QueryGuildBankTab then QueryGuildBankTab(tab) end
    return C_Timer.After(.6,function() A:WithdrawStep() end)
  end
  local take=math.min(count,job.qty)
  if take==count then
    AutoStoreGuildBankItem(tab,slot)
  else
    local bag,bslot=emptyBagSlot()
    if not bag then self.withdraw=nil;return self:Message(A:Tr('Taschen voll. Entnahme gestoppt.')) end
    SplitGuildBankItem(tab,slot,take)
    pickupBag(bag,bslot)
  end
  job.qty=job.qty-take
  C_Timer.After(.8,function() A:WithdrawStep() end)
end

-- Mail -------------------------------------------------------------------
local function mailRecipient(o)
  local name,realm=tostring(o.recipient or ''):match('^([^-]+)-?(.*)$')
  if not name or name=='' then return nil end
  local own=(GetRealmName and GetRealmName() or ''):gsub('%s','')
  if realm=='' or realm:gsub('%s','')==own then return name end
  return name..'-'..realm:gsub('%s','')
end

-- Bag stacks that cover every line of the order, or nil plus the reason.
local function attachmentPlan(o)
  local plan,used={},{}
  for _,l in ipairs(o.lines or {}) do
    local left=l.qty
    eachBagSlot(function(bag,slot)
      local id,count,locked=bagItem(bag,slot)
      if id==l.itemId and not locked and not used[bag..':'..slot] then
        local n=math.min(count,left)
        used[bag..':'..slot]=true
        plan[#plan+1]={bag=bag,slot=slot,n=n,whole=(n==count)}
        left=left-n
      end
      return left<=0
    end)
    if left>0 then return nil,A:Tr('Es fehlen %d %s in den Taschen.'):format(left,l.name or '?') end
  end
  if #plan>(ATTACHMENTS_MAX_SEND or 12) then return nil,A:Tr('Zu viele Stapel für einen Brief. Bitte Gegenstände stapeln.') end
  return plan
end
function A:CanMailOrder(o) return attachmentPlan(o)~=nil end

function A:PrepareMail(o)
  if not (SendMailNameEditBox and ClickSendMailItemButton) then return self:Message(A:Tr('Bitte zuerst einen Briefkasten öffnen.')) end
  local to=mailRecipient(o)
  if not to then return self:Message(A:Tr('Bestellung #%d hat keinen Empfänger-Charakter.'):format(o.id)) end
  local plan,why=attachmentPlan(o)
  if not plan then return self:Message(why) end
  self:ClaimOrders({o},function() A:FillMail(o,to) end)
end

function A:FillMail(o,to)
  local plan,why=attachmentPlan(o)
  if not plan then return self:Message(why) end
  if not store('manageMine')[o.id] or store('manageSent')[o.id] then return end
  if MailFrameTab2 and MailFrameTab2.Click then MailFrameTab2:Click() end
  if ClearSendMail then ClearSendMail() end
  SendMailNameEditBox:SetText(to)
  if SendMailSubjectEditBox then SendMailSubjectEditBox:SetText(('Banklord #%d'):format(o.id)) end
  self.mailPrepared=o.id
  local i=0
  local function attach()
    i=i+1
    local p=plan[i]
    if not p then
      return self:Message(A:Tr('Brief für #%d an %s ist bereit. Bitte auf „Senden“ klicken.'):format(o.id,to))
    end
    if p.whole then pickupBag(p.bag,p.slot) else splitBag(p.bag,p.slot,p.n) end
    ClickSendMailItemButton(i)
    C_Timer.After(.35,attach)
  end
  attach()
end

function A:MailNext()
  for _,o in ipairs(self:OpenManageOrders()) do
    if not self:OrderBlocked(o.id) and self:CanMailOrder(o) and mailRecipient(o) then self.mailAll=true;return self:PrepareMail(o) end
  end
  self.mailAll=false
  self:Message(A:Tr('Keine weitere Bestellung mit passenden Gegenständen in den Taschen.'))
end

-- Only mails whose subject we prepared count as handed over.
if hooksecurefunc and SendMail then
  hooksecurefunc('SendMail',function(_,subject)
    local id=tonumber(tostring(subject or ''):match('^Banklord #(%d+)$'))
    A.mailSending=(id and id==A.mailPrepared) and id or nil
    if A.mailSending then store('manageSent')[id]=now() end
  end)
end

-- Panel ------------------------------------------------------------------
local panel
local function button(parent,w,caption,fn)
  local b=CreateFrame('Button',nil,parent,'UIPanelButtonTemplate')
  b:SetSize(w,22);b:SetText(caption);b:SetScript('OnClick',fn)
  return b
end
local function buildPanel()
  panel=CreateFrame('Frame','BanklordManagePanel',UIParent,BackdropTemplateMixin and 'BackdropTemplate' or nil)
  panel:SetSize(340,96+MAX_ROWS*26)
  panel:SetFrameStrata('HIGH')
  if panel.SetBackdrop then
    panel:SetBackdrop({bgFile='Interface\\DialogFrame\\UI-DialogBox-Background',edgeFile='Interface\\DialogFrame\\UI-DialogBox-Border',tile=true,tileSize=32,edgeSize=24,insets={left=6,right=6,top=6,bottom=6}})
  end
  panel:SetMovable(true);panel:EnableMouse(true);panel:RegisterForDrag('LeftButton')
  panel:SetScript('OnDragStart',panel.StartMoving)
  panel:SetScript('OnDragStop',function(self)
    self:StopMovingOrSizing()
    local p,_,rp,x,y=self:GetPoint();A.db.settings.managePos={p,rp,x,y}
  end)
  panel.title=panel:CreateFontString(nil,'OVERLAY','GameFontNormal')
  panel.title:SetPoint('TOPLEFT',14,-14)
  panel.all=button(panel,150,'',function() A:ManageAll() end)
  panel.all:SetPoint('TOPLEFT',12,-36)
  panel.close=button(panel,70,A:Tr('Schließen'),function() panel:Hide() end)
  panel.close:SetPoint('TOPRIGHT',-12,-36)
  panel.rows={}
  for i=1,MAX_ROWS do
    local r=CreateFrame('Frame',nil,panel);r:SetSize(316,24);r:SetPoint('TOPLEFT',12,-64-(i-1)*26)
    r.text=r:CreateFontString(nil,'OVERLAY','GameFontHighlightSmall')
    r.text:SetPoint('LEFT',0,0);r.text:SetWidth(226);r.text:SetJustifyH('LEFT')
    r.go=button(r,86,'',function() A:ManageOne(r.order) end);r.go:SetPoint('RIGHT',0,0)
    panel.rows[i]=r
  end
  panel.note=panel:CreateFontString(nil,'OVERLAY','GameFontDisableSmall')
  panel.note:SetPoint('BOTTOMLEFT',14,12);panel.note:SetWidth(312);panel.note:SetJustifyH('LEFT')
end

function A:ManageOne(o)
  if not o then return end
  if self.manageMode=='bank' then self:WithdrawOrders({o}) else self.mailAll=false;self:PrepareMail(o) end
end
function A:ManageAll()
  if self.manageMode=='bank' then
    local list,taken={},store('manageTaken')
    for _,o in ipairs(self:OpenManageOrders()) do if not taken[o.id] and not self:OrderBlocked(o.id) then list[#list+1]=o end end
    self:WithdrawOrders(list)
  else self:MailNext() end
end

function A:ShowManagePanel(mode,anchor)
  if not self.db or not self:IsBankManager() then return end
  if #self:OpenManageOrders()==0 and mode~='list' then return end
  if not panel then buildPanel() end
  self.manageMode=mode
  panel:ClearAllPoints()
  local pos=self.db.settings.managePos
  if pos then panel:SetPoint(pos[1],UIParent,pos[2],pos[3],pos[4])
  elseif anchor and anchor.IsVisible and anchor:IsVisible() then panel:SetPoint('TOPLEFT',anchor,'TOPRIGHT',4,0)
  else panel:SetPoint('RIGHT',UIParent,'RIGHT',-40,60) end
  panel:Show()
  self:RefreshManagePanel()
end
function A:HideManagePanel(mode) if panel and self.manageMode==mode then panel:Hide() end end

function A:RefreshManagePanel()
  if not panel or not panel:IsShown() then return end
  local mode,orders,taken=self.manageMode,self:OpenManageOrders(),store('manageTaken')
  panel.title:SetText(mode=='bank' and A:Tr('Banklord – offene Bestellungen entnehmen')
    or mode=='mail' and A:Tr('Banklord – Bestellungen versenden') or A:Tr('Banklord – offene Bestellungen'))
  panel.all:SetText(mode=='bank' and A:Tr('Alle entnehmen') or A:Tr('Alle versenden'))
  panel.all:SetShown(mode~='list')
  for i,r in ipairs(panel.rows) do
    local o=orders[i]
    r.order=o
    if o then
      local who=o.recipient~='' and o.recipient or o.requester
      r.text:SetText(('#%d %s: %s'):format(o.id,who,summary(o)))
      r.go:SetShown(mode~='list')
      local blocked=self:OrderBlocked(o.id)
      if blocked then
        r.text:SetText(('#%d %s (%s)'):format(o.id,who,blocked))
        r.go:SetText(A:Tr('Gesperrt'));r.go:SetEnabled(false)
      elseif mode=='bank' then
        r.go:SetText(taken[o.id] and A:Tr('Entnommen') or A:Tr('Entnehmen'));r.go:SetEnabled(true)
      else
        r.go:SetText(A:Tr('Versenden'))
        r.go:SetEnabled(self:CanMailOrder(o))
      end
      r:Show()
    else r:Hide() end
  end
  local more=#orders-MAX_ROWS
  panel.note:SetText(more>0 and A:Tr('%d weitere Bestellungen nach dem Versand.'):format(more)
    or (#orders==0 and A:Tr('Keine offenen Bestellungen.') or A:Tr('Ziehen zum Verschieben. Neue Bestellungen erscheinen nach /reload.')))
end

function A:OnBankStateChanged()
  if self.bankOpen then
    if C_Timer then C_Timer.After(1,function() A:ShowManagePanel('bank',A:GetVisibleGuildBankFrame() and GuildBankFrame) end) end
  else
    self.withdraw=nil
    self:HideManagePanel('bank')
  end
end

local events=CreateFrame('Frame')
A._manageEvents=events
for _,e in ipairs({'PLAYER_ENTERING_WORLD','PLAYER_GUILD_UPDATE','MAIL_SHOW','MAIL_CLOSED','MAIL_SEND_SUCCESS','MAIL_FAILED','BAG_UPDATE_DELAYED'}) do pcall(events.RegisterEvent,events,e) end
events:SetScript('OnEvent',function(_,event)
  if not A.db then return end
  if event=='PLAYER_ENTERING_WORLD' or event=='PLAYER_GUILD_UPDATE' then A:RememberCharacter();A:StartJoinRequests()
  elseif event=='MAIL_SHOW' then A:ShowManagePanel('mail',MailFrame)
  elseif event=='MAIL_CLOSED' then A.mailPrepared,A.mailAll=nil,false;A:HideManagePanel('mail')
  elseif event=='MAIL_SEND_SUCCESS' then
    local id=A.mailSending
    A.mailSending,A.mailPrepared=nil,nil
    if id then
      A:MarkOrderSent(id)
      A:QueueManageAction(id,'deliver')
      store('manageTaken')[id]=nil
      A:Message(A:Tr('Bestellung #%d versendet und als ausgegeben vorgemerkt.'):format(id))
      A:RefreshManagePanel()
      if A.mailAll and C_Timer then C_Timer.After(1.5,function() A:MailNext() end) end
    end
  elseif event=='MAIL_FAILED' then
    if A.mailSending then store('manageSent')[A.mailSending]=nil end
    A.mailSending=nil;A.mailAll=false
  elseif event=='BAG_UPDATE_DELAYED' then A:RefreshManagePanel() end
end)

function A:InitManage()
  local reg=(C_ChatInfo and C_ChatInfo.RegisterAddonMessagePrefix) or RegisterAddonMessagePrefix
  if reg then pcall(reg,PREFIX);pcall(reg,'BLJOIN1') end
  self:ReconcileManage()
  self:InitCoordination()
end

-- Joining by in-game confirmation -------------------------------------------
-- The member's addon sends the companion's PUBLIC key on the guild channel. An
-- online bank manager checks the sender (set by Blizzard's server) against the
-- real guild roster, remembers the key for the website and answers with the
-- website address. The companion then proves the matching private key.
local JOIN='BLJOIN1'
local function norm(s) return tostring(s or ''):gsub("[%s'%-]",''):lower() end
local function send(payload,channel,target)
  local f=(C_ChatInfo and C_ChatInfo.SendAddonMessage) or SendAddonMessage
  if f then pcall(f,JOIN,payload,channel,target) end
end

-- Full roster name ("Name-Realm") of a guild member, or nil.
function A:RosterMember(name)
  local short,realm=tostring(name):match('^([^-]+)-?(.*)$')
  if not short or not GetNumGuildMembers or not GetGuildRosterInfo then return nil end
  for i=1,(GetNumGuildMembers() or 0) do
    local full=GetGuildRosterInfo(i)
    if full then
      local n,r=full:match('^([^-]+)-?(.*)$')
      if n==short and (realm=='' or r=='' or norm(r)==norm(realm)) then
        if r=='' then r=(GetRealmName and GetRealmName() or ''):gsub('%s','') end
        return n..'-'..r
      end
    end
  end
end
local function requestRoster()
  if C_GuildInfo and C_GuildInfo.GuildRoster then pcall(C_GuildInfo.GuildRoster) elseif GuildRoster then pcall(GuildRoster) end
end

-- Website connection (server address, guild id) the companion has for a guild.
function A:ConnectionFor(guild,realm)
  for _,c in ipairs(state().connections or {}) do
    if norm(c.guild)==norm(guild) and (not realm or c.guildRealm=='' or norm(c.guildRealm)==norm(realm)) then return c end
  end
end

local function claimKey()
  local k=type(Banklord_Claim)=='table' and Banklord_Claim.key
  if type(k)=='string' and #k==64 and k:match('^%x+$') then return k:lower() end
end

function A:SendJoinRequest()
  local key,g,r=claimKey(),self:CurrentGuild()
  if not key or not g or self:ConnectionFor(g,r) then return false end
  send('K|'..key,'GUILD')
  return true
end

function A:StartJoinRequests()
  if self.joinTimer or not C_Timer then return end
  local tries=0
  local function again()
    tries=tries+1
    local g,r=A:CurrentGuild()
    if not claimKey() or (g and A:ConnectionFor(g,r)) or tries>6 then return end
    A:SendJoinRequest()
    C_Timer.After(g and 300 or 20,again)
  end
  self.joinTimer=true
  C_Timer.After(15,again)
end

function A:ReceiveJoinMessage(prefix,payload,channel,sender)
  if prefix~=JOIN or type(payload)~='string' or #payload>250 or type(sender)~='string' then return end
  local key=payload:match('^K|(%x+)$')
  if key and channel=='GUILD' and #key==64 and self:IsBankManager() then
    local g,r=self:CurrentGuild()
    local conn=g and self:ConnectionFor(g,r)
    if not conn then return end
    local function vouch(retry)
      local who=A:RosterMember(sender)
      if not who then
        if retry and C_Timer then requestRoster();C_Timer.After(3,function() vouch(false) end) end
        return
      end
      local claims=store('claims')
      if claims[key:lower()] then return end
      for k,v in pairs(claims) do if (v.seen or 0)<time()-14*86400 then claims[k]=nil end end
      claims[key:lower()]={character=who,guild=g,guildRealm=r,seen=time()}
      send(('S|%s|%d'):format(conn.server,conn.guildId),'WHISPER',sender)
      A:Message(A:Tr('%s möchte Banklord nutzen und wurde bestätigt. Die Freischaltung wird nach deinem nächsten /reload übertragen.'):format(who))
    end
    return vouch(true)
  end
  local server,id=payload:match('^S|(https://[%w%.%-]+)|(%d+)$')
  if server and channel=='WHISPER' then
    local function accept(retry)
      local by=A:RosterMember(sender)
      if not by then
        if retry and C_Timer then requestRoster();C_Timer.After(3,function() accept(false) end) end
        return
      end
      local offers=store('joinOffers')
      local k=server..'|'..id
      if offers[k] then return end
      offers[k]={server=server,guild=tonumber(id),by=by,at=time()}
      A:Message(A:Tr('%s hat dich für die Gilden-Website bestätigt. Nach /reload oder Logout verbindet der Companion automatisch.'):format(by))
    end
    accept(true)
  end
end
