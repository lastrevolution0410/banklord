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

-- Open orders the manager still has to handle (sent ones are hidden at once).
function A:OpenManageOrders()
  local out={}
  if not self.db then return out end
  local done=store('manageDone')
  for _,o in ipairs(state().manage or {}) do
    if type(o)=='table' and not done[o.id] then out[#out+1]=o end
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
  box[#box+1]={key=('ma-%d-%s'):format(time(),randomTag()),order=orderId,action=action}
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
  if prefix~=PREFIX or channel~='GUILD' or type(payload)~='string' or #payload>200 then return end
  if not self:IsBankManager() then return end
  local who,lines,pieces=payload:match('^N|([^|]+)|(%d+)|(%d+)$')
  if not who then return end
  self:Alert(A:Tr('%s hat bestellt: %d Positionen, %d Stück. Sichtbar nach /reload.'):format(who,tonumber(lines),tonumber(pieces)))
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
  if self.withdraw then return self:Message(A:Tr('Entnahme läuft bereits.')) end
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
    local taken=store('manageTaken')
    for _,o in ipairs(w.orders) do if not w.missing[o.id] then taken[o.id]=true end end
    self:Message(A:Tr('Entnahme fertig. Jetzt zum Briefkasten gehen.'))
    return self:RefreshManagePanel()
  end
  if job.qty<=0 then w.i=w.i+1;return self:WithdrawStep() end
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
    if self:CanMailOrder(o) and mailRecipient(o) then self.mailAll=true;return self:PrepareMail(o) end
  end
  self.mailAll=false
  self:Message(A:Tr('Keine weitere Bestellung mit passenden Gegenständen in den Taschen.'))
end

-- Only mails whose subject we prepared count as handed over.
if hooksecurefunc and SendMail then
  hooksecurefunc('SendMail',function(_,subject)
    local id=tonumber(tostring(subject or ''):match('^Banklord #(%d+)$'))
    A.mailSending=(id and id==A.mailPrepared) and id or nil
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
    for _,o in ipairs(self:OpenManageOrders()) do if not taken[o.id] then list[#list+1]=o end end
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
      if mode=='bank' then
        r.go:SetText(taken[o.id] and A:Tr('Entnommen') or A:Tr('Entnehmen'))
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
for _,e in ipairs({'MAIL_SHOW','MAIL_CLOSED','MAIL_SEND_SUCCESS','MAIL_FAILED','BAG_UPDATE_DELAYED'}) do pcall(events.RegisterEvent,events,e) end
events:SetScript('OnEvent',function(_,event)
  if not A.db then return end
  if event=='MAIL_SHOW' then A:ShowManagePanel('mail',MailFrame)
  elseif event=='MAIL_CLOSED' then A.mailPrepared,A.mailAll=nil,false;A:HideManagePanel('mail')
  elseif event=='MAIL_SEND_SUCCESS' then
    local id=A.mailSending
    A.mailSending,A.mailPrepared=nil,nil
    if id then
      A:QueueManageAction(id,'deliver')
      store('manageTaken')[id]=nil
      A:Message(A:Tr('Bestellung #%d versendet und als ausgegeben vorgemerkt.'):format(id))
      A:RefreshManagePanel()
      if A.mailAll and C_Timer then C_Timer.After(1.5,function() A:MailNext() end) end
    end
  elseif event=='MAIL_FAILED' then A.mailSending=nil;A.mailAll=false
  elseif event=='BAG_UPDATE_DELAYED' then A:RefreshManagePanel() end
end)

function A:InitManage()
  local reg=(C_ChatInfo and C_ChatInfo.RegisterAddonMessagePrefix) or RegisterAddonMessagePrefix
  if reg then pcall(reg,PREFIX) end
  self:ReconcileManage()
end
