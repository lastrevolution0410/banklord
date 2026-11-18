-- Bank ledger: reads the guild bank's item and gold log (WoW keeps only the
-- last 25 entries per tab), the bank's gold and market prices from TSM or
-- Auctionator. The companion uploads it; the server keeps the history.
local A = Banklord
local MONEY_TAB = (MAX_GUILDBANK_TABS or 8) + 1
local ITEM_ACTIONS = {deposit=true, withdraw=true, move=true}
local MONEY_ACTIONS = {deposit=true, withdraw=true, repair=true, withdrawForTab=true, buyTab=true, depositSummary=true}

-- Entries carry "years, months, days, hours ago"; the server matches within one hour.
local function Ago(now, y, m, d, h)
  local t = now - ((((tonumber(y) or 0) * 365 + (tonumber(m) or 0) * 30 + (tonumber(d) or 0)) * 24 + (tonumber(h) or 0)) * 3600)
  return t - t % 3600
end

local function ItemId(link)
  return link and tonumber(link:match("item:(%d+)"))
end

-- Market value per piece in copper: TSM first, then Auctionator.
function A:MarketPrice(itemId)
  if type(TSM_API) == "table" and type(TSM_API.GetCustomPriceValue) == "function" then
    local ok, v = pcall(TSM_API.GetCustomPriceValue, "dbmarket", "i:" .. itemId)
    if ok and tonumber(v) and tonumber(v) > 0 then return math.floor(tonumber(v)), "tsm" end
  end
  local api = type(Auctionator) == "table" and Auctionator.API and Auctionator.API.v1
  if api and type(api.GetAuctionPriceByItemID) == "function" then
    local ok, v = pcall(api.GetAuctionPriceByItemID, "Banklord", itemId)
    if ok and tonumber(v) and tonumber(v) > 0 then return math.floor(tonumber(v)), "auctionator" end
  end
end

function A:ReadBankLog()
  if not self.bankOpen or not self.db or type(GetGuildBankTransaction) ~= "function" then return end
  local guild = GetGuildInfo and GetGuildInfo("player")
  if not guild then return end
  local now = time()
  local log = {guild = guild, realm = GetRealmName and GetRealmName() or "", capturedAt = now, items = {}, gold = {}, prices = {}}
  local tabs = type(GetNumGuildBankTabs) == "function" and GetNumGuildBankTabs() or 0
  for tab = 1, math.min(tabs, 8) do
    local n = type(GetNumGuildBankTransactions) == "function" and GetNumGuildBankTransactions(tab) or 0
    for i = 1, math.min(n or 0, 50) do
      local kind, name, link, count, tab1, tab2, y, m, d, h = GetGuildBankTransaction(tab, i)
      local id = ItemId(link)
      if ITEM_ACTIONS[kind] and name and id then
        log.items[#log.items + 1] = {t = kind, p = name, i = id, n = (link:match("%[(.-)%]") or ""):sub(1, 200), q = tonumber(count) or 1,
          tab = kind == "move" and (tonumber(tab1) or tab) or tab, to = kind == "move" and tonumber(tab2) or nil, at = Ago(now, y, m, d, h)}
      end
    end
  end
  local n = type(GetNumGuildBankMoneyTransactions) == "function" and GetNumGuildBankMoneyTransactions() or 0
  for i = 1, math.min(n or 0, 50) do
    local kind, name, amount, y, m, d, h = GetGuildBankMoneyTransaction(i)
    if MONEY_ACTIONS[kind] then
      log.gold[#log.gold + 1] = {t = kind, p = name or "?", q = tonumber(amount) or 0, at = Ago(now, y, m, d, h)}
    end
  end
  if type(GetGuildBankMoney) == "function" then log.money = GetGuildBankMoney() end
  -- Prices for everything in the latest scan.
  local scans = self.db.fullScans
  local latest = type(scans) == "table" and scans[#scans]
  local seen = {}
  if latest and type(latest.items) == "table" then
    for _, item in ipairs(latest.items) do
      local id = item.itemID
      if id and not seen[id] and #log.prices < 1500 then
        seen[id] = true
        local copper, source = self:MarketPrice(id)
        if copper then log.prices[#log.prices + 1] = {i = id, c = copper, s = source} end
      end
    end
  end
  self.db.bankLog = log
  self.db.gameFlavor = self.GameFlavor and self:GameFlavor() or self.db.gameFlavor
end

-- Ask the server for every tab's log and the gold log; GUILDBANKLOG_UPDATE answers.
function A:CaptureBankLog()
  if not self.bankOpen or type(QueryGuildBankLog) ~= "function" then return end
  local tabs = type(GetNumGuildBankTabs) == "function" and GetNumGuildBankTabs() or 0
  for tab = 1, math.min(tabs, 8) do pcall(QueryGuildBankLog, tab) end
  pcall(QueryGuildBankLog, MONEY_TAB)
end

local frame = CreateFrame("Frame")
local pending = false
pcall(frame.RegisterEvent, frame, "GUILDBANKLOG_UPDATE")
frame:SetScript("OnEvent", function()
  if pending or not C_Timer or type(C_Timer.After) ~= "function" then return end
  pending = true
  C_Timer.After(1, function() pending = false; A:ReadBankLog() end)
end)

-- Read the logs each time the bank opens (after the bank-state handlers in Manage.lua).
local previous = A.OnBankStateChanged
function A:OnBankStateChanged()
  if previous then previous(self) end
  if self.bankOpen then self:CaptureBankLog() end
end

-- Gold amount like the game shows it: 1234g 56s 78c.
function A:FormatGold(copper)
  copper = math.max(0, math.floor(tonumber(copper) or 0))
  local g, s, c = math.floor(copper / 10000), math.floor(copper % 10000 / 100), copper % 100
  local out = {}
  if g > 0 then out[#out + 1] = g .. "g" end
  if s > 0 then out[#out + 1] = s .. "s" end
  if c > 0 or #out == 0 then out[#out + 1] = c .. "c" end
  return table.concat(out, " ")
end

local ACTION = {deposit = "eingezahlt", withdraw = "entnommen", move = "verschoben", repair = "Reparatur",
  withdrawForTab = "für Fach entnommen", buyTab = "Fach gekauft", depositSummary = "Einzahlung (gesammelt)"}

-- Cash book in game: the bank's gold and the latest log entries this character read.
function A:BankLogText()
  local log = self.db and self.db.bankLog
  if type(log) ~= "table" then return nil end
  local rows = {}
  for _, e in ipairs(log.gold or {}) do rows[#rows + 1] = {at = e.at or 0, text = ("%s %s %s"):format(e.p or "?", A:Tr(ACTION[e.t] or tostring(e.t)), self:FormatGold(e.q))} end
  for _, e in ipairs(log.items or {}) do
    local where = e.t == "move" and (" (" .. A:Tr("Fach %d → %d"):format(e.tab or 0, e.to or 0) .. ")") or (" (" .. A:Tr("Fach %d"):format(e.tab or 0) .. ")")
    rows[#rows + 1] = {at = e.at or 0, text = ("%s %s %dx %s%s"):format(e.p or "?", A:Tr(ACTION[e.t] or tostring(e.t)), tonumber(e.q) or 0, (type(e.n) == "string" and e.n ~= "") and e.n or ("Item " .. tostring(e.i or 0)), where)}
  end
  table.sort(rows, function(a, b) return a.at > b.at end)
  local lines = {}
  if log.money then lines[#lines + 1] = A:Tr("Gold in der Gildenbank: %s"):format(self:FormatGold(log.money)) end
  lines[#lines + 1] = ""
  for i, r in ipairs(rows) do
    if i > 60 then break end
    lines[#lines + 1] = (type(date) == "function" and date("%d.%m. %H:00", r.at) or "") .. "  " .. r.text
  end
  if #rows == 0 then lines[#lines + 1] = A:Tr("Keine Einträge im Bankprotokoll.") end
  return table.concat(lines, "\n"), log.capturedAt, log.guild
end

function A:ShowBankLog()
  local body, at, guild = self:BankLogText()
  if not body then
    return self:Message(A:Tr("Kassenbuch: Öffne einmal die Gildenbank, dann liest Banklord das Bankprotokoll aus. Die ganze Geschichte steht auf der Website unter „Kassenbuch“."))
  end
  self:ShowTextWindow(A:Tr("Banklord – Kassenbuch") .. (type(guild) == "string" and guild ~= "" and (" – " .. guild) or ""),
    A:Tr("Stand %s. Die ganze Geschichte steht auf der Website unter „Kassenbuch“."):format(type(date) == "function" and date("%d.%m. %H:%M", at or time()) or ""),
    body, false)
end
