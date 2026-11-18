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

-- Gold amount as plain text (chat, copyable lists): 1234g 56s 78c.
function A:FormatGold(copper)
  copper = math.max(0, math.floor(tonumber(copper) or 0))
  local g, s, c = math.floor(copper / 10000), math.floor(copper % 10000 / 100), copper % 100
  local out = {}
  if g > 0 then out[#out + 1] = g .. "g" end
  if s > 0 then out[#out + 1] = s .. "s" end
  if c > 0 or #out == 0 then out[#out + 1] = c .. "c" end
  return table.concat(out, " ")
end

-- Thousands separator like the game client: 1.661 (German) or 1,661 (English).
local function Thousands(n)
  local sep = type(LARGE_NUMBER_SEPERATOR) == "string" and LARGE_NUMBER_SEPERATOR ~= "" and LARGE_NUMBER_SEPERATOR
    or ((A.GetLanguage and A:GetLanguage() ~= "deDE") and "," or ".")
  local s = tostring(n)
  while true do
    local k
    s, k = s:gsub("^(%d+)(%d%d%d)", "%1" .. sep .. "%2")
    if k == 0 then return s end
  end
end

local COIN = {
  g = "|TInterface\\MoneyFrame\\UI-GoldIcon:0:0:2:0|t",
  s = "|TInterface\\MoneyFrame\\UI-SilverIcon:0:0:2:0|t",
  c = "|TInterface\\MoneyFrame\\UI-CopperIcon:0:0:2:0|t",
}
-- Gold amount like the game shows it: 1.661 (gold coin) 57 (silver coin) 61 (copper coin).
function A:CoinText(copper)
  copper = math.max(0, math.floor(tonumber(copper) or 0))
  local g, s, c = math.floor(copper / 10000), math.floor(copper % 10000 / 100), copper % 100
  local out = {}
  if g > 0 then out[#out + 1] = Thousands(g) .. COIN.g end
  if s > 0 then out[#out + 1] = s .. COIN.s end
  if c > 0 or #out == 0 then out[#out + 1] = c .. COIN.c end
  return table.concat(out, " ")
end

local IN = {deposit = true, depositSummary = true}
local ACTION = {deposit = "eingezahlt", withdraw = "entnommen", move = "verschoben", repair = "Reparatur",
  withdrawForTab = "für Fach entnommen", buyTab = "Fach gekauft", depositSummary = "Einzahlung (gesammelt)"}
local GREEN, RED, GREY = "|cff4cd964", "|cffff6060", "|cffc8c0b0"

local function When(at)
  return type(date) == "function" and date("%d.%m. %H:00", tonumber(at) or 0) or ""
end

-- Rows for the cash book: kind = "overview", "gold" or "items".
-- Each row: {cells = {date/player, ..., ...}} already coloured, newest first.
function A:BankLogRows(kind)
  local log = self.db and self.db.bankLog
  if type(log) ~= "table" then return nil end
  local rows = {}
  if kind == "items" then
    for _, e in ipairs(log.items or {}) do
      if type(e) == "table" then
        local where = e.t == "move" and A:Tr("Fach %d » %d"):format(tonumber(e.tab) or 0, tonumber(e.to) or 0) or A:Tr("Fach %d"):format(tonumber(e.tab) or 0)
        local name = (type(e.n) == "string" and e.n ~= "") and e.n or ("Item " .. tostring(e.i or 0))
        local col = IN[e.t] and GREEN or (e.t == "move" and GREY or RED)
        rows[#rows + 1] = {at = tonumber(e.at) or 0, cells = {When(e.at), tostring(e.p or "?"),
          col .. A:Tr(ACTION[e.t] or tostring(e.t)) .. "|r", ("%dx %s  |cff9d9d9d(%s)|r"):format(tonumber(e.q) or 0, name, where)}}
      end
    end
  elseif kind == "gold" then
    for _, e in ipairs(log.gold or {}) do
      if type(e) == "table" then
        local col = IN[e.t] and GREEN or RED
        rows[#rows + 1] = {at = tonumber(e.at) or 0, cells = {When(e.at), tostring(e.p or "?"),
          col .. A:Tr(ACTION[e.t] or tostring(e.t)) .. "|r", col .. (IN[e.t] and "+ " or "- ") .. "|r" .. self:CoinText(e.q)}}
      end
    end
  else
    -- Overview: gold per player over the entries the game still shows.
    local per, order = {}, {}
    for _, e in ipairs(log.gold or {}) do
      if type(e) == "table" then
        local p = tostring(e.p or "?")
        if not per[p] then per[p] = {inn = 0, out = 0}; order[#order + 1] = p end
        if IN[e.t] then per[p].inn = per[p].inn + (tonumber(e.q) or 0) else per[p].out = per[p].out + (tonumber(e.q) or 0) end
      end
    end
    table.sort(order, function(a, b) return (per[a].inn - per[a].out) > (per[b].inn - per[b].out) end)
    for _, p in ipairs(order) do
      local net = per[p].inn - per[p].out
      rows[#rows + 1] = {cells = {p, GREEN .. "+ |r" .. self:CoinText(per[p].inn), RED .. "- |r" .. self:CoinText(per[p].out),
        (net >= 0 and GREEN .. "+ |r" or RED .. "- |r") .. self:CoinText(math.abs(net))}}
    end
    return rows, log
  end
  table.sort(rows, function(a, b) return a.at > b.at end)
  return rows, log
end

local HEADERS = {
  overview = {"Spieler", "Eingezahlt", "Entnommen", "Saldo"},
  gold = {"Zeit", "Spieler", "Aktion", "Betrag"},
  items = {"Zeit", "Spieler", "Aktion", "Gegenstand"},
}
local COLS = {overview = {0, 150, 300, 450}, gold = {0, 90, 230, 380}, items = {0, 90, 230, 360}}
local ROW_H, MAX_ROWS = 20, 120
local book

local function BuildBook()
  book = CreateFrame("Frame", "BanklordCashBook", UIParent, BackdropTemplateMixin and "BackdropTemplate" or nil)
  book:SetSize(640, 470); book:SetPoint("CENTER"); book:SetFrameStrata("DIALOG"); book:SetToplevel(true)
  if book.SetBackdrop then
    book:SetBackdrop({bgFile = "Interface\\DialogFrame\\UI-DialogBox-Background", edgeFile = "Interface\\DialogFrame\\UI-DialogBox-Border",
      tile = true, tileSize = 32, edgeSize = 24, insets = {left = 6, right = 6, top = 6, bottom = 6}})
  end
  book:SetMovable(true); book:EnableMouse(true); book:RegisterForDrag("LeftButton")
  book:SetScript("OnDragStart", book.StartMoving); book:SetScript("OnDragStop", book.StopMovingOrSizing)
  if type(UISpecialFrames) == "table" then table.insert(UISpecialFrames, "BanklordCashBook") end -- Escape closes it
  book.title = book:CreateFontString(nil, "OVERLAY", "GameFontNormal"); book.title:SetPoint("TOPLEFT", 20, -18)
  book.goldLabel = book:CreateFontString(nil, "OVERLAY", "GameFontHighlight"); book.goldLabel:SetPoint("TOPLEFT", 20, -44)
  book.gold = book:CreateFontString(nil, "OVERLAY", "GameFontHighlightLarge"); book.gold:SetPoint("LEFT", book.goldLabel, "RIGHT", 8, 0)
  book.hint = book:CreateFontString(nil, "OVERLAY", "GameFontDisableSmall"); book.hint:SetPoint("TOPLEFT", 20, -68)
  book.hint:SetWidth(600); book.hint:SetJustifyH("LEFT")
  book.tabs = {}
  for i, key in ipairs({"overview", "gold", "items"}) do
    local b = CreateFrame("Button", nil, book, "UIPanelButtonTemplate")
    b:SetSize(130, 24); b:SetPoint("TOPLEFT", 20 + (i - 1) * 136, -90)
    b:SetText(A:Tr(({overview = "Übersicht", gold = "Gold", items = "Gegenstände"})[key]))
    b:SetScript("OnClick", function() A:ShowBankLog(key) end)
    book.tabs[key] = b
  end
  book.head = {}
  for i = 1, 4 do
    local h = book:CreateFontString(nil, "OVERLAY", "GameFontNormalSmall"); h:SetJustifyH("LEFT")
    book.head[i] = h
  end
  local scroll = CreateFrame("ScrollFrame", nil, book, "UIPanelScrollFrameTemplate")
  scroll:SetPoint("TOPLEFT", 20, -146); scroll:SetPoint("BOTTOMRIGHT", -40, 48)
  book.content = CreateFrame("Frame", nil, scroll); book.content:SetSize(580, ROW_H)
  scroll:SetScrollChild(book.content); book.scroll = scroll
  book.rows = {}
  book.empty = book:CreateFontString(nil, "OVERLAY", "GameFontHighlight"); book.empty:SetPoint("TOPLEFT", 24, -152)
  book.empty:SetWidth(580); book.empty:SetJustifyH("LEFT")
  local close = CreateFrame("Button", nil, book, "UIPanelButtonTemplate")
  close:SetSize(120, 24); close:SetPoint("BOTTOMRIGHT", -18, 16); close:SetText(A:Tr("Schließen"))
  close:SetScript("OnClick", function() book:Hide() end)
end

local function Row(i)
  local r = book.rows[i]
  if r then return r end
  r = CreateFrame("Frame", nil, book.content); r:SetSize(580, ROW_H); r:SetPoint("TOPLEFT", 0, -(i - 1) * ROW_H)
  r.bg = r:CreateTexture(nil, "BACKGROUND"); r.bg:SetAllPoints()
  if r.bg.SetColorTexture then r.bg:SetColorTexture(1, 1, 1, i % 2 == 0 and 0.05 or 0) end
  r.cells = {}
  for c = 1, 4 do
    local f = r:CreateFontString(nil, "OVERLAY", "GameFontHighlightSmall"); f:SetJustifyH("LEFT")
    r.cells[c] = f
  end
  book.rows[i] = r
  return r
end

-- Cash book in game: gold of the guild bank and the entries the game still shows.
function A:ShowBankLog(kind)
  kind = kind or (book and book.kind) or "overview"
  local rows, log = self:BankLogRows(kind)
  if not rows then
    return self:Message(A:Tr("Kassenbuch: Öffne einmal die Gildenbank, dann liest Banklord das Bankprotokoll aus. Die ganze Geschichte steht auf der Website unter „Kassenbuch“."))
  end
  if not book then BuildBook() end
  book.kind = kind
  local guild = type(log.guild) == "string" and log.guild ~= "" and (" – " .. log.guild) or ""
  book.title:SetText(A:Tr("Banklord – Kassenbuch") .. guild)
  book.goldLabel:SetText(A:Tr("Gold in der Gildenbank:"))
  book.gold:SetText(log.money and self:CoinText(log.money) or "?")
  book.hint:SetText(A:Tr("Stand %s. Das Spiel zeigt nur die letzten Einträge – die ganze Geschichte steht auf der Website unter „Kassenbuch“.")
    :format(type(date) == "function" and date("%d.%m. %H:%M", tonumber(log.capturedAt) or time()) or ""))
  for key, b in pairs(book.tabs) do if key == kind then b:Disable() else b:Enable() end end
  local cols = COLS[kind]
  for i, h in ipairs(book.head) do
    h:ClearAllPoints(); h:SetPoint("TOPLEFT", book, "TOPLEFT", 24 + cols[i], -128)
    h:SetWidth((cols[i + 1] or 580) - cols[i] - 6); h:SetText(A:Tr(HEADERS[kind][i]))
  end
  local shown = math.min(#rows, MAX_ROWS)
  for i = 1, shown do
    local r = Row(i)
    for c = 1, 4 do
      local f = r.cells[c]
      f:ClearAllPoints(); f:SetPoint("LEFT", r, "LEFT", 4 + cols[c], 0); f:SetWidth((cols[c + 1] or 580) - cols[c] - 6)
      f:SetText(rows[i].cells[c] or "")
    end
    r:Show()
  end
  for i = shown + 1, #book.rows do book.rows[i]:Hide() end
  book.content:SetHeight(math.max(ROW_H, shown * ROW_H))
  book.empty:SetText(shown == 0 and A:Tr("Keine Einträge im Bankprotokoll.") or "")
  book:Show()
  return book
end
