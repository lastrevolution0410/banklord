-- In-game orders. The cart is queued in BanklordDB.orderOutbox; the
-- companion uploads it with the player's personal token after /reload or logout
-- and writes the server answer to ServerState.lua (read at the next login).
-- Nothing here is authoritative: only the server creates or changes orders.
local A=Banklord
local MAX_OUTBOX=10

A.ORDER_STATUS={
  submitted='Eingereicht – wartet auf Prüfung',
  partial='Teilweise genehmigt',
  reserved='Genehmigt – liegt bereit',
  rejected='Abgelehnt',
  partially_fulfilled='Teilweise ausgegeben',
  fulfilled='Ausgegeben',
  cancelled='Storniert',
}
local STATUS_COLOR={submitted='ffd28c',partial='ffd28c',reserved='5fe88a',partially_fulfilled='5fe88a',
  fulfilled='bdb6a6',rejected='ff7a6a',cancelled='bdb6a6'}
local ERRORS={
  UNKNOWN_ITEM='Gegenstand auf dem Server unbekannt – Inventur abwarten',
  FORBIDDEN='Keine Berechtigung in dieser Gilde',
  INVALID_LINES='Ungültige Positionen',
  QUANTITY_INVALID='Ungültige Menge',
  IDEMPOTENCY_KEY_REUSED='Kennung bereits verwendet',
}

local function randomTag()
  local chars='abcdefghijklmnopqrstuvwxyz0123456789'
  local out={}
  for i=1,6 do local n=math.random(1,#chars);out[i]=chars:sub(n,n) end
  return table.concat(out)
end
local function clean(s,max)
  s=tostring(s or ''):gsub('[%c|]','')
  return s:sub(1,max)
end

function A:SubmitCartOrder()
  if not self.db then return end
  local code,err=self:BuildCartTransferCode()
  if not code then return self:Message(err or A:Tr('Bestellung nicht möglich.')) end
  self.db.orderOutbox=self.db.orderOutbox or {}
  if #self.db.orderOutbox>=MAX_OUTBOX then
    return self:Message(A:Tr('Zu viele Bestellungen warten auf Übertragung. Bitte /reload ausführen und den Companion laufen lassen.'))
  end
  local realm=type(GetRealmName)=='function' and GetRealmName() or ''
  local name=type(UnitName)=='function' and UnitName('player') or ''
  local guild,guildRealm
  if self.CurrentGuild then guild,guildRealm=self:CurrentGuild() end
  table.insert(self.db.orderOutbox,{
    clientKey=('ig-%d-%s'):format(time(),randomTag()),
    code=code,character=clean(name..(realm~='' and '-'..realm:gsub('%s','') or ''),80),note='',createdAt=time(),
    guild=guild,guildRealm=guildRealm})
  if self.BroadcastNewOrder then self:BroadcastNewOrder(name,code) end
  local limit,used=self:WeeklyLimit()
  if limit then
    local pieces=0
    for part in code:sub(5):gmatch('[^|]+') do pieces=pieces+(tonumber(part:match('^%d+,(%d+)')) or 0) end
    if used+pieces>limit then
      self:Message(A:Tr('Hinweis: Damit liegst du über deinem Wochenlimit (%d Stück). Ein Offizier muss die Bestellung freigeben.'):format(limit))
    end
  end
  self.db.localCartDraft={}
  self:Message(A:Tr('Bestellung vorgemerkt. Der Companion überträgt sie nach /reload oder Logout; der Status erscheint danach hier.'))
  if self.RefreshUI then self:RefreshUI() end
end

-- Called once after SavedVariables are loaded: drop queued orders the server has answered.
function A:ReconcileOrderOutbox()
  local st=Banklord_ServerState
  if not self.db or type(st)~='table' then return end
  local known={}
  for _,o in ipairs(type(st.orders)=='table' and st.orders or {}) do
    if type(o)=='table' and type(o.clientKey)=='string' then known[o.clientKey]=o end
  end
  local results=type(st.outbox)=='table' and st.outbox or {}
  self.db.orderFailures=self.db.orderFailures or {}
  local outbox=self.db.orderOutbox or {}
  for i=#outbox,1,-1 do
    local e=outbox[i]
    local key=type(e)=='table' and e.clientKey
    local r=key and results[key]
    if not key then table.remove(outbox,i)
    elseif known[key] or (type(r)=='table' and r.orderId) then table.remove(outbox,i)
    elseif type(r)=='table' and type(r.error)=='string' then
      table.insert(self.db.orderFailures,1,{clientKey=key,error=r.error,createdAt=e.createdAt})
      while #self.db.orderFailures>5 do table.remove(self.db.orderFailures) end
      table.remove(outbox,i)
    end
  end
  self.db.orderOutbox=outbox
end

function A:DismissOrderFailures()
  if self.db then self.db.orderFailures={} end
end

-- Rows for the "Meine Bestellungen" box: pending, failed, then server orders.
function A:GetOrderOverview()
  local rows={}
  for _,e in ipairs(self.db and self.db.orderOutbox or {}) do
    rows[#rows+1]={title=A:Tr('Neu · %s'):format(e.createdAt and date(A:Tr('%d.%m. %H:%M'),e.createdAt) or ''),
      status='|cffffaa44'..A:Tr('Wartet auf Übertragung (/reload)')..'|r',code=e.code}
  end
  for _,f in ipairs(self.db and self.db.orderFailures or {}) do
    rows[#rows+1]={title=A:Tr('Nicht angenommen'),status='|cffff7a6a'..(ERRORS[f.error] and A:Tr(ERRORS[f.error]) or f.error or A:Tr('Fehler'))..'|r',failure=true}
  end
  local st=Banklord_ServerState
  local generated=type(st)=='table' and tonumber(st.generatedAt) or 0
  local shipped=self.db and self.db.orderShipped or {}
  local cancelling=self.db and self.db.orderCancelPending or {}
  for _,o in ipairs(type(st)=='table' and type(st.orders)=='table' and st.orders or {}) do
    if type(o)=='table' and (not A.SameGuild or A.SameGuild(o)) then
      local color=STATUS_COLOR[o.status] or 'ffffff'
      local text=A.ORDER_STATUS[o.status] and A:Tr(A.ORDER_STATUS[o.status]) or tostring(o.status)
      local ship=shipped[o.id]
      if cancelling[o.id] and A.IsCancellable and o.status~='cancelled' and o.status~='fulfilled' then
        color,text='ffaa44',A:Tr('Storno vorgemerkt (/reload)')
      elseif type(ship)=='table' and (ship.at or 0)>generated and o.status~='fulfilled' then
        color,text='5fe88a',ship.part and A:Tr('Teil unterwegs – Briefkasten prüfen') or A:Tr('Unterwegs – Briefkasten prüfen')
      end
      rows[#rows+1]={title='#'..tostring(o.id)..' · '..(o.createdAt and date(A:Tr('%d.%m. %H:%M'),o.createdAt) or ''),
        status='|cff'..color..text..'|r',order=o}
    end
  end
  local stamp=type(st)=='table' and tonumber(st.generatedAt)
  return rows,stamp and date(A:Tr('%d.%m.%Y %H:%M'),stamp) or nil
end

function A:ShowOrderTooltip(owner,row)
  if not row or type(GameTooltip)~='table' then return end
  GameTooltip:SetOwner(owner,'ANCHOR_LEFT')
  GameTooltip:AddLine(row.title,1,.84,.46)
  GameTooltip:AddLine(row.status,1,1,1,true)
  if row.order then
    for _,l in ipairs(type(row.order.lines)=='table' and row.order.lines or {}) do
      local detail=A:Tr('%d bestellt'):format(l.requested or 0)
      if (l.approved or 0)>0 then detail=detail..A:Tr(' · %d genehmigt'):format(l.approved) end
      if (l.fulfilled or 0)>0 then detail=detail..A:Tr(' · %d ausgegeben'):format(l.fulfilled) end
      GameTooltip:AddDoubleLine(l.name or A:Tr('Item %s'):format(tostring(l.itemId)),detail,.98,.97,.92,.87,.84,.78)
    end
  elseif row.code then
    for part in tostring(row.code):sub(5):gmatch('[^|]+') do
      local id,qty=part:match('^(%d+),(%d+),')
      local name=id and type(GetItemInfo)=='function' and GetItemInfo(tonumber(id)) or A:Tr('Item %s'):format(tostring(id))
      GameTooltip:AddDoubleLine(name,A:Tr('%s Stück'):format(qty or '?'),.98,.97,.92,.87,.84,.78)
    end
    if A.IsCancellable and A:IsCancellable(row.order) then
      GameTooltip:AddLine(A:Tr('Rechtsklick: Bestellung stornieren.'),.87,.84,.78,true)
    end
  elseif row.failure then
    GameTooltip:AddLine(A:Tr('Rechtsklick in der Liste entfernt die Meldungen.'),.87,.84,.78,true)
  end
  GameTooltip:Show()
end

-- Weekly piece limit of this player's role (guild rule), or nil when unlimited.
-- used also counts orders still waiting for upload.
function A:WeeklyLimit()
  local r=self.GuildRules and self:GuildRules()
  local limit=r and tonumber(r.weeklyLimit) or 0
  if limit<=0 then return nil end
  local used=tonumber(r.weeklyUsed) or 0
  for _,e in ipairs(self.db and self.db.orderOutbox or {}) do
    for part in tostring(e.code or ''):sub(5):gmatch('[^|]+') do used=used+(tonumber(part:match('^%d+,(%d+)')) or 0) end
  end
  return limit,used
end
