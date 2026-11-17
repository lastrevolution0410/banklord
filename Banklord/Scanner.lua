-- Banklord - 0.1.3-dev. Read-only multi-tab scanner for MoP Classic.
-- No reliance on Baganator (or any other inventory addon).
-- GUILDBANKBAGSLOTS_CHANGED does not identify the responding tab.
-- Therefore ALL results are locally UNVERIFIED until a trusted reconciliation step.
local A = Banklord
local SLOTS_PER_TAB = MAX_GUILDBANK_SLOTS_PER_TAB or 98
local EVENT_SETTLE_SECONDS = 0.30
local READ_STABILITY_SECONDS = 0.20
local RETRY_DELAY_SECONDS = 0.50
local TAB_TIMEOUT_SECONDS = 8
local MAX_TAB_RETRIES = 1
local MAX_STORED_RUNS = 3

local function Schedule(seconds, fn)
  if C_Timer and type(C_Timer.After) == "function" then
    C_Timer.After(seconds, fn)
    return true
  end
  return false
end

local function Viewable(value) return value == true or value == 1 end

local function GetTabDetails(tab)
  if type(GetGuildBankTabInfo) ~= "function" then return nil, false, "API-missing" end
  local ok, name, _, viewable = pcall(GetGuildBankTabInfo, tab)
  if not ok then return nil, false, "tab-info-error" end
  return name or ("Fach " .. tab), Viewable(viewable), nil
end

local function ScanSlotData(tab)
  local items, signature = {}, {}
  local uncertain = false
  for slot = 1, SLOTS_PER_TAB do
    local okInfo, texture, count = pcall(GetGuildBankItemInfo, tab, slot)
    local okLink, link = pcall(GetGuildBankItemLink, tab, slot)
    if not okInfo or not okLink then
      uncertain = true
      signature[#signature + 1] = "error:" .. slot
    else
      if (texture and not link) or (link and not texture) or
         (link and (type(count) ~= "number" or count < 1)) or
         (not link and type(count) == "number" and count > 0) then
        uncertain = true
      end
      if link and type(count) == "number" and count >= 1 then
        local itemID = tonumber(string.match(link, "item:(%d+)"))
        local itemKey = string.match(link, "|H(item:[^|]+)|h") or
                        string.match(link, "(item:[%d:%-]+)")
        if not itemID or not itemKey then uncertain = true end
        items[#items + 1] = {
          slot = slot, itemID = itemID, itemKey = itemKey,
          itemLink = link, count = count,
          -- Read-only bank-slot texture; matches the original WoW bank tile.
          iconTexture = (type(texture)=="number" and texture>=1 and texture<=4294967295) and math.floor(texture)
             or ((type(texture)=="string" and #texture<=140 and texture:match("^[%w_\\/%.%-]+$")) and texture or nil),
        }
        signature[#signature + 1] = table.concat({slot, tostring(link), tostring(count)}, ":")
      end
    end
  end
  return {items = items, signature = table.concat(signature, ";"), uncertain = uncertain}
end

local function Aggregate(tabs)
  local byKey, keys, slots, quantity = {}, {}, 0, 0
  for _, tab in pairs(tabs) do
    if tab.status == "captured" then
      for _, entry in ipairs(tab.items) do
        local key = entry.itemKey or ("link:" .. entry.itemLink)
        local aggregated = byKey[key]
        if not aggregated then
          aggregated = {itemKey = key, itemID = entry.itemID,
            itemLink = entry.itemLink, iconTexture = entry.iconTexture,
            iconConflict = false, count = 0, stacks = 0}
          byKey[key] = aggregated
          keys[#keys + 1] = key
        end
        -- If bank slots for one variant disagree, avoid randomly choosing
        -- the last observed image. Keep the first captured source and flag it.
        if aggregated.iconTexture ~= entry.iconTexture then
          aggregated.iconConflict = true
          if not aggregated.iconTexture then aggregated.iconTexture = entry.iconTexture end
        end
        aggregated.count = aggregated.count + entry.count
        aggregated.stacks = aggregated.stacks + 1
        slots = slots + 1
        quantity = quantity + entry.count
      end
    end
  end
  table.sort(keys)
  local items = {}
  for _, key in ipairs(keys) do items[#items + 1] = byKey[key] end
  return items, slots, quantity
end

function A:RefreshScanProgress()
  if self.RefreshUI then self:RefreshUI() end
end

function A:AbortScan(reason)
  local run = self.scanRun
  if not run then return end
  self.scanRun = nil -- invalidate ALL queued timeouts and event-debounce callbacks
  self.pendingTab = nil
  self.db.lastScanAttempt = {
    capturedAt = time(), status = "aborted", reason = reason,
    finished = run.completed, eligible = #run.queue,
  }
  self:Message("Gesamtscan abgebrochen: " .. tostring(reason) ..
    ". Keine neuen Daten als Gesamtbestand gespeichert.")
  self:RefreshScanProgress()
end

function A:_FinishScan()
  local run = self.scanRun
  if not run then return end
  self.scanRun = nil
  self.pendingTab = nil
  -- Don't override a tab that the user selected themselves during the scan.
  if type(SetCurrentGuildBankTab) == "function" and
     type(GetCurrentGuildBankTab) == "function" and
     run.originalTab and run.originalTab >= 1 and run.currentTab and
     GetCurrentGuildBankTab() == run.currentTab then
    pcall(SetCurrentGuildBankTab, run.originalTab)
  end

  local result = {
    formatVersion = 2, scanStartedAt = run.startedAt, capturedAt = time(), locale = (type(GetLocale)=="function" and GetLocale() or "deDE"), realm = run.realm,
    guild = run.guild, character = run.character, mode = run.mode,
    totalTabs = run.totalTabs, eligibleTabs = #run.queue,
    tabs = run.tabs, errors = run.errors,
    source = "mop-classic-guildbank", serverConfirmed = false,
  }
  result.items, result.occupiedSlots, result.totalItemCount = Aggregate(run.tabs)
  result.scannedTabs = run.completed
  result.skippedTabs = run.skipped
  result.failedTabs = #run.errors
  result.quality = (run.completed > 0 and run.completed == #run.queue and
                    run.skipped == 0 and #run.errors == 0 and not run.uncertain)
                    and "unverified" or "partial"
  if run.completed == 0 then result.quality = "failed" end

  self.db.lastScanAttempt = {
    capturedAt = result.capturedAt, status = result.quality,
    finished = result.scannedTabs, eligible = result.eligibleTabs,
    failed = result.failedTabs, skipped = result.skippedTabs,
  }
  if run.completed > 0 then
    self.db.fullScans = self.db.fullScans or {}
    -- The bank snapshot history stays intact; previously captured optional
    -- metadata is dropped to keep SavedVariables below the 4 MiB importer cap.
    for _, previous in ipairs(self.db.fullScans) do
      previous.itemMetadata = nil
      previous.metadataCapturedAt = nil
    end
    -- Snapshot replacement, not addition. Retain only the 3 most recent attempts.
    table.insert(self.db.fullScans, result)
    while #self.db.fullScans > MAX_STORED_RUNS do table.remove(self.db.fullScans, 1) end
    self:Message("Scan beendet: " .. result.scannedTabs .. "/" .. result.eligibleTabs ..
      " lesbare Fächer, " .. result.occupiedSlots .. " belegte Slots, " ..
      #result.items .. " unterschiedliche Item-Varianten. Status=" .. result.quality .. ".")
    self:Message("Lokal erfasst. Companion überträgt automatisch nach /reload oder Logout.")
  else
    self:Message("Scan fehlgeschlagen: keine vollständigen Fach-Daten empfangen. Alter Snapshot bleibt bestehen.")
  end
  if run.completed > 0 and self.EnrichSnapshotWithClientMetadata then
    self:EnrichSnapshotWithClientMetadata(result)
  end
  self:RefreshScanProgress()
end

function A:_ScanNextTab()
  local run = self.scanRun
  if not run then return end
  if not self:IsGuildBankOpen() then return self:AbortScan("Gildenbank geschlossen") end
  run.cursor = run.cursor + 1
  local tab = run.queue[run.cursor]
  if not tab then return self:_FinishScan() end
  run.currentTab = tab
  run.retries = 0
  self:_RequestTab()
end

function A:_TabFailed(reason)
  local run = self.scanRun
  if not run then return end
  local tab = run.currentTab
  run.waiting = false
  run.token = run.token + 1 -- invalidate late callbacks for this failed request
  run.errors[#run.errors + 1] = {tab = tab, reason = reason}
  run.tabs[tab] = {tab = tab, tabName = (GetTabDetails(tab)), status = "failed", reason = reason}
  self:Message("Fach " .. tab .. " konnte nicht verlässlich erfasst werden (" .. reason .. ").")
  local id = run.id
  if not Schedule(RETRY_DELAY_SECONDS, function()
    if self.scanRun and self.scanRun.id == id then self:_ScanNextTab() end
  end) then self:AbortScan("C_Timer.After nicht verfügbar") end
end

function A:_RequestTab()
  local run = self.scanRun
  if not run then return end
  if not self:IsGuildBankOpen() then return self:AbortScan("Gildenbank geschlossen") end
  local tab = run.currentTab
  local tabName, canView, infoError = GetTabDetails(tab)
  if infoError then return self:_TabFailed(infoError) end
  if not canView then return self:_TabFailed("Leserecht inzwischen entfallen") end
  if type(QueryGuildBankTab) ~= "function" or type(GetGuildBankItemInfo) ~= "function" or
     type(GetGuildBankItemLink) ~= "function" then
    return self:_TabFailed("Blizzard-Bank-API fehlt")
  end
  if type(GetCurrentGuildBankTab) ~= "function" or type(SetCurrentGuildBankTab) ~= "function" then
    return self:_TabFailed("Fachauswahl-API fehlt")
  end
  local okSelect, selectError = pcall(SetCurrentGuildBankTab, tab)
  if not okSelect then return self:_TabFailed("Fachauswahl fehlgeschlagen: " .. tostring(selectError)) end
  if GetCurrentGuildBankTab() ~= tab then
    return self:_TabFailed("Fachauswahl weicht ab (möglicher UI-/Addon-Konflikt)")
  end
  run.token = run.token + 1
  local token, runId = run.token, run.id
  run.waiting = true
  run.settleSerial = 0
  run.retries = run.retries + 1
  self.pendingTab = tab
  self:Message("Lese Fach " .. run.cursor .. "/" .. #run.queue .. ": " ..
    tabName .. " (#" .. tab .. "; Versuch " .. run.retries .. ").")
  self:RefreshScanProgress()
  local ok, err = pcall(QueryGuildBankTab, tab)
  if not ok then return self:_TabFailed("Bankabfrage fehlgeschlagen: " .. tostring(err)) end
  if not Schedule(TAB_TIMEOUT_SECONDS, function()
    local current = self.scanRun
    if not current or current.id ~= runId or current.token ~= token or not current.waiting then return end
    if current.retries <= MAX_TAB_RETRIES then
      self:Message("Fach " .. tab .. ": keine eindeutige Datenaktualisierung; erneuter Versuch.")
      self:_RequestTab()
    else
      self:_TabFailed("Timeout ohne bestätigtes Bankereignis")
    end
  end) then self:AbortScan("C_Timer.After nicht verfügbar") end
end

function A:ScanEvent()
  local run = self.scanRun
  if not run or not run.waiting or not self.db or not self:IsGuildBankOpen() then return end
  if GetCurrentGuildBankTab() ~= run.currentTab then
    -- Some other UI changed the selected tab; cannot attribute an untagged event.
    return self:AbortScan("Bankfach während des Scans gewechselt; Zuordnung unsicher")
  end
  -- Changes have no tab argument. Only attribute to the tab we just requested,
  -- then debounce and require the observed contents to stabilize.
  run.settleSerial = run.settleSerial + 1
  local serial, runId, token = run.settleSerial, run.id, run.token
  if not Schedule(EVENT_SETTLE_SECONDS, function()
    local r = self.scanRun
    if not r or r.id ~= runId or r.token ~= token or
       r.settleSerial ~= serial or not r.waiting then return end
    if not self:IsGuildBankOpen() then return self:AbortScan("Gildenbank geschlossen") end
    if GetCurrentGuildBankTab() ~= r.currentTab then
      return self:AbortScan("Bankfach während des Scans gewechselt")
    end
    local first = ScanSlotData(r.currentTab)
    if not Schedule(READ_STABILITY_SECONDS, function()
      local rr = self.scanRun
      if not rr or rr.id ~= runId or rr.token ~= token or
         rr.settleSerial ~= serial or not rr.waiting then return end
      if not self:IsGuildBankOpen() then return self:AbortScan("Gildenbank geschlossen") end
      if GetCurrentGuildBankTab() ~= rr.currentTab then
        return self:AbortScan("Bankfach während des Scans gewechselt")
      end
      local second = ScanSlotData(rr.currentTab)
      if first.signature ~= second.signature then
        -- Wait for another update or timeout/retry, never save shifting data.
        self:Message("Fach " .. rr.currentTab .. ": Daten noch in Änderung, warte weiter.")
        return
      end
      local tab = rr.currentTab
      local tabName, canView = GetTabDetails(tab)
      if not canView then return self:_TabFailed("Leserecht inzwischen entfallen") end
      rr.waiting = false
      self.pendingTab = nil
      rr.completed = rr.completed + 1
      if second.uncertain then rr.uncertain = true end
      rr.tabs[tab] = {
        tab = tab, tabName = tabName, expectedSlots = SLOTS_PER_TAB,
        capturedAt = time(), status = "captured",
        quality = second.uncertain and "partial" or "unverified",
        items = second.items,
      }
      self:Message("Fach " .. tab .. ": " .. #second.items .. " belegte Slots (" ..
        rr.tabs[tab].quality .. ").")
      if not Schedule(RETRY_DELAY_SECONDS, function()
        if self.scanRun and self.scanRun.id == runId then self:_ScanNextTab() end
      end) then self:AbortScan("C_Timer.After nicht verfügbar") end
    end) then self:AbortScan("C_Timer.After nicht verfügbar") end
  end) then self:AbortScan("C_Timer.After nicht verfügbar") end
end

function A:StartScan(mode)
  mode = mode or "all"
  if self.scanRun then return self:Message("Ein Scan läuft bereits. /banklord stop zum Abbrechen.") end
  if not self.db then return self:Message("Datenbank noch nicht bereit.") end
  if not self:IsGuildBankOpen() then return self:Message("Bitte zuerst die Gildenbank öffnen. /banklord debug") end
  local guild = GetGuildInfo and GetGuildInfo("player")
  local realm = GetRealmName and GetRealmName()
  if not guild or guild == "" or not realm or realm == "" then
    return self:Message("Gilden- oder Realmzuordnung fehlt; Scan nicht gestartet.")
  end
  local count = GetNumGuildBankTabs and GetNumGuildBankTabs()
  if type(count) ~= "number" or count < 1 or count > 16 then
    return self:Message("Anzahl der Bankfächer ist ungültig/nicht verfügbar: " .. tostring(count))
  end
  local selected = GetCurrentGuildBankTab and GetCurrentGuildBankTab()
  local queue, skipped = {}, 0
  local first, last = 1, count
  if mode == "current" then
    if not selected or selected < 1 or selected > count then
      return self:Message("Bitte ein gültiges Bankfach auswählen.")
    end
    first, last = selected, selected
  end
  for tab = first, last do
    local _, viewable, infoError = GetTabDetails(tab)
    if viewable then queue[#queue + 1] = tab else skipped = skipped + 1 end
    if infoError then self:Message("Fach " .. tab .. ": Informationen fehlen (" .. infoError .. ").") end
  end
  if #queue == 0 then return self:Message("Keine lesbaren Bankfächer gefunden.") end
  if not C_Timer or type(C_Timer.After) ~= "function" then
    return self:Message("Dieser Client bietet keinen geeigneten Timer für sichere Scans.")
  end
  self.scanIdCounter = (self.scanIdCounter or 0) + 1
  self.scanRun = {
    id = self.scanIdCounter, token = 0, cursor = 0, queue = queue,
    totalTabs = count, skipped = skipped, completed = 0,
    uncertain = false, tabs = {}, errors = {}, guild = guild,
    realm = realm, character = UnitName("player"),
    originalTab = selected, mode = mode, startedAt = time(),
  }
  self:Message("Starte " .. (mode == "current" and "Einzelfachscan" or "Gesamtscan") ..
    ": " .. #queue .. " lesbare Fächer, " .. skipped .. " übersprungen.")
  self:_ScanNextTab()
end

function A:StartSingleScan() self:StartScan("current") end

function A:Status()
  if not self.db then return self:Message("Nicht geladen.") end
  local run = self.scanRun
  if run then
    self:Message("Gesamtscan läuft: " .. run.completed .. "/" .. #run.queue ..
      " fertig; aktuelles Fach " .. tostring(run.currentTab) .. ".")
  end
  local list = self.db.fullScans or {}
  self:Message("Gesamtscan-Snapshots lokal: " .. #list .. " (max. " .. MAX_STORED_RUNS .. ")")
  local latest = list[#list]
  if latest then
    self:Message("Letzter Snapshot: " .. latest.guild .. " / " .. latest.scannedTabs ..
      "/" .. latest.eligibleTabs .. " Fächer / " .. latest.occupiedSlots ..
      " Slots / " .. #latest.items .. " Varianten / " .. latest.quality .. ".")
  end
  local legacy = self.db.scans or {}
  if #legacy > 0 then self:Message("Vorherige Einzeltest-Scans: " .. #legacy) end
  self:Message("Alle Snapshots sind lokal und NICHT serverbestätigt.")
end
