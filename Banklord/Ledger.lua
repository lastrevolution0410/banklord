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
