-- Retail moved some item functions into C_Item; provide the classic names.
if C_Item then
  if not GetItemInfo and C_Item.GetItemInfo then GetItemInfo = C_Item.GetItemInfo end
  if not GetItemIcon and C_Item.GetItemIconByID then GetItemIcon = C_Item.GetItemIconByID end
  if not GetItemStats and C_Item.GetItemStats then GetItemStats = C_Item.GetItemStats end
end
-- Banklord / MoP Classic 5.5.4 / Interface 50504
-- Read-only bank interaction detection; no protected actions or network requests.
Banklord = Banklord or {}
local A = Banklord
A.version = "0.16.5"
A.bankOpen = false
A.lastBankEvent = "noch keines" -- display-only default, translated in Debug()
A.frame = CreateFrame("Frame")
local F = A.frame

-- Classic clients may register legacy events without firing them. The current
-- interaction-manager events are the primary source for guild bank presence.
local function RegisterIfSupported(name)
  local ok = pcall(function() F:RegisterEvent(name) end)
  return ok
end
for _, event in ipairs({
  "ADDON_LOADED",
  "PLAYER_INTERACTION_MANAGER_FRAME_SHOW",
  "PLAYER_INTERACTION_MANAGER_FRAME_HIDE",
  "GUILDBANKFRAME_OPENED",
  "GUILDBANKFRAME_CLOSED",
  "GUILDBANKBAGSLOTS_CHANGED",
  "CHAT_MSG_ADDON",
}) do RegisterIfSupported(event) end

local function GuildBankerType()
  if Enum and Enum.PlayerInteractionType and Enum.PlayerInteractionType.GuildBanker ~= nil then
    return Enum.PlayerInteractionType.GuildBanker
  end
  return 10  -- Documented stable guild-banker interaction type in MoP Classic.
end

function A:Message(msg)
  DEFAULT_CHAT_FRAME:AddMessage("|cff33ccaaBanklord|r " .. tostring(msg))
end

-- A summoned mobile Guild Chest is a game object, not necessarily a GuildBanker
-- NPC. For that reason the visible guild bank UI is the first signal, rather
-- than requiring a specific NPC-interaction type.
function A:GetVisibleGuildBankFrame()
  local frame = GuildBankFrame
  if not frame or type(frame.IsVisible) ~= "function" then
    return false, frame ~= nil
  end
  local ok, visible = pcall(frame.IsVisible, frame)
  return ok and visible == true, true
end

function A:IsGuildBankOpen()
  local visible, frameKnown = self:GetVisibleGuildBankFrame()
  if visible then
    self.bankOpen = true
    self.bankDetection = "guild-bank-frame"
    return true
  end

  local guildBanker = GuildBankerType()
  local interactionKnown, interacting = false, false
  if C_PlayerInteractionManager and
     type(C_PlayerInteractionManager.IsInteractingWithNpcOfType) == "function" then
    local ok, active = pcall(C_PlayerInteractionManager.IsInteractingWithNpcOfType, guildBanker)
    if ok and type(active) == "boolean" then
      interactionKnown, interacting = true, active
    end
  end
  if not interactionKnown and C_PlayerInteractionManager and
     type(C_PlayerInteractionManager.GetCurrentInteractionType) == "function" then
    local ok, activeType = pcall(C_PlayerInteractionManager.GetCurrentInteractionType)
    if ok then
      interactionKnown = true
      interacting = (activeType == guildBanker)
    end
  end
  if interacting then
    self.bankOpen = true
    self.bankDetection = "guild-banker-interaction"
    return true
  end

  -- Prevent a stale SHOW event from being mistaken for an open bank after the
  -- window has closed. Only use cached events when no live signal is available.
  if frameKnown or interactionKnown then
    self.bankOpen = false
    self.bankDetection = "none"
    return false
  end
  self.bankDetection = "event-fallback"
  return self.bankOpen == true
end

-- One automatic complete scan per bank opening. Never call a protected action,
-- and do not treat the bank-open event itself as proof that tabs are loaded.
function A:QueueAutomaticBankScan()
  if self.autoOpenTriggered or self.autoOpenQueued or not self.db then return end
  if not C_Timer or type(C_Timer.After) ~= 'function' then return end
  self.autoOpenQueued = true
  self.autoOpenSerial = (self.autoOpenSerial or 0) + 1
  local serial = self.autoOpenSerial
  C_Timer.After(1.5, function()
    if A.autoOpenSerial ~= serial then return end
    A.autoOpenQueued = false
    if A.autoOpenTriggered or not A.db or not A:IsGuildBankOpen() then return end
    if A.scanRun then A.autoOpenTriggered = true; return end
    A.guildInfoMissing = nil
    A:StartScan('all', true)
    if A.scanRun then
      A.autoOpenTriggered = true
    elseif A.noBankTabs and (A.autoOpenRetries or 0) >= 2 then
      -- Tab data has arrived and there really is no tab: say it once, quietly stop.
      A.autoOpenTriggered = true
      A:Message(A:Tr("Diese Gildenbank hat noch kein Bankfach - es gibt nichts zu zählen. Gold wird trotzdem im Kassenbuch erfasst."))
    elseif A.guildInfoMissing and (A.autoOpenRetries or 0) >= 4 then
      A.autoOpenTriggered = true
      A:Message(A:Tr("Gildenname noch nicht geladen; Scan übersprungen. Gildenbank kurz schließen und wieder öffnen."))
    elseif (A.autoOpenRetries or 0) < 4 then
      -- The first event can precede the bank-tab metadata arriving.
      A.autoOpenRetries = (A.autoOpenRetries or 0) + 1
      A:QueueAutomaticBankScan()
    end
  end)
end
function A:EndAutomaticBankSession()
  self.autoOpenSerial = (self.autoOpenSerial or 0) + 1
  self.autoOpenQueued = false
  self.autoOpenTriggered = false
  self.autoOpenRetries = 0
end

-- retail, mop_classic, anniversary (TBC), forever or classic_era, from the client's interface number.
function A:GameFlavor()
  local toc = type(GetBuildInfo) == "function" and select(4, GetBuildInfo()) or 0
  toc = tonumber(toc) or 0
  if toc >= 100000 then return "retail" end
  if toc >= 50000 and toc < 60000 then return "mop_classic" end
  if toc >= 20000 and toc < 30000 then return "anniversary" end
  -- WoW Forever (since Nov 2026): own project id where the client defines it, interface 1.6x.
  if (WOW_PROJECT_CAMELOT and WOW_PROJECT_ID == WOW_PROJECT_CAMELOT) or (toc >= 16000 and toc < 17000) then return "forever" end
  if toc > 0 and toc < 20000 then return "classic_era" end
  return nil
end

function A:OnEvent(event, ...)
  if event == "ADDON_LOADED" then
    local name = ...
    if name ~= "Banklord" then return end
    BanklordDB = BanklordDB or {}
    BanklordDB.schemaVersion = 2
    -- Game version of this client; the server only accepts files from the guild's version.
    BanklordDB.gameFlavor = A:GameFlavor()
    BanklordDB.fullScans = type(BanklordDB.fullScans) == "table" and BanklordDB.fullScans or {}
    BanklordDB.scans = type(BanklordDB.scans) == "table" and BanklordDB.scans or {}
    BanklordDB.settings = type(BanklordDB.settings) == "table" and BanklordDB.settings or {}
    BanklordDB.localCartDraft = type(BanklordDB.localCartDraft) == "table" and BanklordDB.localCartDraft or {}
    BanklordDB.peerInbox = type(BanklordDB.peerInbox) == "table" and BanklordDB.peerInbox or {}
    BanklordDB.peerOutbox = type(BanklordDB.peerOutbox) == "table" and BanklordDB.peerOutbox or {}
    local lang = BanklordDB.settings.locale
    if lang ~= "deDE" and lang ~= "enUS" then BanklordDB.settings.locale = nil end
    A.db = BanklordDB
    A.loadedAt = time()
    if A.ApplyUILocale then A:ApplyUILocale() end
    if A.InitPeer then A:InitPeer() end
    if A.ReconcileOrderOutbox then A:ReconcileOrderOutbox() end
    if A.ReconcileFeedback then A:ReconcileFeedback() end
    if A.InitManage then A:InitManage() end
    if A.InitTooltip then A:InitTooltip() end
    if A.InitMinimapButton then A:InitMinimapButton() end
    A:Message(A:Tr("v%s geladen. /banklord zeigt die Befehle."):format(A.version))
  elseif event == "PLAYER_INTERACTION_MANAGER_FRAME_SHOW" or
         event == "PLAYER_INTERACTION_MANAGER_FRAME_HIDE" then
    local interactionType = ...
    if interactionType ~= GuildBankerType() then return end
    A.lastBankEvent = event
    A.bankOpen = (event == "PLAYER_INTERACTION_MANAGER_FRAME_SHOW")
    if not A.bankOpen and A.AbortScan then A:AbortScan("Bankinteraktion beendet") end
    if A.bankOpen then A:QueueAutomaticBankScan() else A:EndAutomaticBankSession() end
    if A.RefreshUI then A:RefreshUI() end
    if A.OnBankStateChanged then A:OnBankStateChanged() end
  elseif event == "GUILDBANKFRAME_OPENED" then
    A.lastBankEvent = event
    A.bankOpen = true
    A:QueueAutomaticBankScan()
    if A.RefreshUI then A:RefreshUI() end
    if A.OnBankStateChanged then A:OnBankStateChanged() end
  elseif event == "GUILDBANKFRAME_CLOSED" then
    A.lastBankEvent = event
    A.bankOpen = false
    A:EndAutomaticBankSession()
    if A.AbortScan then A:AbortScan("Bankfenster geschlossen") end
    if A.RefreshUI then A:RefreshUI() end
    if A.OnBankStateChanged then A:OnBankStateChanged() end
  elseif event == "CHAT_MSG_ADDON" then
    if A.ReceivePeerMessage then A:ReceivePeerMessage(...) end
    if A.ReceiveManageMessage then A:ReceiveManageMessage(...) end
    if A.ReceiveJoinMessage then A:ReceiveJoinMessage(...) end
  elseif event == "GUILDBANKBAGSLOTS_CHANGED" then
    if A.ScanEvent then A:ScanEvent() end
    if A.manageMode=='bank' and A.RefreshManagePanel then A:RefreshManagePanel() end
  end
end
F:SetScript("OnEvent", function(_, event, ...) A:OnEvent(event, ...) end)

function A:Debug()
  local bank = self:IsGuildBankOpen()
  local selected = GetCurrentGuildBankTab and GetCurrentGuildBankTab() or A:Tr("nicht verfügbar")
  local bankType = GuildBankerType()
  local queryResult = A:Tr("nicht verfügbar")
  if C_PlayerInteractionManager and
      type(C_PlayerInteractionManager.IsInteractingWithNpcOfType) == "function" then
    local ok, value = pcall(C_PlayerInteractionManager.IsInteractingWithNpcOfType, bankType)
    queryResult = ok and tostring(value) or A:Tr("API-Fehler")
  end
  local tabInfo = A:Tr("unbekannt")
  if bank and type(selected) == "number" and selected > 0 and GetGuildBankTabInfo then
    local ok, name, _, viewable = pcall(GetGuildBankTabInfo, selected)
    if ok then tabInfo = A:Tr("%s/Leserecht=%s"):format(tostring(name), tostring(viewable)) end
  end
  self:Message(A:Tr("DEBUG v%s: Bank=%s, API=%s, Fach=%s"):format(self.version,
    tostring(bank), tostring(queryResult), tostring(selected)))
  local uiVisible = self:GetVisibleGuildBankFrame()
  self:Message(A:Tr("DEBUG Quelle=%s, UI sichtbar=%s, letztes Bankereignis=%s"):format(
    tostring(self.bankDetection), tostring(uiVisible), A:Tr(tostring(self.lastBankEvent))))
  self:Message(A:Tr("DEBUG Fachinfo=%s, Scan ausstehend=%s"):format(tabInfo, tostring(self.pendingTab)))
  if self.scanRun then
    self:Message(A:Tr("DEBUG Gesamtscan: %d/%d, Fach=%s, Versuch=%s"):format(self.scanRun.completed,
      #self.scanRun.queue, tostring(self.scanRun.currentTab), tostring(self.scanRun.retries)))
  end
end

SLASH_BANKLORD1 = "/banklord"
SLASH_BANKLORD2 = "/bl"
SlashCmdList.BANKLORD = function(text)
  local cmd = tostring(text or ""):lower():match("^%s*(.-)%s*$")
  if cmd == "scan" or cmd == "scan all" then
    A:StartScan("all")
  elseif cmd == "scanfach" or cmd == "scan tab" then
    A:StartSingleScan()
  elseif cmd == "stop" then
    if A.scanRun then A:AbortScan("Manuell abgebrochen") else A:Message(A:Tr("Kein Scan aktiv.")) end
  elseif cmd == "status" then
    A:Status()
  elseif cmd == "debug" then
    A:Debug()
  elseif cmd:match("^ablehnen%s+%d+$") or cmd:match("^reject%s+%d+$") then
    local id = tonumber(cmd:match("(%d+)$"))
    local found
    for _, o in ipairs(A:IsBankManager() and A:OpenManageOrders() or {}) do if o.id == id then found = o end end
    if found then A:RejectOrder(found) else A:Message(A:Tr("Keine offene Bestellung #%d."):format(id)) end
  elseif cmd:match("^stornieren%s+%d+$") or cmd:match("^cancel%s+%d+$") then
    A:CancelOwnOrder(tonumber(cmd:match("(%d+)$")))
  elseif cmd == "einkauf" or cmd == "shopping" then
    if A:IsBankManager() then A:ShowShoppingList() else A:Message(A:Tr("Nur für Bankverwalter.")) end
  elseif cmd == "tooltip" then
    A:ToggleTooltipStock()
  elseif cmd == "orders" or cmd == "bestellungen" then
    if A:IsBankManager() then A:ShowManagePanel("list") else A:Message(A:Tr("Nur für Bankverwalter.")) end
  elseif cmd == "liste" or cmd:match("^suche%s*") then
    local query = cmd:match("^suche%s*(.*)$") or ""
    A:SearchLocalItems(query)
  elseif cmd:match("^merken%s") then
    local iid,amount=cmd:match("^merken%s+(%d+)%s+(%d+)%s*$")
    A:AddCartDraft(tonumber(iid),tonumber(amount))
  elseif cmd == "korb" then
    A:CartDraftStatus()
    if A.OpenCartUI then A:OpenCartUI() end
  elseif cmd == "kasse" or cmd == "kassenbuch" or cmd == "ledger" then
    A:ShowBankLog("overview")
  elseif cmd == "verlauf" or cmd == "history" then
    A:ShowBankLog("history")
  elseif cmd == "protokoll" or cmd == "log" then
    A:ShowBankLog("activity")
  elseif cmd == "bestellen" or cmd == "order" then
    A:SubmitCartOrder()
  elseif cmd == "bestellen dringend" or cmd == "order urgent" then
    local before = A.orderUrgent
    A.orderUrgent = true
    A:SubmitCartOrder()
    -- Not sent (e.g. empty cart): keep the checkbox state, not a leftover "urgent".
    if A.orderUrgent then A.orderUrgent = before end
  elseif cmd == "code" then
    if A.OpenCartUI then A:OpenCartUI() end
  elseif cmd == "leeren" then
    A:ClearCartDraft()
  elseif cmd:match("^senden%s+") then
    local target=cmd:match("^senden%s+(.+)$")
    if A.SendCartToPeer then A:SendCartToPeer(target) end
  elseif cmd == "eingang" then
    if A.ShowPeerInbox then A:ShowPeerInbox() end
  elseif cmd == "ausgang" then
    if A.ShowPeerOutbox then A:ShowPeerOutbox() end
  elseif cmd == "erneut" then
    if A.RetryPendingPeers then A:RetryPendingPeers(true) end
  elseif cmd == "feedback" or cmd == "hilfe" or cmd == "help" or cmd == "fehler" then
    if A.OpenFeedbackUI then A:OpenFeedbackUI() end
  elseif cmd == "minimap" then
    if A.ToggleMinimapButton then A:ToggleMinimapButton() end
  elseif cmd == "lang" or cmd:match("^lang%s") then
    A:SetLanguage(cmd:match("^lang%s+(%S+)") or "")
  elseif cmd == "open" or cmd == "" then
    A:ToggleUI()
  else
    A:Message(A:Tr("Befehle: /banklord open, scan, scanfach, liste, suche <Name>, merken <Item-ID> <Menge>, korb, code, leeren, senden <Spieler>, eingang, ausgang, erneut, status, bestellungen, ablehnen <Nr>, stornieren <Nr>, einkauf, kasse, verlauf, protokoll, tooltip, feedback, debug, minimap, lang de|en|auto"))
  end
end
