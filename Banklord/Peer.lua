-- Banklord MoP Classic: offline-capable, durable LOCAL outbox for peer DRAFTS.
-- This does NOT provide HTTP, guaranteed peer delivery, or server order confirmation.
local A=Banklord
local PREFIX='WGBSYNC1'
local PART=150
local MAX_PARTS=30
local MAX_PENDING=50
local MAX_PEER_ROWS=12 -- Compatible with the deployed 0.5.1 PHP peer importer.
local RETRY_SECONDS=120
local TICK_SECONDS=15
A.peerParts={}
local function now() return (time and time()) or 0 end
local function safeName(s)
  return type(s)=='string' and #s>0 and #s<=120 and not s:find('[|%c%s]')
end
local function peerNameEquals(a,b)
  return type(a)=='string' and type(b)=='string' and a:lower()==b:lower()
end
local function validTransfer(s)
  if type(s)~='string' or #s<12 or #s>4000 or s:sub(1,5)~='WGB1|' then return false end
  local count=0
  for part in (s:sub(6)..'|'):gmatch('(.-)|') do
    if part=='' then return false end
    local id,quantity,key=part:match('^(%d+),(%d+),(item:[%d:%-]+)$')
    id=tonumber(id);quantity=tonumber(quantity)
    if not id or id<1 or id>4294967295 or not quantity or quantity<1 or quantity>1000000 or
       not key or key:sub(1,#tostring(id)+6)~='item:'..tostring(id)..':' then return false end
    count=count+1
    if count>50 then return false end
  end
  return count>0
end
local function send(target,payload)
  if C_ChatInfo and type(C_ChatInfo.SendAddonMessage)=='function' then
    local ok,value=pcall(C_ChatInfo.SendAddonMessage,PREFIX,payload,'WHISPER',target)
    return ok and value~=false and (type(value)~='number' or value==0)
  elseif type(SendAddonMessage)=='function' then
    local ok,value=pcall(SendAddonMessage,PREFIX,payload,'WHISPER',target)
    return ok and value~=false
  end
  return false
end
local function outbox(self)
  if not self.db then return nil end
  if type(self.db.peerOutbox)~='table' then self.db.peerOutbox={} end
  return self.db.peerOutbox
end
local function sendQueued(self,row)
  if row.status=='peer_ack' or row.status=='cancelled' or row.sending then return false end
  if not safeName(row.target) or not validTransfer(row.code) then
    row.status='invalid';return false
  end
  if not self.peerEnabled then return false end
  local total=math.ceil(#row.code/PART)
  if total>MAX_PARTS then row.status='invalid';return false end
  row.sending=true
  row.status='transmitting'
  row.attempts=(tonumber(row.attempts) or 0)+1
  row.lastAttempt=now()
  for index=1,total do
    local chunk=row.code:sub((index-1)*PART+1,index*PART)
    local packet='D|'..row.messageId..'|'..index..'|'..total..'|'..chunk
    local function transmit()
      -- Timers from an old attempt must not send if status changed.
      if row.status~='transmitting' or not row.sending then return end
      local accepted=send(row.target,packet)
      if not accepted then
        row.status='retry_pending';row.sending=false
        self:Message(A:Tr('Entwurf an %s zurzeit nicht zustellbar. Bleibt lokal vorgemerkt.'):format(row.target))
      elseif index==total then
        row.status='waiting_peer_ack';row.sending=false
      end
    end
    if C_Timer and type(C_Timer.After)=='function' then C_Timer.After((index-1)*1.25,transmit)
    else transmit() end
  end
  return true
end
function A:RetryPendingPeers(force)
  local pending=outbox(self)
  if not pending or not self.peerEnabled then return end
  for _,row in ipairs(pending) do
    if row.status~='peer_ack' and row.status~='cancelled' and row.status~='invalid' and not row.sending then
      if force or now()-(tonumber(row.lastAttempt) or 0)>=RETRY_SECONDS then
        if sendQueued(self,row) then break end -- One request per tick, avoid throttling.
      end
    end
  end
end
function A:InitPeer()
  local ok=false
  if C_ChatInfo and type(C_ChatInfo.RegisterAddonMessagePrefix)=='function' then
    ok=pcall(C_ChatInfo.RegisterAddonMessagePrefix,PREFIX)
  elseif type(RegisterAddonMessagePrefix)=='function' then
    ok=pcall(RegisterAddonMessagePrefix,PREFIX)
  end
  self.peerEnabled=ok
  if not ok then self:Message(A:Tr('Ingame-Kommunikation nicht verfügbar; Entwürfe bleiben lokal.')) end
  -- Initial retry AFTER login; and periodic bounded retry while WoW is running.
  -- Does not rely on a permanent online bank administrator.
  if C_Timer and type(C_Timer.After)=='function' then
    C_Timer.After(10,function()if A.db then A:RetryPendingPeers(true) end end)
    local function tick()
      if A.db then A:RetryPendingPeers(false) end
      C_Timer.After(TICK_SECONDS,tick)
    end
    C_Timer.After(TICK_SECONDS,tick)
  end
end
function A:SendCartToPeer(target)
  target=tostring(target or ''):match('^%s*(.-)%s*$')
  if not safeName(target) then return self:Message(A:Tr('Empfänger ungültig. Syntax: /banklord senden Charakter-Realm')) end
  local transfer,reason=self:BuildCartTransferCode()
  if not transfer then return self:Message(reason or A:Tr('Warenkorb leer.')) end
  if not validTransfer(transfer) then return self:Message(A:Tr('Warenkorbcode ungültig.')) end
  local rows=outbox(self)
  if not rows then return self:Message(A:Tr('Lokaler Speicher noch nicht geladen.')) end
  local active=0
  for _,row in ipairs(rows) do if row.status~='peer_ack' and row.status~='cancelled' then active=active+1 end end
  if active>=MAX_PENDING then return self:Message(A:Tr('Ausgang voll. Bitte offene Entwürfe prüfen.')) end
  self.db.peerSequence=((tonumber(self.db.peerSequence) or 0)%999999)+1
  local id=tostring(now())..'-'..string.format('%06d',self.db.peerSequence)
  local row={messageId=id,target=target,code=transfer,createdAt=now(),status='pending',attempts=0,lastAttempt=0}
  table.insert(rows,row)
  self:Message(A:Tr('Entwurf %s lokal vorgemerkt. KEINE Serverbestellung. Automatische Ingame-Zustellversuche laufen.'):format(id))
  self:RetryPendingPeers(true)
end
function A:ReceivePeerMessage(prefix,payload,channel,sender)
  if prefix~=PREFIX or channel~='WHISPER' or not safeName(sender) or type(payload)~='string' or #payload>255 then return end
  local kind,id,rest=payload:match('^(%u)|([%d%-]+)|(.+)$')
  if not id or not id:match('^%d+%-%d%d%d%d%d%d$') then return end
  if kind=='A' and rest=='OK' then
    for _,row in ipairs(outbox(self) or {}) do
      if row.messageId==id and peerNameEquals(row.target,sender) and row.status~='cancelled' then
        row.status='peer_ack';row.sending=false;row.receivedByPeerAt=now()
        self:Message(A:Tr('Ingame-Empfang durch %s bestätigt. Noch NICHT auf der Website eingegangen.'):format(sender))
      end
    end
    return
  end
  if kind~='D' then return end
  local seq,total,chunk=rest:match('^(%d+)|(%d+)|(.+)$')
  seq=tonumber(seq);total=tonumber(total)
  if not seq or not total or total<1 or total>MAX_PARTS or seq<1 or seq>total or #chunk>PART then return end
  local key=sender..':'..id
  self.peerParts=self.peerParts or {}
  local state=self.peerParts[key]
  if state and (state.total~=total or now()-state.started>180) then self.peerParts[key]=nil;state=nil end
  if not state then
    local count=0;for _ in pairs(self.peerParts) do count=count+1 end
    if count>=12 then self.peerParts={} end
    state={total=total,parts={},started=now(),received=0};self.peerParts[key]=state
  end
  if not state.parts[seq] then state.parts[seq]=chunk;state.received=state.received+1 end
  if state.received~=total then return end
  self.peerParts[key]=nil
  local code=table.concat(state.parts)
  if not validTransfer(code) then return end
  self.db.peerInbox=self.db.peerInbox or {}
  for _,existing in ipairs(self.db.peerInbox) do
    if existing.sender==sender and existing.messageId==id then send(sender,'A|'..id..'|OK');return end
  end
  if #self.db.peerInbox>=MAX_PEER_ROWS then
    -- Crucial: DO NOT ACK, and never evict an unprocessed message.
    self:Message(A:Tr('Ingame-Eingang voll; neuer Entwurf NICHT angenommen. Bitte zuerst Eingänge im Web prüfen.'))
    return
  end
  table.insert(self.db.peerInbox,{sender=sender,messageId=id,code=code,receivedAt=now(),status='unverified_draft'})
  send(sender,'A|'..id..'|OK')
  self:Message(A:Tr('Ungeprüfter Entwurf von %s gespeichert. Noch keine Webbestellung!'):format(sender))
end
function A:ShowPeerInbox()
  local rows=self.db and self.db.peerInbox or {}
  self:Message(A:Tr('Ingame-Eingang: %d von %d Entwürfen; Webannahme UNBESTÄTIGT.'):format(#rows,MAX_PEER_ROWS))
  for n=math.max(1,#rows-9),#rows do
    local r=rows[n]
    if r then self:Message(A:Tr('%d · %s · %s · %d Zeichen'):format(n,tostring(r.sender),tostring(r.messageId),#tostring(r.code or ''))) end
  end
end
function A:ShowPeerOutbox()
  local rows=outbox(self) or {};local n=0
  for _,r in ipairs(rows) do
    if r.status~='cancelled' then
      n=n+1
      self:Message(A:Tr('%s · %s · %s · Versuche %s'):format(tostring(r.messageId),tostring(r.target),tostring(r.status),tostring(r.attempts)))
    end
  end
  if n==0 then self:Message(A:Tr('Keine gespeicherten Ingame-Sendeaufträge.')) end
  self:Message(A:Tr('Peer-ACK bedeutet nicht Serverannahme. Bei fehlender Verbindung: direkt im Web bestellen.'))
end
