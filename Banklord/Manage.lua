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

-- Pieces of an item in an order as the server state lists them.
local function rawQty(o,item)
  local n=0
  for _,l in ipairs(o.lines or {}) do if l.itemId==item then n=n+(l.qty or 0) end end
  return n
end
-- Partial deliveries (own or reported by other managers) that the server state
-- does not show yet. base = open pieces when the delivery happened: once the
-- state lists fewer, the server has booked it and nothing is subtracted twice.
local function openCopy(o)
  local d=store('manageDelivered')[o.id]
  if type(d)~='table' or type(d.items)~='table' then return o end
  local left={}
  for item,n in pairs(d.items) do
    if rawQty(o,item)>=((d.base or {})[item] or 0) then left[item]=n end
  end
  if not next(left) then return o end
  local c={};for k,v in pairs(o) do c[k]=v end
  c.lines={}
  for _,l in ipairs(o.lines or {}) do
    local cut=math.min(left[l.itemId] or 0,l.qty or 0)
    if cut>0 then left[l.itemId]=left[l.itemId]-cut end
    if (l.qty or 0)-cut>0 then c.lines[#c.lines+1]={itemId=l.itemId,name=l.name,qty=(l.qty or 0)-cut} end
  end
  return #c.lines>0 and c or nil
end
local function stateOrder(id)
  for _,o in ipairs(state().manage or {}) do if type(o)=='table' and o.id==id and sameGuild(o) then return o end end
end
function A:RecordPartialDelivery(id,items)
  local o=stateOrder(id)
  local d=store('manageDelivered')
  d[id]=type(d[id])=='table' and d[id] or {items={},base={}}
  d[id].at=time()
  for item,n in pairs(items) do
    local now=o and rawQty(o,item) or 0
    -- Earlier parts already booked by the server: start counting again from its state.
    if not d[id].base[item] or now<d[id].base[item] then d[id].base[item]=now;d[id].items[item]=0 end
    d[id].items[item]=(d[id].items[item] or 0)+n
  end
end

-- Open orders the manager still has to handle (sent ones are hidden at once).
function A:OpenManageOrders()
  local out={}
  if not self.db then return out end
  local done=store('manageDone')
  for _,o in ipairs(state().manage or {}) do
    if type(o)=='table' and not done[o.id] and not store('manageSent')[o.id] and sameGuild(o) then
      local c=openCopy(o)
      if c then out[#out+1]=c end
    end
  end
  return out
end

-- Weekly limit: 0 = free, 1 = waits for an officer, 2 = waits and you may approve.
function A:OrderApproval(o)
  local a=tonumber(o and o.approval) or 0
  if a>0 and store('manageApproved')[o.id] then return 0 end
  return a
end

local function summary(o)
  local parts={}
  for i,l in ipairs(o.lines or {}) do
    if i>2 then parts[#parts+1]='…';break end
    parts[#parts+1]=('%dx %s'):format(l.qty or 0,l.name or '?')
  end
  return table.concat(parts,', ')
end

function A:OrderSummary(o) return summary(o) end

local function randomTag()
  local s='';for _=1,6 do local n=math.random(0,35);s=s..(n<10 and tostring(n) or string.char(87+n)) end;return s
end

-- Queue a handover/rejection/approval/cancellation; the companion uploads it
-- after /reload. lines = partial delivery {{itemId,qty}}; keepOpen leaves the
-- order in the list (partial delivery, approval, own cancellation).
function A:QueueManageAction(orderId,action,lines,keepOpen)
  local box=store('manageOutbox')
  local g,r=self:CurrentGuild()
  box[#box+1]={key=('ma-%d-%s'):format(time(),randomTag()),order=orderId,action=action,lines=lines,guild=g,guildRealm=r}
  if not keepOpen then store('manageDone')[orderId]=true end
end

-- Called once after login: drop answered actions, report errors, alert new orders.
function A:ReconcileManage()
  local results=state().manageResults or {}
  local box,keep=store('manageOutbox'),{}
  local done=store('manageDone')
  for _,row in ipairs(box) do
    local r=results[row.key]
    if r and row.action=='cancel' then store('orderCancelPending')[row.order]=nil end
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
  for id,v in pairs(store('orderShipped')) do if type(v)~='table' or time()-(v.at or 0)>30*86400 then store('orderShipped')[id]=nil end end
  if not self:IsBankManager() then return end
  local seen,new=store('manageSeen'),{}
  for _,o in ipairs(self:OpenManageOrders()) do if not seen[o.id] then new[#new+1]=o;seen[o.id]=true end end
  for id in pairs(seen) do if not open[id] then seen[id]=nil end end
  if self.UpdateMinimapBadge then self:UpdateMinimapBadge(#new>0 or nil) end
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
  -- Chat line and sound only; the minimap button blinks. No big text across the screen.
  self:Message(text)
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
  if prefix==PREFIX and channel=='WHISPER' and type(payload)=='string' then
    local id,part=payload:match('^S|(%d+)|([01])$')
    if id then self:ReceiveShipped(tonumber(id),part=='1',sender) end
    return
  end
  if prefix~=PREFIX or channel~='GUILD' or type(payload)~='string' or #payload>250 then return end
  if payload:match('^[CDXQRPZ]') then return self:ReceiveCoordination(payload,sender) end
  if not self:IsBankManager() then return end
  local who,lines,pieces=payload:match('^N|([^|]+)|(%d+)|(%d+)$')
  if not who then return end
  self.liveNewOrders=(self.liveNewOrders or 0)+1
  if self.UpdateMinimapBadge then self:UpdateMinimapBadge(true) end
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
  if store('manageCancelled')[id] then return A:Tr('vom Mitglied storniert') end
  if store('manageRejected')[id] then return A:Tr('abgelehnt') end
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
  store('manageProgress')[id]=nil
  store('manageSent')[id]=now()
  store('manageMine')[id]=nil
  store('manageLocks')[id]=nil
  sendIds('D|',{id})
  if self.UpdateMinimapBadge then self:UpdateMinimapBadge() end
end

-- Part of an order was mailed: book exactly that, keep the rest open for any manager.
function A:MarkOrderPartlySent(id,items)
  local lines,parts={},{}
  for item,n in pairs(items) do lines[#lines+1]={itemId=item,qty=n};parts[#parts+1]=item..':'..n end
  table.sort(parts)
  self:QueueManageAction(id,'deliver',lines,true)
  self:RecordPartialDelivery(id,items)
  store('manageProgress')[id]=nil;store('manageTaken')[id]=nil
  self:ReleaseOrders({id})
  guildSend(('P|%d|%s'):format(id,table.concat(parts,',')))
  if self.UpdateMinimapBadge then self:UpdateMinimapBadge() end
end

-- The member learns right away that the mail is on its way (whisper to the recipient).
function A:NotifyRecipient(o,partial)
  local name,realm=tostring(o and o.recipient or ''):match('^([^-]+)-?(.*)$')
  if not name or name=='' then return end
  local own=(GetRealmName and GetRealmName() or ''):gsub('%s','')
  local to=(realm=='' or realm:gsub('%s','')==own) and name or name..'-'..realm:gsub('%s','')
  local f=(C_ChatInfo and C_ChatInfo.SendAddonMessage) or SendAddonMessage
  if f then pcall(f,PREFIX,('S|%d|%d'):format(o.id,partial and 1 or 0),'WHISPER',to) end
end
function A:ReceiveShipped(id,partial,sender)
  local function accept(retry)
    local by=A:RosterMember(sender)
    if not by then
      if retry and C_Timer then C_Timer.After(3,function() accept(false) end) end
      return
    end
    store('orderShipped')[id]={at=time(),part=partial,by=short(by)}
    A:Alert((partial and A:Tr('Ein Teil deiner Bestellung #%d ist unterwegs (von %s). Schau in deinen Briefkasten – der Rest folgt.')
      or A:Tr('Deine Bestellung #%d ist unterwegs (von %s). Schau in deinen Briefkasten.')):format(id,short(by)))
    if A.RefreshUI then A:RefreshUI() end
  end
  accept(true)
end

function A:ApproveOrder(o)
  if not o or self:OrderApproval(o)~=2 then return end
  self:QueueManageAction(o.id,'approve',nil,true)
  store('manageApproved')[o.id]=now()
  self:Message(A:Tr('Bestellung #%d freigegeben. Die Website erfährt es beim nächsten /reload.'):format(o.id))
  self:RefreshManagePanel()
end

-- Members cancel their own open orders in game.
local CANCELLABLE={submitted=true,reserved=true,partial=true,partially_fulfilled=true}
function A:CancelOwnOrder(id)
  local found
  for _,o in ipairs(state().orders or {}) do if type(o)=='table' and o.id==id and sameGuild(o) then found=o end end
  if not found then return self:Message(A:Tr('Keine eigene Bestellung #%d gefunden.'):format(id or 0)) end
  if not CANCELLABLE[found.status] or store('orderCancelPending')[id] then return self:Message(A:Tr('Bestellung #%d kann nicht mehr storniert werden.'):format(id)) end
  if StaticPopupDialogs and StaticPopup_Show then
    StaticPopupDialogs.BANKLORD_CANCEL={button1=YES or 'Ja',button2=NO or 'Nein',timeout=0,whileDead=true,hideOnEscape=true,preferredIndex=3,
      text=A:Tr('Bestellung #%d stornieren?\n\nBereits verschickte Gegenstände bleiben bei dir.'):format(id):gsub('%%','%%%%'),
      OnAccept=function() A:ConfirmCancel(id) end}
    StaticPopup_Show('BANKLORD_CANCEL')
  else self:ConfirmCancel(id) end
end
function A:ConfirmCancel(id)
  self:QueueManageAction(id,'cancel',nil,true)
  store('orderCancelPending')[id]=time()
  guildSend('Z|'..id)
  self:Message(A:Tr('Bestellung #%d wird storniert. Die Website erfährt es beim nächsten /reload.'):format(id))
  if self.RefreshUI then self:RefreshUI() end
end
function A:IsCancellable(o) return type(o)=='table' and CANCELLABLE[o.status] and not store('orderCancelPending')[o.id] end

-- Shopping list from the target stock rules (website: "Nachkauf").
function A:ShoppingList()
  local out={}
  for _,r in ipairs(state().restock or {}) do
    if type(r)=='table' and sameGuild(r) and (tonumber(r.missing) or 0)>0 then out[#out+1]=r end
  end
  return out
end
local function cleanName(s) return (tostring(s or '?'):gsub('[%^";|]','')) end
function A:ShoppingExport(list)
  local auct,tsm={},{}
  for _,r in ipairs(list) do
    auct[#auct+1]=('"%s";;0;0;0;0;0;0;0;0;;#;;%d'):format(cleanName(r.name),r.missing)
    tsm[#tsm+1]='i:'..tostring(r.itemId)
  end
  return 'Banklord^'..table.concat(auct,'^'),table.concat(tsm,',')
end
local listFrame
function A:ShowShoppingList()
  local list=self:ShoppingList()
  if #list==0 then return self:Message(A:Tr('Einkaufsliste: Nichts fehlt. Mindestbestände legst du auf der Website unter „Nachkauf“ fest.')) end
  local lines={}
  for _,r in ipairs(list) do lines[#lines+1]=A:Tr('%dx %s (vorhanden %d, Ziel %d)'):format(r.missing,cleanName(r.name),r.have or 0,r.target or 0) end
  local auct,tsm=self:ShoppingExport(list)
  local body=table.concat(lines,'\n')..'\n\n'..A:Tr('Auctionator (Einkaufen → Importieren):')..'\n'..auct..'\n\n'..A:Tr('TSM (Gruppe → Importieren):')..'\n'..tsm
  if not listFrame then
    listFrame=CreateFrame('Frame','BanklordShoppingList',UIParent,BackdropTemplateMixin and 'BackdropTemplate' or nil)
    listFrame:SetSize(520,380);listFrame:SetPoint('CENTER');listFrame:SetFrameStrata('DIALOG')
    if listFrame.SetBackdrop then
      listFrame:SetBackdrop({bgFile='Interface\\DialogFrame\\UI-DialogBox-Background',edgeFile='Interface\\DialogFrame\\UI-DialogBox-Border',tile=true,tileSize=32,edgeSize=24,insets={left=6,right=6,top=6,bottom=6}})
    end
    listFrame:SetMovable(true);listFrame:EnableMouse(true);listFrame:RegisterForDrag('LeftButton')
    listFrame:SetScript('OnDragStart',listFrame.StartMoving);listFrame:SetScript('OnDragStop',listFrame.StopMovingOrSizing)
    listFrame.title=listFrame:CreateFontString(nil,'OVERLAY','GameFontNormal');listFrame.title:SetPoint('TOPLEFT',16,-16)
    listFrame.hint=listFrame:CreateFontString(nil,'OVERLAY','GameFontDisableSmall');listFrame.hint:SetPoint('TOPLEFT',16,-34)
    local scroll=CreateFrame('ScrollFrame',nil,listFrame,'UIPanelScrollFrameTemplate')
    scroll:SetPoint('TOPLEFT',16,-54);scroll:SetPoint('BOTTOMRIGHT',-34,46)
    listFrame.edit=CreateFrame('EditBox',nil,scroll)
    listFrame.edit:SetMultiLine(true);listFrame.edit:SetFontObject(ChatFontNormal or 'GameFontHighlightSmall');listFrame.edit:SetWidth(460);listFrame.edit:SetAutoFocus(false)
    listFrame.edit:SetScript('OnEscapePressed',function() listFrame:Hide() end)
    scroll:SetScrollChild(listFrame.edit)
    local close=CreateFrame('Button',nil,listFrame,'UIPanelButtonTemplate')
    close:SetSize(110,24);close:SetPoint('BOTTOMRIGHT',-16,14);close:SetText(A:Tr('Schließen'));close:SetScript('OnClick',function() listFrame:Hide() end)
  end
  listFrame.title:SetText(A:Tr('Banklord – Einkaufsliste (%d Gegenstände)'):format(#list))
  listFrame.hint:SetText(A:Tr('Text markieren und mit Strg+C kopieren. Ziele legst du auf der Website unter „Nachkauf“ fest.'))
  listFrame.edit:SetText(body);listFrame.edit:SetFocus();listFrame.edit:HighlightText()
  listFrame:Show()
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
  local kind,rest=payload:match('^([CDXQRPZ])|?(.*)$')
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
  elseif kind=='R' then
    -- Another manager rejected these orders: nobody may withdraw or send them.
    for _,id in ipairs(idList(rest)) do store('manageRejected')[id]=now() end
    self:RefreshManagePanel()
  elseif kind=='P' then
    -- Another manager mailed part of an order: the rest stays open.
    local id,list=rest:match('^(%d+)|([%d:,]+)$')
    id=tonumber(id)
    if id then
      local items={}
      for item,n in list:gmatch('(%d+):(%d+)') do items[tonumber(item)]=tonumber(n) end
      self:RecordPartialDelivery(id,items)
      store('manageLocks')[id]=nil
      self:RefreshManagePanel()
    end
  elseif kind=='Z' then
    -- A member cancelled their own order (only the recipient may announce it).
    for _,id in ipairs(idList(rest)) do
      local o=stateOrder(id)
      if o and lname(short(o.recipient))==lname(from) then store('manageCancelled')[id]=now() end
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
  for id,v in pairs(store('manageRejected')) do if t-v>SENT_TTL then store('manageRejected')[id]=nil end end
  for id,v in pairs(store('manageCancelled')) do if t-v>SENT_TTL then store('manageCancelled')[id]=nil end end
  for id,v in pairs(store('manageApproved')) do if t-v>SENT_TTL then store('manageApproved')[id]=nil end end
  for id,d in pairs(store('manageDelivered')) do if type(d)~='table' or time()-(d.at or 0)>7*86400 then store('manageDelivered')[id]=nil end end
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

-- Pieces per item in all viewable guild bank tabs (as far as the client has loaded them).
local function bankStock()
  local stock={}
  local tabs=GetNumGuildBankTabs and GetNumGuildBankTabs() or 0
  for tab=1,tabs do
    local _,_,viewable=GetGuildBankTabInfo(tab)
    if viewable then
      for slot=1,bankSlotsPerTab() do
        local link=GetGuildBankItemLink(tab,slot)
        local id=link and tonumber(link:match('item:(%d+)'))
        if id then local _,count=GetGuildBankItemInfo(tab,slot);stock[id]=(stock[id] or 0)+(count or 0) end
      end
    end
  end
  return stock
end
-- nil when the bank holds everything for the order, else what is missing.
-- With consume=true the pieces are taken from stock (for "withdraw all").
local function shortfall(o,stock,consume)
  local need,done={},store('manageProgress')[o.id] or {}
  for _,l in ipairs(o.lines or {}) do need[l.itemId]=(need[l.itemId] or 0)+(l.qty or 0) end
  for id,n in pairs(need) do need[id]=math.max(0,n-(done[id] or 0)) end
  local miss={}
  for _,l in ipairs(o.lines or {}) do
    local n=need[l.itemId]
    if n and (stock[l.itemId] or 0)<n then miss[#miss+1]=('%dx %s'):format(n-(stock[l.itemId] or 0),l.name or '?') end
    need[l.itemId]=nil
  end
  if #miss>0 then return A:Tr('fehlt: %s'):format(table.concat(miss,', ')) end
  if consume then for _,l in ipairs(o.lines or {}) do stock[l.itemId]=(stock[l.itemId] or 0)-math.max(0,(l.qty or 0)-(done[l.itemId] or 0)) end end
end
A._bankStock,A._shortfall=bankStock,shortfall
-- True when the bank holds at least something of what the order still needs.
local function partlyThere(o,stock)
  local done=store('manageProgress')[o.id] or {}
  for _,l in ipairs(o.lines or {}) do
    if (l.qty or 0)>(done[l.itemId] or 0) and (stock[l.itemId] or 0)>0 then return true end
  end
end

-- One place that decides what an order row says and which button it offers:
-- returns status text, tone ('ok','warn','busy','info'), action ('withdraw',
-- 'mail' or nil) and whether that action is possible right now.
-- stock: bankStock() while the guild bank is open, else nil.
function A:OrderRowState(o,stock)
  local blocked=self:OrderBlocked(o.id)
  if blocked then return blocked,'busy' end
  local approval=self:OrderApproval(o)
  if approval==2 then return A:Tr('Über dem Wochenlimit – bitte freigeben'),'warn','approve',true end
  if approval==1 then return A:Tr('Über dem Wochenlimit – wartet auf einen Offizier'),'busy' end
  local taken=store('manageTaken')[o.id]
  if self.mailOpen then
    if self:CanMailOrder(o) then return A:Tr('Bereit zum Versenden'),'ok','mail',true end
    if taken and self:CanMailOrder(o,true) then return A:Tr('Teil in den Taschen – der Rest bleibt offen'),'warn','mailpart',true end
    if taken then return A:Tr('Gegenstände fehlen in den Taschen'),'warn','mail',false end
  end
  if taken then return A:Tr('Entnommen – jetzt zum Briefkasten'),'info' end
  if stock then
    if self.scanRun then return A:Tr('Bestand wird geprüft …'),'busy','withdraw',false end
    local short=shortfall(o,stock)
    if short and partlyThere(o,stock) then return short,'warn','partial',true end
    if short then return short,'warn','withdraw',false end
    return A:Tr('Bereit zum Entnehmen'),'ok','withdraw',true
  end
  return A:Tr('Offen – an der Gildenbank entnehmen'),'info'
end
function A:BankStockNow() if self:IsGuildBankOpen() then return bankStock() end end

function A:WithdrawOrders(orders,partial)
  if not self:IsGuildBankOpen() then return self:Message(A:Tr('Bitte zuerst die Gildenbank öffnen.')) end
  if self.withdraw or self.claiming then return self:Message(A:Tr('Entnahme läuft bereits.')) end
  self.claiming=true
  self:ClaimOrders(orders,function(won) A.claiming=nil;A:StartWithdraw(won,partial) end)
  if self.claiming and not C_Timer then self.claiming=nil end
  C_Timer.After(CLAIM_WINDOW+1,function() A.claiming=nil end)
end

function A:StartWithdraw(orders,partial)
  if not self:IsGuildBankOpen() then return self:Message(A:Tr('Entnahme abgebrochen: Gildenbank geschlossen.')) end
  local queue={}
  for _,o in ipairs(orders) do
    -- Only what is still missing: a stop (e.g. full bags) can be resumed without taking twice.
    local credit={}
    for id,n in pairs(store('manageProgress')[o.id] or {}) do credit[id]=n end
    for _,l in ipairs(o.lines or {}) do
      local use=math.min(credit[l.itemId] or 0,l.qty or 0)
      credit[l.itemId]=(credit[l.itemId] or 0)-use
      queue[#queue+1]={item=l.itemId,name=l.name,qty=(l.qty or 0)-use,order=o.id}
    end
  end
  self.withdraw={queue=queue,i=1,orders=orders,missing={},partial=partial}
  self:WithdrawStep()
end

function A:WithdrawStep()
  local w=self.withdraw
  if not w then return end
  if not self:IsGuildBankOpen() then self.withdraw=nil;return self:Message(A:Tr('Entnahme abgebrochen: Gildenbank geschlossen.')) end
  local job=w.queue[w.i]
  if not job then
    self.withdraw=nil
    local taken,release,part=store('manageTaken'),{},false
    for _,o in ipairs(w.orders) do
      if not w.missing[o.id] then taken[o.id]=true
      elseif w.partial and next(store('manageProgress')[o.id] or {}) then taken[o.id]=true;part=true
      else release[#release+1]=o.id end
    end
    if #release>0 then self:ReleaseOrders(release) end
    self:Message(part and A:Tr('Teil entnommen. Am Briefkasten „Teil senden“ – der Rest bleibt offen.') or A:Tr('Entnahme fertig. Jetzt zum Briefkasten gehen.'))
    return self:RefreshManagePanel()
  end
  if job.qty<=0 or not store('manageMine')[job.order] then w.i=w.i+1;return self:WithdrawStep() end
  local tab,slot,count=findBankStack(job.item)
  if not tab then
    w.missing[job.order]=true
    if not w.partial then self:Message(A:Tr('Nicht genug %s in der Gildenbank (es fehlen %d).'):format(job.name or '?',job.qty)) end
    w.i=w.i+1;return self:WithdrawStep()
  end
  if GetCurrentGuildBankTab and GetCurrentGuildBankTab()~=tab and SetCurrentGuildBankTab then
    SetCurrentGuildBankTab(tab)
    if QueryGuildBankTab then QueryGuildBankTab(tab) end
    return C_Timer.After(.6,function() A:WithdrawStep() end)
  end
  local take=math.min(count,job.qty)
  local bag,bslot=emptyBagSlot()
  if not bag then return self:StopWithdrawBagsFull(job) end
  if take==count then
    AutoStoreGuildBankItem(tab,slot)
  else
    SplitGuildBankItem(tab,slot,take)
    pickupBag(bag,bslot)
  end
  local progress=store('manageProgress')
  progress[job.order]=progress[job.order] or {}
  progress[job.order][job.item]=(progress[job.order][job.item] or 0)+take
  job.qty=job.qty-take
  C_Timer.After(.8,function() A:WithdrawStep() end)
end

-- Rejecting in game: confirm, reserve like any other action (so two managers
-- never handle the same order), queue the rejection for the website and tell
-- the other managers ("R", plus "D" for older addon versions).
function A:RejectOrder(o)
  if not o then return end
  local why=self:OrderBlocked(o.id)
  if why then return self:Message(A:Tr('Bestellung #%d: %s.'):format(o.id,why)) end
  local who=(o.recipient~='' and o.recipient) or o.requester or '?'
  if StaticPopupDialogs and StaticPopup_Show then
    StaticPopupDialogs.BANKLORD_REJECT={button1=YES or 'Ja',button2=NO or 'Nein',timeout=0,whileDead=true,hideOnEscape=true,preferredIndex=3,
      text=A:Tr('Bestellung #%d von %s ablehnen?\n\nDas Mitglied sieht „Abgelehnt“ nach deinem nächsten /reload.'):format(o.id,who):gsub('%%','%%%%'),
      OnAccept=function() A:ConfirmReject(o) end}
    StaticPopup_Show('BANKLORD_REJECT')
  else self:ConfirmReject(o) end
end
function A:ConfirmReject(o)
  self:ClaimOrders({o},function(won)
    if not won[1] then return end
    local id=o.id
    A:QueueManageAction(id,'reject')
    local t=now()
    store('manageRejected')[id]=t;store('manageSent')[id]=t
    store('manageMine')[id]=nil;store('manageLocks')[id]=nil;store('manageProgress')[id]=nil
    local had=store('manageTaken')[id];store('manageTaken')[id]=nil
    sendIds('R|',{id});sendIds('D|',{id})
    A:Message(A:Tr('Bestellung #%d abgelehnt. Die Website erfährt es beim nächsten /reload.'):format(id))
    if had then A:Message(A:Tr('Bitte die entnommenen Gegenstände zurück in die Gildenbank legen.')) end
    A:RefreshManagePanel()
  end)
end

-- Bags full: keep what was taken (and the reservation of that order), release
-- the orders not started yet, and say exactly how to continue.
function A:StopWithdrawBagsFull(job)
  local w=self.withdraw
  self.withdraw=nil
  local release={}
  for _,o in ipairs(w and w.orders or {}) do
    local started=store('manageProgress')[o.id]
    if o.id~=job.order and not started then release[#release+1]=o.id end
  end
  if #release>0 then self:ReleaseOrders(release) end
  self:Message(A:Tr('Taschen voll: Bestellung #%d ist erst teilweise entnommen. Platz schaffen und erneut „Entnehmen“ klicken – Banklord nimmt dann nur den Rest.'):format(job.order))
  if PlaySound then pcall(PlaySound,(SOUNDKIT and SOUNDKIT.IG_QUEST_FAILED) or 847) end
  self:RefreshManagePanel()
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
local function attachmentPlan(o,partial)
  local plan,used,sent={},{},{}
  for _,l in ipairs(o.lines or {}) do
    local left=l.qty
    eachBagSlot(function(bag,slot)
      local id,count,locked=bagItem(bag,slot)
      if id==l.itemId and not locked and not used[bag..':'..slot] then
        local n=math.min(count,left)
        used[bag..':'..slot]=true
        plan[#plan+1]={bag=bag,slot=slot,n=n,whole=(n==count)}
        sent[l.itemId]=(sent[l.itemId] or 0)+n
        left=left-n
      end
      return left<=0
    end)
    if left>0 and not partial then return nil,A:Tr('Es fehlen %d %s in den Taschen.'):format(left,l.name or '?') end
  end
  if #plan==0 then return nil,A:Tr('Keine Gegenstände dieser Bestellung in den Taschen.') end
  if #plan>(ATTACHMENTS_MAX_SEND or 12) then return nil,A:Tr('Zu viele Stapel für einen Brief. Bitte Gegenstände stapeln.') end
  return plan,nil,sent
end
function A:CanMailOrder(o,partial) return attachmentPlan(o,partial)~=nil end

function A:PrepareMail(o,partial)
  if not (SendMailNameEditBox and ClickSendMailItemButton) then return self:Message(A:Tr('Bitte zuerst einen Briefkasten öffnen.')) end
  local to=mailRecipient(o)
  if not to then return self:Message(A:Tr('Bestellung #%d hat keinen Empfänger-Charakter.'):format(o.id)) end
  local plan,why=attachmentPlan(o,partial)
  if not plan then return self:Message(why) end
  self:ClaimOrders({o},function() A:FillMail(o,to,partial) end)
end

function A:FillMail(o,to,partial)
  local full=attachmentPlan(o)
  if full then partial=false end
  local plan,why,sent=attachmentPlan(o,partial)
  if not plan then return self:Message(why) end
  self.mailPartial=partial and sent or nil
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
    if not self:OrderBlocked(o.id) and self:OrderApproval(o)==0 and self:CanMailOrder(o) and mailRecipient(o) then self.mailAll=true;return self:PrepareMail(o) end
  end
  self.mailAll=false
  self:Message(A:Tr('Keine weitere Bestellung mit passenden Gegenständen in den Taschen.'))
end

-- Only mails whose subject we prepared count as handed over.
if hooksecurefunc and SendMail then
  hooksecurefunc('SendMail',function(_,subject)
    local id=tonumber(tostring(subject or ''):match('^Banklord #(%d+)$'))
    A.mailSending=(id and id==A.mailPrepared) and id or nil
    if A.mailSending and not A.mailPartial then store('manageSent')[id]=now() end
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
    r.text:SetPoint('LEFT',0,0);r.text:SetWidth(206);r.text:SetJustifyH('LEFT')
    r.go=button(r,106,'',function() A:ManageOne(r.order,r.action) end);r.go:SetPoint('RIGHT',0,0)
    panel.rows[i]=r
  end
  panel.note=panel:CreateFontString(nil,'OVERLAY','GameFontDisableSmall')
  panel.note:SetPoint('BOTTOMLEFT',14,12);panel.note:SetWidth(312);panel.note:SetJustifyH('LEFT')
end

function A:ManageOne(o,action)
  if not o then return end
  local approval=self:OrderApproval(o)
  if approval==2 then return self:ApproveOrder(o) end
  if approval==1 then return self:Message(A:Tr('Bestellung #%d liegt über dem Wochenlimit und wartet auf die Freigabe durch einen Offizier.'):format(o.id)) end
  if self.manageMode=='bank' then
    local stock=bankStock()
    local short=shortfall(o,stock)
    if short and not (action=='partial' and partlyThere(o,stock)) then return self:Message(A:Tr('Bestellung #%d: %s'):format(o.id,short)) end
    self:WithdrawOrders({o},short and true or nil)
  else self.mailAll=false;self:PrepareMail(o,action=='mailpart') end
end
function A:ManageAll()
  if self.manageMode=='bank' then
    local list,taken={},store('manageTaken')
    local stock,skipped=bankStock(),0
    for _,o in ipairs(self:OpenManageOrders()) do
      if not taken[o.id] and not self:OrderBlocked(o.id) and self:OrderApproval(o)==0 then
        if shortfall(o,stock,true) then skipped=skipped+1 else list[#list+1]=o end
      end
    end
    if skipped>0 then self:Message(A:Tr('%d Bestellungen übersprungen: nicht genug in der Gildenbank.'):format(skipped)) end
    if #list==0 then return self:RefreshManagePanel() end
    self:WithdrawOrders(list)
  else self:MailNext() end
end

local CAPTIONS={withdraw='Entnehmen',mail='Versenden',partial='Teil entnehmen',mailpart='Teil senden',approve='Freigeben'}
function A:ActionCaption(action) return action and A:Tr(CAPTIONS[action]) or '' end

function A:ShowManagePanel(mode,anchor)
  if not self.db or not self:IsBankManager() then return end
  -- At the bank the panel always opens for managers (also with no orders),
  -- at the mailbox only when there is something to send.
  if #self:OpenManageOrders()==0 and mode=='mail' then return end
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
  if self.UpdateMinimapBadge then self:UpdateMinimapBadge() end
  if self.RefreshOrdersTab then self:RefreshOrdersTab() end
  if not panel or not panel:IsShown() then return end
  local mode,orders=self.manageMode,self:OpenManageOrders()
  local stock=mode=='bank' and bankStock() or nil
  panel.title:SetText(mode=='bank' and A:Tr('Banklord – offene Bestellungen entnehmen')
    or mode=='mail' and A:Tr('Banklord – Bestellungen versenden') or A:Tr('Banklord – offene Bestellungen'))
  panel.all:SetText(mode=='bank' and A:Tr('Alle entnehmen') or A:Tr('Alle versenden'))
  panel.all:SetShown(mode~='list')
  for i,r in ipairs(panel.rows) do
    local o=orders[i]
    r.order=o
    if o then
      local who=o.recipient~='' and o.recipient or o.requester
      local status,_,action,possible=self:OrderRowState(o,stock)
      r.action=action
      if action and possible and (action=='withdraw' or action=='mail') then
        r.text:SetText(('#%d %s: %s'):format(o.id,who,summary(o)))
      else r.text:SetText(('#%d %s (%s)'):format(o.id,who,status or summary(o))) end
      r.go:SetShown(mode~='list' and action~=nil)
      r.go:SetText(self:ActionCaption(action))
      r.go:SetEnabled(possible==true)
      r:Show()
    else r:Hide() end
  end
  local more=#orders-MAX_ROWS
  local live=self.liveNewOrders or 0
  if live>0 and more<=0 then
    panel.note:SetText(A:Tr('%d neue Bestellung(en) im Spiel – sichtbar nach /reload.'):format(live));return
  end
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
  elseif event=='MAIL_SHOW' then A.mailOpen=true;A:ShowManagePanel('mail',MailFrame)
  elseif event=='MAIL_CLOSED' then A.mailOpen=nil;A.mailPrepared,A.mailAll=nil,false;A:HideManagePanel('mail')
  elseif event=='MAIL_SEND_SUCCESS' then
    local id,part=A.mailSending,A.mailPartial
    A.mailSending,A.mailPrepared,A.mailPartial=nil,nil,nil
    if id then
      A:NotifyRecipient(stateOrder(id),part~=nil)
      if part then
        A:MarkOrderPartlySent(id,part)
        A:Message(A:Tr('Teil von Bestellung #%d versendet. Der Rest bleibt offen.'):format(id))
      else
        A:MarkOrderSent(id)
        A:QueueManageAction(id,'deliver')
        store('manageTaken')[id]=nil
        A:Message(A:Tr('Bestellung #%d versendet und als ausgegeben vorgemerkt.'):format(id))
      end
      A:RefreshManagePanel()
      if A.mailAll and C_Timer then C_Timer.After(1.5,function() A:MailNext() end) end
    end
  elseif event=='MAIL_FAILED' then
    if A.mailSending then store('manageSent')[A.mailSending]=nil end
    A.mailSending,A.mailPartial=nil,nil;A.mailAll=false
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
