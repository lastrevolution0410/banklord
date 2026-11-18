-- Help & feedback in game: bug reports, ideas and language requests.
-- Reports wait in BanklordDB.feedbackOutbox; the companion uploads them after
-- /reload and the guild website forwards them to the Banklord project.
-- Status and answers come back in Banklord_ServerState.feedback.
local A=Banklord
local MAX_PENDING=5

A.FeedbackKinds={
  {key='bug',caption='Fehler melden',hint='Etwas funktioniert nicht wie erwartet.'},
  {key='idea',caption='Idee vorschlagen',hint='Neue Funktion oder Verbesserung.'},
  {key='language',caption='Sprache anfragen',hint='Banklord in einer weiteren Sprache.'},
}
A.FeedbackAreas={
  {key='addon',caption='Addon im Spiel'},{key='web',caption='Website'},
  {key='companion',caption='Companion'},{key='setup',caption='Setup oder Updates'},{key='unknown',caption='Weiß nicht'},
}
A.FeedbackHelp={{key='no',caption='Nein'},{key='maybe',caption='Vielleicht'},{key='yes',caption='Ja'}}
A.FeedbackStatus={new='Eingegangen',seen='Gesehen',planned='Geplant',done='Erledigt',rejected='Abgelehnt',
  duplicate='Schon gemeldet',pending='Wird gesendet',queued='Wartet auf /reload'}

local function trim(s) return (tostring(s or ''):gsub('^%s+',''):gsub('%s+$','')) end
local function clean(s,max,multiline)
  s=trim(s):gsub('|','/')
  s=multiline and s:gsub('[%z\1-\9\11-\31]','') or s:gsub('[%z\1-\31]','')
  if #s>max then s=s:sub(1,max) end
  return s
end
local function randomTag()
  local s='';for _=1,6 do local n=math.random(0,35);s=s..(n<10 and tostring(n) or string.char(87+n)) end;return s
end

-- Returns true, or nil plus the reason shown to the player.
function A:QueueFeedback(kind,f)
  if not self.db then return nil,A:Tr('Banklord ist noch nicht geladen.') end
  local box=self.db.feedbackOutbox or {}
  self.db.feedbackOutbox=box
  if #box>=MAX_PENDING then return nil,A:Tr('Es warten schon %d Meldungen. Bitte erst /reload ausführen.'):format(#box) end
  local row={kind=kind,area=f.area or 'unknown',title=clean(f.title,120),text=clean(f.text,2000,true)}
  if kind=='language' then
    row.language=clean(f.language,60);row.help=f.help or 'no'
    if #row.language<2 then return nil,A:Tr('Bitte die gewünschte Sprache angeben.') end
    if row.title=='' then row.title=A:Tr('Sprache: %s'):format(row.language) end
    if #row.text<10 then row.text=A:Tr('Sprachwunsch: %s.'):format(row.language)..(row.text~='' and ' '..row.text or '') end
  elseif kind~='bug' and kind~='idea' then return nil,'?' end
  if #row.title<4 then return nil,A:Tr('Bitte einen kurzen Titel angeben (mindestens 4 Zeichen).') end
  if #row.text<10 then return nil,A:Tr('Bitte etwas genauer beschreiben (mindestens 10 Zeichen).') end
  row.key=('fb-%d-%s'):format(time(),randomTag())
  row.addon=tostring(A.version or '')
  if GetBuildInfo then local v,b=GetBuildInfo();row.build=tostring(v or '')..(b and ' ('..tostring(b)..')' or '') end
  row.lang=GetLocale and GetLocale() or ''
  row.createdAt=time()
  box[#box+1]=row
  return true
end

-- After login: drop reports the website has taken over, report rejected ones.
function A:ReconcileFeedback()
  if not self.db then return end
  local st=type(Banklord_ServerState)=='table' and Banklord_ServerState or {}
  local known={}
  for _,r in ipairs(type(st.feedback)=='table' and st.feedback or {}) do if type(r)=='table' and r.key then known[r.key]=true end end
  local results=type(st.feedbackResults)=='table' and st.feedbackResults or {}
  local box,keep=self.db.feedbackOutbox or {},{}
  for _,row in ipairs(box) do
    local r=results[row.key]
    if type(r)=='table' and r.status=='invalid' then
      self:Message(A:Tr('Meldung „%s“ wurde nicht angenommen: %s'):format(row.title or '?',A:TrReason(r.error or '?')))
    elseif not known[row.key] or (type(r)=='table' and r.status=='pending') then keep[#keep+1]=row end
  end
  self.db.feedbackOutbox=keep
end

-- Rows for "Meine Meldungen": waiting ones first, then the website's list.
function A:FeedbackOverview()
  local rows={}
  for i=#(self.db and self.db.feedbackOutbox or {}),1,-1 do
    local r=self.db.feedbackOutbox[i]
    rows[#rows+1]={kind=r.kind,title=r.title,status='queued',reply=''}
  end
  local st=type(Banklord_ServerState)=='table' and Banklord_ServerState or {}
  local waiting={}
  for _,r in ipairs(self.db and self.db.feedbackOutbox or {}) do waiting[r.key]=true end
  for _,r in ipairs(type(st.feedback)=='table' and st.feedback or {}) do
    if type(r)=='table' and not waiting[r.key or ''] then
      rows[#rows+1]={kind=r.kind,title=r.title,status=r.status,reply=r.reply or '',number=r.number}
    end
  end
  return rows
end
