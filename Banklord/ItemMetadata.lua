-- Optional item-data capture for MoP Classic 5.5.4.
-- Captured client strings are display hints, NEVER authorization or authoritative stock.
-- No HTTP, asset extraction, privileged game actions or external addons.
local A = Banklord
local MAX_ROWS = 40
local MAX_TEXT = 240
local MAX_PER_SCAN = 250
local function ShortText(value, max)
  if type(value) ~= "string" then return nil end
  value = value:gsub("[%z\1-\8\11\12\14-\31]", "")
  if #value == 0 then return nil end
  local limit = math.min(#value, max or MAX_TEXT)
  if limit < #value then
    -- UTF-8 safe byte limit: never save broken German/Cyrillic/Asian text.
    while limit > 0 and string.byte(value, limit + 1) >= 128 and string.byte(value, limit + 1) <= 191 do
      limit = limit - 1
    end
  end
  return value:sub(1, limit)
end
local function RGB(font)
  if not font or type(font.GetTextColor) ~= "function" then return nil end
  local r,g,b = font:GetTextColor()
  if type(r) ~= "number" or type(g) ~= "number" or type(b) ~= "number" then return nil end
  local function n(v) return math.max(0,math.min(255,math.floor(v*255+0.5))) end
  return string.format("%02x%02x%02x",n(r),n(g),n(b))
end
local tooltip
local function CaptureTooltip(itemLink)
  if not tooltip then
    local ok, created = pcall(CreateFrame,"GameTooltip","BanklordMetadataTooltip",UIParent,"GameTooltipTemplate")
    if not ok or not created then return nil end
    tooltip = created
  end
  tooltip:ClearLines()
  local ok = pcall(function()
    tooltip:SetOwner(UIParent,"ANCHOR_NONE")
    tooltip:SetHyperlink(itemLink)
  end)
  if not ok then return nil end
  local result={}
  local count=math.min(tonumber((tooltip:NumLines())) or 0,MAX_ROWS)
  for i=1,count do
    local left=_G["BanklordMetadataTooltipTextLeft"..i]
    local right=_G["BanklordMetadataTooltipTextRight"..i]
    local l=left and ShortText(left:GetText()) or nil
    local r=right and ShortText(right:GetText()) or nil
    if l or r then result[#result+1]={left=l or "",right=r or "",leftColor=RGB(left),rightColor=RGB(right)} end
  end
  tooltip:Hide()
  return #result>0 and result or nil
end
function A:CaptureItemMetadata(item)
  if type(item)~="table" or type(item.itemLink)~="string" then return nil end
  local link=item.itemLink
  local itemID=tonumber(item.itemID)
  if not itemID or itemID<1 then return nil end
  local key=item.itemKey or link:match("|H(item:[^|]+)|h")
  if type(key)~="string" or #key>180 then return nil end
  local name,_,quality,level,minLevel,kind,subkind,_,equipLoc,texture,_,classID,subclassID
  if type(GetItemInfo)=="function" then
    local ok; ok,name,_,quality,level,minLevel,kind,subkind,_,equipLoc,texture,_,classID,subclassID=pcall(GetItemInfo,link)
    if not ok then name=nil end
  end
  if not name then return nil end -- game data not cached; retry later
  local stats={}
  if type(GetItemStats)=="function" then
    local ok, values=pcall(GetItemStats,link)
    if ok and type(values)=="table" then
      for stat,val in pairs(values) do
        if type(stat)=="string" and #stat<=70 and type(val)=="number" and val>=-10000000 and val<=10000000 then
          stats[stat]=val
        end
      end
    end
  end
  -- Prefer the exact glyph already displayed by the guild bank for this variant.
  -- GetItemInfo/GetItemIcon(itemID) can return a different generic enchant scroll.
  local observedTexture=item.iconTexture or texture
  local iconFileID = type(observedTexture)=="number" and math.floor(observedTexture) or nil
  local iconName = type(observedTexture)=="string" and observedTexture:match("[Ii]nterface[\\/][Ii]cons[\\/]([%w_]+)$") or nil
  if not iconName and type(observedTexture)=="string" then
    iconName=observedTexture:match("^([Ii][Nn][Vv]_[%w_]+)$")
  end
  if not iconFileID and not iconName and type(GetItemIcon)=="function" then
    local ok, icon = pcall(GetItemIcon,itemID)
    if ok and type(icon)=="number" then iconFileID=math.floor(icon) end
    if ok and type(icon)=="string" then iconName=icon:match("[Ii]nterface[\\/][Ii]cons[\\/]([%w_]+)$") end
  end
  local rows=CaptureTooltip(link)
  return {
    itemID=itemID,itemKey=key,name=ShortText(name,200), quality=quality,
    itemLevel=level,requiredLevel=minLevel,itemType=ShortText(kind,100),
    itemSubType=ShortText(subkind,100),equipLoc=ShortText(equipLoc,50),
    classID=classID,subclassID=subclassID,iconFileID=iconFileID,
    iconName=ShortText(iconName,80),stats=stats,tooltipLines=rows or {},
    completeness=rows and "tooltip" or "basic",
  }
end
function A:EnrichSnapshotWithClientMetadata(scan)
  if type(scan)~="table" or type(scan.items)~="table" or type(C_Timer)~="table" or type(C_Timer.After)~="function" then return end
  local clientVersion,clientBuild
  if type(GetBuildInfo)=="function" then
    local ok,v,b=pcall(GetBuildInfo)
    if ok then clientVersion=v;clientBuild=tonumber(b) end
  end
  scan.clientVersion=clientVersion or "unknown"
  scan.clientBuild=clientBuild or 0
  scan.itemMetadata=scan.itemMetadata or {}
  local cursor, attempts=1,{}
  local function nextItem()
    -- Abandon enrichment if a newer snapshot replaced this one.
    local db=A.db
    if not db or type(db.fullScans)~="table" then return end
    local found=false
    for _,saved in ipairs(db.fullScans) do if saved==scan then found=true;break end end
    if not found then return end
    if cursor>#scan.items or cursor>MAX_PER_SCAN then
      scan.metadataCapturedAt=time()
      local captured=0
      for _ in pairs(scan.itemMetadata) do captured=captured+1 end
      A:Message('Item-Daten erfasst: '..captured..'/'..math.min(#scan.items,MAX_PER_SCAN)..
        ' Varianten. /reload oder Ausloggen speichert die Daten für den Companion.')
      if A.RefreshUI then A:RefreshUI() end
      return
    end
    local item=scan.items[cursor]
    local key=item and item.itemKey
    if key and not scan.itemMetadata[key] then
      local meta=A:CaptureItemMetadata(item)
      if meta then
        scan.itemMetadata[key]=meta
      else
        attempts[cursor]=(attempts[cursor] or 0)+1
        if attempts[cursor]<3 then
          C_Timer.After(1.5,nextItem)
          return
        end
      end
    end
    cursor=cursor+1
    C_Timer.After(0.04,nextItem)
  end
  C_Timer.After(0,nextItem)
end
