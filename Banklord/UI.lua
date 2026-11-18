-- Banklord: Blizzard-inspired classic bank interface for MoP Classic 5.5.4.
-- Uses Blizzard's insecure UI frames; no protected actions or foreign addon dependencies.
-- Local snapshot and cart drafts are NEVER presented as live or server-confirmed.
local A = Banklord
local WINDOW_W, WINDOW_H = 1030, 670
-- Blizzard game fonts and original frame textures; no external assets or addon hooks.
local COLORS = {
  gold={1,.84,.46}, bright={.98,.97,.92}, muted={.87,.84,.78},
  green={.37,.91,.54}, warn={1,.67,.26}, ink={.07,.065,.055},
}
local function rgb(t) return t[1],t[2],t[3] end
local function backdrop(parent,x,y,w,h,r,g,b,a)
  local t=parent:CreateTexture(nil,'BACKGROUND')
  t:SetTexture('Interface\\Buttons\\WHITE8X8')
  t:SetVertexColor(r or .052,g or .047,b or .039,a or .88)
  t:SetPoint('TOPLEFT',parent,'TOPLEFT',x,y)
  t:SetSize(w,h)
  return t
end
-- Every panel has a native Blizzard dialog border and a dark stone/wood tint.
-- If a private server lacks BackdropTemplate, the opaque inset still works.
-- A panel is BACKGROUND ARTWORK on the owning frame, never another child Frame.
-- Previous child Frames painted *over* the parent's font strings and made entire
-- bank / cart / synchronization views look disabled. Keep the original WoW layers.
local function panelSurface(parent,x,y,w,h,bright)
  local fill=parent:CreateTexture(nil,'BACKGROUND',nil,1)
  fill:SetTexture('Interface\\DialogFrame\\UI-DialogBox-Background')
  fill:SetVertexColor(bright and .83 or .73,bright and .78 or .70,bright and .69 or .60,1)
  fill:SetPoint('TOPLEFT',parent,'TOPLEFT',x+3,y-3)
  fill:SetSize(w-6,h-6)
  local function rule(dx,dy,rw,rh,r,g,b,a)
    local t=parent:CreateTexture(nil,'BORDER')
    t:SetTexture('Interface\\Buttons\\WHITE8X8')
    t:SetVertexColor(r,g,b,a)
    t:SetPoint('TOPLEFT',parent,'TOPLEFT',x+dx,y+dy)
    t:SetSize(rw,rh)
    return t
  end
  -- Etched gold-grey inset edges, consistent with the pre-Cataclysm auction UI.
  rule(0,0,w,2,.46,.37,.24,.90)
  rule(0,-h+2,w,2,.44,.38,.29,.90)
  rule(0,0,2,h,.38,.34,.27,.87)
  rule(w-2,0,2,h,.47,.40,.29,.87)
  rule(3,-3,w-6,1,.08,.07,.05,.88)
  rule(3,-h+4,w-6,1,.07,.06,.05,.88)
  return fill
end
-- Static labels keep their German source text so the language can be switched
-- after SavedVariables load (ADDON_LOADED) or via /banklord lang.
local localized={}
local function track(font,source,fitWidth)
  if type(source)=='string' and source~='' then
    localized[#localized+1]={font=font,source=source,fit=fitWidth}
  end
end
local function text(parent,size,x,y,w,message,color)
  local f=parent:CreateFontString(nil,'OVERLAY',size or 'GameFontNormal')
  f:SetPoint('TOPLEFT',parent,'TOPLEFT',x,y)
  f:SetWidth(w)
  f:SetJustifyH('LEFT')
  if type(f.SetJustifyV)=='function' then f:SetJustifyV('TOP') end
  f:SetText(A:Tr(message or ''))
  track(f,message)
  if color then f:SetTextColor(rgb(color)) end
  return f
end
-- Font metrics and UTF-8 safe shortening prevent long names escaping their column.
local function prefixChars(s,n)
  local i=1;local chars=0
  while i<=#s and chars<n do
    local b=s:byte(i)
    local step=b>=240 and 4 or (b>=224 and 3 or (b>=192 and 2 or 1))
    if i+step-1>#s then break end
    i=i+step;chars=chars+1
  end
  return s:sub(1,i-1)
end
local function characterCount(s)
  local n=0
  for i=1,#s do
    local b=s:byte(i)
    if b<128 or b>=192 then n=n+1 end
  end
  return n
end
local function fitText(font,original,maxWidth,maxChars)
  local s=tostring(original or '')
  local count=characterCount(s)
  local limit=maxChars or count
  if count>limit then s=prefixChars(s,math.max(0,limit-3))..'...' end
  font:SetText(s)
  if type(font.GetStringWidth)=='function' and maxWidth and maxWidth>8 then
    local ok,actual=pcall(font.GetStringWidth,font)
    if ok and actual and actual>maxWidth then
      local low,high=0,characterCount(s)
      while low<high do
        local mid=math.floor((low+high+1)/2)
        local candidate=prefixChars(s,mid)..'...'
        font:SetText(candidate)
        local good,width=pcall(font.GetStringWidth,font)
        if good and width and width<=maxWidth then low=mid else high=mid-1 end
      end
      font:SetText(prefixChars(s,low)..'...')
    end
  end
end
local function push(parent,caption,x,y,w,h,handler)
  local b=CreateFrame('Button',nil,parent,'UIPanelButtonTemplate')
  b:SetPoint('TOPLEFT',parent,'TOPLEFT',x,y)
  b:SetSize(w,h)
  -- Template supplies native Blizzard pressed/disabled/highlight artwork.
  if type(b.SetText)=='function' then b:SetText('') end
  b.bg=backdrop(b,4,-4,w-8,h-8,.35,.27,.12,0)
  b.caption=b:CreateFontString(nil,'OVERLAY','GameFontNormalSmall')
  -- Center within the *button*, not inside a TOPLEFT text rectangle whose
  -- font baseline moves when the UI scale/font metrics change.
  b.caption:SetPoint('CENTER',b,'CENTER',0,0)
  b.caption:SetSize(w-18,h)
  b.caption:SetJustifyH('CENTER')
  b.caption:SetJustifyV('MIDDLE')
  fitText(b.caption,A:Tr(caption),w-19)
  track(b.caption,caption,w-19)
  -- Suppress Blizzard's HIGHLIGHT overlay which obscures custom label fonts.
  local oldHighlight=type(b.GetHighlightTexture)=='function' and b:GetHighlightTexture() or nil
  if oldHighlight and type(oldHighlight.SetAlpha)=='function' then oldHighlight:SetAlpha(0) end
  local hover=b:CreateTexture(nil,'ARTWORK')
  hover:SetTexture('Interface\\Buttons\\WHITE8X8')
  hover:SetVertexColor(1,.75,.32,.18)
  hover:SetPoint('TOPLEFT',b,'TOPLEFT',5,-4)
  hover:SetPoint('BOTTOMRIGHT',b,'BOTTOMRIGHT',-5,4)
  hover:Hide()
  b:SetScript('OnEnter',function(self)
    if not self.IsEnabled or self:IsEnabled() then
      hover:Show();self.caption:SetTextColor(1,.98,.78)
    end
  end)
  b:SetScript('OnLeave',function(self)
    hover:Hide()
    -- Active tabs immediately recover their selected foreground state.
    if self.blSelected~=nil then
      self.caption:SetTextColor(self.blSelected and 1 or .94,
        self.blSelected and .84 or .87,self.blSelected and .46 or .77)
    else self.caption:SetTextColor(.94,.87,.77) end
  end)
  b:SetScript('OnClick',handler)
  return b
end
local function entry(parent,x,y,w,maxLetters)
  local e=CreateFrame('EditBox',nil,parent,'InputBoxTemplate')
  e:SetPoint('TOPLEFT',parent,'TOPLEFT',x,y)
  e:SetSize(w,24)
  e:SetAutoFocus(false)
  e:SetMaxLetters(maxLetters or 100)
  e:SetText('')
  e:SetScript('OnEscapePressed',function(self)self:ClearFocus()end)
  return e
end
local function colorButton(b,chosen)
  b.bg:SetVertexColor(.40,.29,.10,chosen and .64 or 0)
  b.caption:SetTextColor(chosen and 1 or .94,chosen and .84 or .87,chosen and .46 or .77)
end
-- A single-line label owns a column inside its row. Horizontal edges are
-- measured from the hitbox, while the vertical origin follows its CENTER.
-- No text label is anchored to the scrolling frame or the overall window.
local function rowLabel(parent,face,left,right,height,align,color)
  local f=parent:CreateFontString(nil,'OVERLAY',face)
  f:SetPoint('LEFT',parent,'LEFT',left,0)
  f:SetPoint('RIGHT',parent,'RIGHT',-right,0)
  f:SetHeight(height)
  f:SetJustifyH(align or 'LEFT')
  if type(f.SetJustifyV)=='function' then f:SetJustifyV('MIDDLE') end
  if type(f.SetWordWrap)=='function' then f:SetWordWrap(false) end
  if type(f.SetMaxLines)=='function' then f:SetMaxLines(1) end
  if color then f:SetTextColor(rgb(color)) end
  return f
end
local function categoryButton(parent,w,h,handler)
  local b=CreateFrame('Button',nil,parent)
  b:SetSize(w,h)
  b.bg=b:CreateTexture(nil,'BACKGROUND')
  b.bg:SetTexture('Interface\\AuctionFrame\\UI-AuctionFrame-FilterBg')
  b.bg:SetTexCoord(0,.53125,0,.625)
  b.bg:SetAllPoints(b)
  -- Auction highlight is on ARTWORK; text is OVERLAY and never hidden.
  b.hover=b:CreateTexture(nil,'ARTWORK')
  b.hover:SetTexture('Interface\\Buttons\\WHITE8X8')
  b.hover:SetVertexColor(.75,.67,.51,.10)
  b.hover:SetAllPoints(b)
  b.hover:Hide()
  b.selectedEdges={}
  b.selectedGlows={}
  local function selectedGlow(x,y,ww,hh)
    local t=b:CreateTexture(nil,'ARTWORK')
    t:SetTexture('Interface\\Buttons\\WHITE8X8')
    t:SetBlendMode('ADD')
    t:SetVertexColor(1,.70,.23,.30)
    t:SetPoint('TOPLEFT',b,'TOPLEFT',x,y)
    t:SetSize(ww,hh)
    t:Hide()
    b.selectedGlows[#b.selectedGlows+1]=t
  end
  selectedGlow(0,0,w,5);selectedGlow(0,-h+5,w,5)
  selectedGlow(0,0,5,h);selectedGlow(w-5,0,5,h)
  local function selectedEdge(x,y,ww,hh)
    local t=b:CreateTexture(nil,'ARTWORK')
    t:SetTexture('Interface\\Buttons\\WHITE8X8')
    t:SetVertexColor(.95,.73,.32,1)
    t:SetPoint('TOPLEFT',b,'TOPLEFT',x,y)
    t:SetSize(ww,hh)
    t:Hide()
    b.selectedEdges[#b.selectedEdges+1]=t
  end
  selectedEdge(0,0,w,2);selectedEdge(0,-h+2,w,2)
  selectedEdge(0,0,2,h);selectedEdge(w-2,0,2,h)
  b:SetScript('OnEnter',function(self) self.hover:Show() end)
  b:SetScript('OnLeave',function(self) self.hover:Hide() end)
  -- The count and the category text are centered in their respective columns.
  -- Both columns follow this exact 26-unit row, including on scroll/resize.
  b.caption=rowLabel(b,'GameFontHighlightSmall',9,49,h,'LEFT',COLORS.bright)
  b.count=rowLabel(b,'GameFontNormalSmall',w-46,10,h,'RIGHT',COLORS.muted)
  b:SetScript('OnClick',handler)
  return b
end
local function latestScan()
  local scans=A.db and A.db.fullScans or {}
  return scans[#scans]
end
local function itemName(item)
  local found=type(item.itemLink)=='string' and item.itemLink:match('|h%[([^%]]+)%]|h')
  if found then return found end
  if type(GetItemInfo)=='function' then
    local ok,name=pcall(GetItemInfo,item.itemID)
    if ok and name then return name end
  end
  return A:Tr('Gegenstand #%s'):format(tostring(item.itemID))
end
local function itemProperties(id)
  if type(GetItemInfo)~='function' then return nil,nil,nil,nil end
  local ok,_,_,quality,level,_,class,subclass=pcall(GetItemInfo,id)
  if ok then return quality,level,class,subclass end
  return nil,nil,nil,nil
end
local function itemIcon(id,scanTexture)
  -- The bank-slot artwork is the accurate visual for scanned variants.
  if type(scanTexture)=='number' and scanTexture>=1 then return scanTexture end
  if type(scanTexture)=='string' and scanTexture:match('^[%w_\\/%.%-]+$') then return scanTexture end
  local icon
  if type(GetItemIcon)=='function' then
    local ok,value=pcall(GetItemIcon,id)
    if ok then icon=value end
  end
  return icon or 'Interface\\Icons\\INV_Misc_QuestionMark'
end
local function itemColor(quality)
  local c=type(ITEM_QUALITY_COLORS)=='table' and ITEM_QUALITY_COLORS[quality or -1]
  if c and c.r then return c.r,c.g,c.b end
  return .94,.94,.94
end
local function snapshotTime(scan)
  if not scan then return A:Tr('Keine Inventur') end
  local stamp=tonumber(scan.capturedAt)
  if stamp and stamp>0 and type(date)=='function' then
    return date(A:Tr('%d.%m.%Y %H:%M'),stamp)
  end
  return A:Tr('Zeitpunkt unbekannt')
end
local function cartInfo()
  local rows,n,total={},0,0
  local cart=A.db and A.db.localCartDraft or {}
  for key,item in pairs(cart) do
    if type(item)=='table' then
      n=n+1
      total=total+(tonumber(item.quantity) or 0)
      rows[#rows+1]={key=key,info=item}
    end
  end
  table.sort(rows,function(a,b)
    local an=(a.info.name or ''):lower();local bn=(b.info.name or ''):lower()
    if an==bn then return tostring(a.key)<tostring(b.key) end
    return an<bn
  end)
  return rows,n,total
end

local window=CreateFrame('Frame','BanklordWindow',UIParent)
window:SetSize(WINDOW_W,WINDOW_H)
window:SetPoint('CENTER',UIParent,'CENTER',0,0)
window:SetMovable(true)
if type(window.SetClampedToScreen)=='function' then window:SetClampedToScreen(true) end
window:EnableMouse(true)
window:RegisterForDrag('LeftButton')
window:SetScript('OnDragStart',function(self)self:StartMoving()end)
window:SetScript('OnDragStop',function(self)self:StopMovingOrSizing()end)
window:Hide()
if type(UISpecialFrames)=='table' then table.insert(UISpecialFrames,'BanklordWindow')end
local function fitWindowToViewport(self)
  if UIParent and UIParent.GetWidth and UIParent.GetHeight then
    local w,h=UIParent:GetWidth(),UIParent:GetHeight()
    if w and h and w>0 and h>0 then
      -- Only the root frame is scaled: every text column inherits one common
      -- scale and remains centered relative to its own parent control.
      self:SetScale(math.min(1,math.max(.1,math.min((w-30)/WINDOW_W,(h-30)/WINDOW_H))))
    end
  end
end
window:SetScript('OnShow',fitWindowToViewport)
-- Recalculate when the player changes UI scale without reopening the addon.
window:RegisterEvent('UI_SCALE_CHANGED')
window:SetScript('OnEvent',function(self,event)
  if event=='UI_SCALE_CHANGED' then fitWindowToViewport(self) end
end)
-- Custom full-width frame, not BasicFrameTemplateWithInset: the native top
-- header's narrow center piece was stretched/offset on this 1030-wide frame.
-- All edges and title anchors are relative to one frame at one UI scale.
window:SetFrameStrata('HIGH')
local frameFill=window:CreateTexture(nil,'BACKGROUND')
frameFill:SetAllPoints(window)
frameFill:SetTexture('Interface\\DialogFrame\\UI-DialogBox-Background')
frameFill:SetVertexColor(.73,.70,.65,1)
local function frameLine(anchor,x,y,w,h,r,g,b,a)
  local line=window:CreateTexture(nil,'BORDER')
  line:SetTexture('Interface\\Buttons\\WHITE8X8')
  line:SetVertexColor(r,g,b,a or 1)
  line:SetPoint('TOPLEFT',window,'TOPLEFT',x,y)
  line:SetSize(w,h)
  return line
end
frameLine('top',0,0,WINDOW_W,3,.46,.37,.23,1)
frameLine('bottom',0,-WINDOW_H+3,WINDOW_W,3,.46,.37,.23,1)
frameLine('left',0,0,3,WINDOW_H,.46,.37,.23,1)
frameLine('right',WINDOW_W-3,0,3,WINDOW_H,.46,.37,.23,1)
frameLine('top-inner',3,-3,WINDOW_W-6,1,.09,.08,.06,.98)
frameLine('header-divider',5,-37,WINDOW_W-10,1,.42,.34,.22,.9)
frameLine('tabs-divider',5,-105,WINDOW_W-10,1,.42,.34,.22,.85)
-- Small WoW-like gold corner accents use native rectangular textures.
frameLine('corner-left',5,-5,23,2,.70,.54,.31,1)
frameLine('corner-left-down',5,-5,2,18,.70,.54,.31,1)
frameLine('corner-right',WINDOW_W-28,-5,23,2,.70,.54,.31,1)
frameLine('corner-right-down',WINDOW_W-7,-5,2,18,.70,.54,.31,1)
local close=CreateFrame('Button',nil,window,'UIPanelCloseButton')
close:SetSize(29,29)
close:SetPoint('TOPRIGHT',window,'TOPRIGHT',-5,-5)
close:SetScript('OnClick',function()window:Hide()end)
local titleLabel=window:CreateFontString(nil,'OVERLAY','GameFontNormalLarge')
-- Use the original client-installed WoW display typeface. Never ship font files.
if type(titleLabel.SetFont)=='function' then
  local ok,applied=pcall(titleLabel.SetFont,titleLabel,'Fonts\\MORPHEUS.TTF',20,'OUTLINE')
  if (not ok or applied==false) and type(titleLabel.SetFontObject)=='function' then
    titleLabel:SetFontObject('GameFontNormalLarge')
  end
end
titleLabel:SetPoint('TOP',window,'TOP',0,-10)
titleLabel:SetSize(WINDOW_W-170,27)
titleLabel:SetJustifyH('CENTER')
titleLabel:SetJustifyV('MIDDLE')
if type(titleLabel.SetWordWrap)=='function' then titleLabel:SetWordWrap(false) end
if type(titleLabel.SetMaxLines)=='function' then titleLabel:SetMaxLines(1) end

local function refreshGuildTitle()
  local guildName=nil
  if type(GetGuildInfo)=='function' then
    local ok,name=pcall(GetGuildInfo,'player')
    if ok and type(name)=='string' and name~='' then guildName=name end
  end
  fitText(titleLabel,A:Tr('Gildenbank - %s - Banklord'):format(guildName or A:Tr('Gilde')),WINDOW_W-170,92)
end
refreshGuildTitle()
local subtitle=text(window,'GameFontHighlightSmall',23,-45,780,'Inventur wird geladen ...',COLORS.muted)
-- No duplicate status at the top-right: footer contains source and timestamp.
-- Removed stale topState reference: deleting the widget without this line crashed UI.lua on load.
local panels,tabs={},{}
local active='items'
-- Purely visual preference, persisted locally in SavedVariables.
local opaqueFill=window:CreateTexture(nil,'BACKGROUND',nil,-8)
opaqueFill:SetTexture('Interface\\Buttons\\WHITE8X8')
opaqueFill:SetAllPoints(window)
opaqueFill:SetVertexColor(.048,.042,.035,1)
local function updateWindowBackdrop()
  local settings=A.db and A.db.settings
  opaqueFill:SetAlpha(settings and settings.solidBackground==true and .98 or 0)
end

local tabDefs={{'items','Gegenstände'},{'cart','Warenkorb'},{'bank','Bankverwaltung'},{'help','Synchronisierung'},{'advanced','Erweitert'},{'feedback','Hilfe & Feedback'}}
for i,t in ipairs(tabDefs) do
  local key,caption=t[1],t[2]
  local b=push(window,caption,18+(i-1)*161,-72,153,29,function()
    active=key;A:RefreshUI()
  end)
  tabs[key]=b
  local panel=CreateFrame('Frame',nil,window)
  panel:SetPoint('TOPLEFT',window,'TOPLEFT',13,-110)
  panel:SetSize(1004,472)
  panels[key]=panel
end
backdrop(window,20,-599,987,1,.59,.47,.23,1)
local footerLeft=text(window,'GameFontNormalSmall',22,-612,745,
  'Lokale Daten • nicht live • Bestellungen werden erst durch den Server verbindlich.',COLORS.warn)
local footerCart=push(window,'Warenkorb öffnen',803,-606,199,30,function()
  active='cart';A:RefreshUI()
end)

-- Browse pane: category tree + sortable, icon-based results + persistent details.
local catalog=panels.items
panelSurface(catalog,0,0,194,469)
panelSurface(catalog,202,0,534,469)
panelSurface(catalog,744,0,260,469,true)
text(catalog,'GameFontNormalLarge',17,-14,164,'Kategorien',COLORS.gold)
text(catalog,'GameFontNormalLarge',220,-14,480,'Gildenbank durchsuchen',COLORS.gold)
text(catalog,'GameFontNormalLarge',763,-14,225,'Gegenstand',COLORS.gold)

-- Custom unobstructed scroll track. The stock UIPanelScrollFrameTemplate
-- anchors arrow buttons outside the scroll region, across our panel border;
-- it also puts its thumb in a narrow strip with a mismatched width.
local categoryScroll=CreateFrame('ScrollFrame',nil,catalog)
categoryScroll:SetPoint('TOPLEFT',catalog,'TOPLEFT',12,-47)
categoryScroll:SetSize(155,407)
local categoryContent=CreateFrame('Frame',nil,categoryScroll)
categoryContent:SetSize(155,407)
categoryScroll:SetScrollChild(categoryContent)
local categorySlider=CreateFrame('Slider',nil,catalog)
categorySlider:SetOrientation('VERTICAL')
categorySlider:SetPoint('TOPLEFT',catalog,'TOPLEFT',174,-49)
categorySlider:SetSize(13,402)
categorySlider:SetMinMaxValues(0,0)
categorySlider:SetValue(0)
-- Compact WoW-style scrollbar instead of a solid gold rectangular strip.
local sliderTrack=categorySlider:CreateTexture(nil,'BACKGROUND')
sliderTrack:SetTexture('Interface\\Buttons\\WHITE8X8')
sliderTrack:SetVertexColor(.23,.19,.13,.85)
sliderTrack:SetPoint('TOP',categorySlider,'TOP',0,-2)
sliderTrack:SetPoint('BOTTOM',categorySlider,'BOTTOM',0,2)
sliderTrack:SetWidth(5)
-- Native slider remains the scroll input. Draw a visible thumb ourselves:
-- several 5.5.4 clients fail to paint the custom SetThumbTexture(Texture).
categorySlider:SetThumbTexture('Interface\\Buttons\\WHITE8X8')
local stockThumb=categorySlider:GetThumbTexture()
if stockThumb then stockThumb:SetAlpha(0) end
local sliderThumb=categorySlider:CreateTexture(nil,'OVERLAY')
sliderThumb:SetTexture('Interface\\AuctionFrame\\UI-AuctionFrame-FilterBg')
sliderThumb:SetTexCoord(0,.53125,0,.625)
sliderThumb:SetVertexColor(.69,.68,.67,.95)
sliderThumb:SetSize(13,28)
local thumbFrame=CreateFrame('Frame',nil,categorySlider)
thumbFrame:SetSize(13,28)
thumbFrame:SetFrameLevel(categorySlider:GetFrameLevel()+1)
sliderThumb:SetParent(thumbFrame)
sliderThumb:ClearAllPoints()
sliderThumb:SetAllPoints(thumbFrame)
local function thumbBorder(x,y,w,h)
  local edge=thumbFrame:CreateTexture(nil,'OVERLAY')
  edge:SetTexture('Interface\\Buttons\\WHITE8X8')
  edge:SetVertexColor(.49,.45,.39,.9)
  edge:SetPoint('TOPLEFT',thumbFrame,'TOPLEFT',x,y)
  edge:SetSize(w,h)
end
thumbBorder(0,0,13,1);thumbBorder(0,-27,13,1)
thumbBorder(0,0,1,28);thumbBorder(12,0,1,28)
local function updateCategoryThumb(value)
  local _,maximum=categorySlider:GetMinMaxValues()
  local track=categorySlider:GetHeight()-thumbFrame:GetHeight()
  local offset=maximum>0 and math.max(0,math.min(1,value/maximum))*track or 0
  thumbFrame:ClearAllPoints()
  thumbFrame:SetPoint('TOP',categorySlider,'TOP',0,-offset)
end
categorySlider:SetScript('OnValueChanged',function(_,v)
  categoryScroll:SetVerticalScroll(v)
  updateCategoryThumb(v)
end)
updateCategoryThumb(0)
categorySlider:SetScript('OnMouseWheel',function(self,delta)
  self:SetValue(math.max(0,math.min(select(2,self:GetMinMaxValues()),self:GetValue()-delta*56)))
end)
categoryScroll:EnableMouseWheel(true)
categoryScroll:SetScript('OnMouseWheel',function(_,delta)
  categorySlider:SetValue(math.max(0,math.min(select(2,categorySlider:GetMinMaxValues()),categorySlider:GetValue()-delta*56)))
end)

local categoryButtons={}
local activeCategory='all'
local expandedCategories={}
local categories={}
local categoryCounts={}
local function buildCategories(items)
  local count,byClass={},{}
  for _,item in ipairs(items) do
    local class=item.class or A:Tr('Weitere Gegenstände')
    local sub=item.subclass
    count[class]=(count[class] or 0)+1
    if sub and sub~='' and sub~=class then
      local key=class..'\031'..sub
      byClass[key]=(byClass[key] or 0)+1
    end
  end
  local keys={}
  for class in pairs(count) do keys[#keys+1]=class end
  table.sort(keys)
  local result={{key='all',caption=A:Tr('Alle Gegenstände'),count=#items,depth=0}}
  for _,class in ipairs(keys) do
    result[#result+1]={key=class,caption=class,count=count[class],depth=0}
    local subs={}
    for key,qty in pairs(byClass) do
      local prefix=class..'\031'
      if key:sub(1,#prefix)==prefix then
        subs[#subs+1]={key=key,caption=key:sub(#prefix+1),count=qty,depth=1}
      end
    end
    table.sort(subs,function(a,b)return a.caption<b.caption end)
    if expandedCategories[class] then
      for _,sub in ipairs(subs) do result[#result+1]=sub end
    end
  end
  return result
end
local searchLabel=text(catalog,'GameFontNormalSmall',220,-46,55,'Suche:',COLORS.muted)
local search=entry(catalog,280,-40,256,90)
local reset=push(catalog,'X',547,-40,32,24,function()search:SetText('');activeCategory='all';A:RefreshUI()end)
local sortMode='name'
local sortButton=push(catalog,'Name',589,-40,130,24,function()
  sortMode=sortMode=='name' and 'stock' or 'name';A:RefreshUI()
end)
text(catalog,'GameFontHighlightSmall',225,-82,340,'Gegenstand',COLORS.gold)
text(catalog,'GameFontHighlightSmall',640,-82,74,'Bestand',COLORS.gold)
backdrop(catalog,214,-99,516,1,.55,.47,.29,.9)
local results,filtered,catalogItems,selectedKey={},{},{},nil
local itemOffset=0
local ROWS=9
local rowHeight=36
for i=1,ROWS do
  local n=i
  local r=CreateFrame('Button',nil,catalog)
  r:SetSize(513,rowHeight-1)
  r:SetPoint('TOPLEFT',catalog,'TOPLEFT',214,-103-(i-1)*rowHeight)
  r.bg=backdrop(r,0,0,513,rowHeight-1,(i%2==0 and .08 or .10),.08,.075,.93)
  r.hover=r:CreateTexture(nil,'ARTWORK')
  r.hover:SetTexture('Interface\\QuestFrame\\UI-QuestTitleHighlight')
  r.hover:SetBlendMode('ADD')
  r.hover:SetAllPoints(r)
  r.hover:SetAlpha(.31)
  r.hover:Hide()
  r.icon=r:CreateTexture(nil,'ARTWORK')
  r.icon:SetSize(30,30)
  r.icon:SetPoint('LEFT',r,'LEFT',5,0)
  r.name=rowLabel(r,'GameFontHighlight',42,96,31,'LEFT',COLORS.bright)
  r.stock=rowLabel(r,'GameFontHighlightSmall',430,9,31,'RIGHT',COLORS.green)
  r:SetScript('OnClick',function()
    local item=filtered[itemOffset+n]
    if not item then return end
    if IsShiftKeyDown and IsShiftKeyDown() and item.link and type(ChatEdit_InsertLink)=='function' then
      ChatEdit_InsertLink(item.link)
      return
    end
    selectedKey=item.key
    A:RefreshUI()
  end)
  r:SetScript('OnEnter',function(self)
    self.hover:Show()
    local item=filtered[itemOffset+n]
    if item and GameTooltip then
      GameTooltip:SetOwner(self,'ANCHOR_RIGHT')
      if GameTooltip.SetHyperlink then GameTooltip:SetHyperlink(item.link or ('item:'..tostring(item.id)))end
      GameTooltip:Show()
    end
  end)
  r:SetScript('OnLeave',function(self)
    self.hover:Hide()
    if GameTooltip then GameTooltip:Hide() end
  end)
  results[i]=r
end
catalog:EnableMouseWheel(true)
catalog:SetScript('OnMouseWheel',function(_,delta)
  if active~='items' then return end
  itemOffset=math.max(0,math.min(math.max(0,#filtered-ROWS),itemOffset-(delta>0 and 3 or -3)))
  A:RefreshUI()
end)
local pager=text(catalog,'GameFontNormalSmall',221,-436,301,'',COLORS.muted)
local prev=push(catalog,'Zurück',537,-430,89,26,function()
  itemOffset=math.max(0,itemOffset-ROWS);A:RefreshUI()
end)
local nextButton=push(catalog,'Weiter',630,-430,95,26,function()
  itemOffset=math.min(math.max(0,#filtered-ROWS),itemOffset+ROWS);A:RefreshUI()
end)

local lastDetailKey=nil
local detailIcon=catalog:CreateTexture(nil,'ARTWORK')
detailIcon:SetPoint('TOPLEFT',catalog,'TOPLEFT',762,-49)
detailIcon:SetSize(49,49)
local detailTooltipButton=CreateFrame('Button',nil,catalog)
detailTooltipButton:SetPoint('TOPLEFT',catalog,'TOPLEFT',762,-49)
detailTooltipButton:SetSize(230,52)
detailTooltipButton:SetFrameLevel(catalog:GetFrameLevel()+3)
detailTooltipButton:SetScript('OnEnter',function(self)
  local item=self.currentItem
  if item and GameTooltip then
    GameTooltip:SetOwner(self,'ANCHOR_CURSOR_RIGHT')
    GameTooltip:SetHyperlink(item.link or ('item:'..tostring(item.id)))
    GameTooltip:Show()
  end
end)
detailTooltipButton:SetScript('OnLeave',function()
  if GameTooltip then GameTooltip:Hide() end
end)
local detailName=text(catalog,'GameFontHighlight',819,-54,172,'Gegenstand auswählen',COLORS.bright)
detailName:SetHeight(44)
if type(detailName.SetMaxLines)=='function' then detailName:SetMaxLines(2) end
if type(detailName.SetWordWrap)=='function' then detailName:SetWordWrap(true) end
local detailType=text(catalog,'GameFontNormalSmall',762,-124,226,'',COLORS.muted)
detailType:SetHeight(42)
local detailLevel=text(catalog,'GameFontNormalSmall',762,-172,223,'',COLORS.muted)
local detailStock=text(catalog,'GameFontNormal',762,-203,225,'',COLORS.green)
text(catalog,'GameFontNormalSmall',762,-238,222,'Menge vormerken',COLORS.gold)
local qty=entry(catalog,806,-262,82,7)
qty:SetNumeric(true);qty:SetText('1')
push(catalog,'-',765,-262,33,26,function()
  qty:SetText(tostring(math.max(1,(tonumber(qty:GetText()) or 1)-1)))
end)
push(catalog,'+',899,-262,33,26,function()
  qty:SetText(tostring(math.min(1000000,(tonumber(qty:GetText()) or 1)+1)))
end)
local addButton=push(catalog,'Zum Warenkorb',761,-307,225,33,function()
  local chosen
  for _,item in ipairs(catalogItems) do if item.key==selectedKey then chosen=item;break end end
  if not chosen then A:Message(A:Tr('Zuerst einen Gegenstand auswählen.'));return end
  local number=tonumber(qty:GetText())
  if not number or number<1 or number>1000000 or number%1~=0 then
    A:Message(A:Tr('Bitte eine gültige Menge zwischen 1 und 1000000 angeben.'));return
  end
  A:AddCartDraftByKey(chosen.key,number)
  A:RefreshUI()
end)
text(catalog,'GameFontNormalSmall',763,-355,226,
  'Im Reiter Warenkorb mit „Bestellung absenden“\nverbindlich beim Server anfragen.',COLORS.warn)
local detailSnapshot=text(catalog,'GameFontNormalSmall',763,-418,230,'',COLORS.muted)

-- Cart: position list, individual removal, existing transfer and peer functions.
local cart=panels.cart
panelSurface(cart,0,0,1004,469)
text(cart,'GameFontNormalLarge',16,-12,560,'Mein Warenkorb',COLORS.gold)
local cartSummary=text(cart,'GameFontNormalSmall',600,-15,385,'',COLORS.muted)
text(cart,'GameFontNormalSmall',18,-51,533,'Gegenstand',COLORS.muted)
text(cart,'GameFontNormalSmall',478,-51,100,'Menge',COLORS.muted)
text(cart,'GameFontNormalSmall',740,-52,240,'Status',COLORS.muted)
backdrop(cart,14,-71,974,1,.42,.38,.27,1)
local cartButtons={}
local cartOffset=0
for i=1,8 do
  local n=i
  local r=CreateFrame('Frame',nil,cart)
  r:SetSize(691,34)
  r:SetPoint('TOPLEFT',cart,'TOPLEFT',16,-80-(i-1)*37)
  r.bg=backdrop(r,0,0,690,34,i%2==0 and .11 or .15,.11,.09,.88)
  r.icon=r:CreateTexture(nil,'ARTWORK')
  r.icon:SetPoint('LEFT',r,'LEFT',5,0)
  r.icon:SetSize(27,27)
  r.name=rowLabel(r,'GameFontHighlight',39,249,30,'LEFT',COLORS.bright)
  r.count=rowLabel(r,'GameFontHighlight',471,148,30,'RIGHT',COLORS.bright)
  r.del=push(r,'Entfernen',552,-3,127,28,function()
    local items=cartInfo()
    local target=items[cartOffset+n]
    if target then
      A.db.localCartDraft[target.key]=nil
      A:RefreshUI()
    end
  end)
  cartButtons[i]=r
end
cart:EnableMouseWheel(true)
cart:SetScript('OnMouseWheel',function(_,delta)
  if active~='cart' then return end
  local items=cartInfo()
  cartOffset=math.max(0,math.min(math.max(0,#items-8),cartOffset-(delta>0 and 4 or -4)))
  A:RefreshUI()
end)
local cartPage=text(cart,'GameFontNormalSmall',20,-395,230,'',COLORS.muted)
push(cart,'<',258,-390,35,25,function()cartOffset=math.max(0,cartOffset-8);A:RefreshUI()end)
push(cart,'>',301,-390,35,25,function()
  local items=cartInfo();cartOffset=math.min(math.max(0,#items-8),cartOffset+8);A:RefreshUI()
end)
push(cart,'Zurück zum Katalog',355,-390,202,26,function()active='items';A:RefreshUI()end)
push(cart,'Warenkorb leeren',560,-390,157,26,function()
  A:ClearCartDraft();A:RefreshUI()
end)
panelSurface(cart,726,-78,262,344)
text(cart,'GameFontNormal',740,-91,238,'Meine Bestellungen',COLORS.gold)
local orderRows={}
for i=1,8 do
  local r=CreateFrame('Button',nil,cart)
  r:SetPoint('TOPLEFT',cart,'TOPLEFT',736,-114-(i-1)*31)
  r:SetSize(244,29)
  r:RegisterForClicks('RightButtonUp')
  local hl=r:CreateTexture(nil,'HIGHLIGHT');hl:SetAllPoints();hl:SetColorTexture(.97,.74,.27,.08)
  r.title=text(r,'GameFontNormalSmall',4,-2,236,'',COLORS.gold)
  r.status=text(r,'GameFontHighlightSmall',4,-15,236,'',COLORS.bright)
  r:SetScript('OnEnter',function(self)A:ShowOrderTooltip(self,self.row)end)
  r:SetScript('OnLeave',function()if GameTooltip then GameTooltip:Hide()end end)
  r:SetScript('OnClick',function(self)
    if self.row and self.row.failure then A:DismissOrderFailures();A:RefreshUI() end
  end)
  orderRows[i]=r
end
local orderEmpty=text(cart,'GameFontHighlightSmall',741,-120,229,
  'Noch keine Bestellungen.\n\nWarenkorb füllen und unten auf „Bestellung absenden“ klicken. Der Companion überträgt sie nach /reload oder Logout.',COLORS.muted)
local orderStamp=text(cart,'GameFontDisableSmall',741,-363,229,'',COLORS.muted)
local submitButton=push(cart,'Bestellung absenden',740,-385,234,30,function()A:SubmitCartOrder()end)
local transfer=entry(cart,20,-438,488,4000)
transfer:SetScript('OnEditFocusGained',function(self)self:HighlightText()end)
push(cart,'Code erzeugen',519,-437,155,27,function()
  local code,err=A:BuildCartTransferCode()
  if not code then A:Message(err or A:Tr('Übertragung nicht möglich.'));return end
  transfer:SetText(code);transfer:SetFocus();transfer:HighlightText()
  A:Message(A:Tr('Übertragungscode markiert: mit Strg+C kopieren. Keine Serverbestellung!'))
end)
local peerTarget=entry(cart,743,-438,140,95)
push(cart,'An Spieler',895,-437,96,27,function()
  A:SendCartToPeer(peerTarget:GetText())
end)

-- Additional UI settings. No influence on banking operations or permissions.
local advanced=panels.advanced
panelSurface(advanced,0,0,1004,469)
text(advanced,'GameFontNormalLarge',22,-19,600,'Erweitert – Darstellung',COLORS.gold)
text(advanced,'GameFontHighlight',26,-69,924,
  'Wähle, wie der Hintergrund des Addon-Fensters dargestellt werden soll.',COLORS.bright)
local backgroundOption=CreateFrame('CheckButton','BanklordBackgroundCheckbox',advanced,'UICheckButtonTemplate')
backgroundOption:SetPoint('TOPLEFT',advanced,'TOPLEFT',28,-114)
backgroundOption:SetSize(28,28)
local backgroundText=advanced:CreateFontString(nil,'OVERLAY','GameFontHighlight')
backgroundText:SetPoint('LEFT',backgroundOption,'RIGHT',11,0)
backgroundText:SetText(A:Tr('Ausgefüllter, blickdichter Hintergrund'))
track(backgroundText,'Ausgefüllter, blickdichter Hintergrund')
local checkHighlight=backgroundOption:CreateTexture(nil,'OVERLAY')
checkHighlight:SetTexture('Interface\\Buttons\\WHITE8X8')
checkHighlight:SetPoint('TOPLEFT',backgroundOption,'TOPLEFT',1,-1)
checkHighlight:SetSize(26,26)
checkHighlight:SetVertexColor(.97,.74,.27,.14)
checkHighlight:Hide()
backgroundOption:SetScript('OnEnter',function() checkHighlight:Show();backgroundText:SetTextColor(1,.91,.64) end)
backgroundOption:SetScript('OnLeave',function() checkHighlight:Hide();backgroundText:SetTextColor(1,1,1) end)
backgroundOption:SetScript('OnClick',function(self)
  if not A.db then return end
  A.db.settings=A.db.settings or {}
  A.db.settings.solidBackground=self:GetChecked()==true
  updateWindowBackdrop()
  A:RefreshUI()
end)
text(advanced,'GameFontHighlightSmall',29,-178,920,
  'Deaktiviert: transparente Ansicht mit sichtbarer Spielwelt im Hintergrund.\n'..
  'Aktiviert: vollständig dunkle Fensterfläche, besser lesbar in hellen Spielbereichen.\n'..
  'Die Einstellung wird pro WoW-Konto gespeichert. Beim nächsten Spielstart bleibt sie erhalten.',COLORS.muted)

-- Bank scanner: preserve all existing actions and transparency.
local bank=panels.bank
panelSurface(bank,0,0,1004,469)
text(bank,'GameFontNormalLarge',22,-20,760,'Inventur & Bankscanner',COLORS.gold)
local bankState=text(bank,'GameFontHighlight',24,-70,927,'',COLORS.bright)
local bankProgress=text(bank,'GameFontHighlightSmall',24,-161,917,'',COLORS.muted)
push(bank,'Alle Fächer scannen',25,-225,268,35,function()A:StartScan('all');A:RefreshUI()end)
push(bank,'Aktuelles Fach scannen',311,-225,268,35,function()A:StartSingleScan();A:RefreshUI()end)
push(bank,'Scan abbrechen',597,-225,211,35,function()
  if A.scanRun then A:AbortScan('Manuell abgebrochen')end
  A:RefreshUI()
end)
-- Open orders for bank managers: what is waiting, for whom, and who is on it.
local bankOrdersTitle=text(bank,'GameFontNormal',26,-278,600,'Offene Bestellungen',COLORS.gold)
local bankOrdersHint=text(bank,'GameFontHighlightSmall',26,-298,940,
  'An der Gildenbank „Entnehmen“, am Briefkasten „Versenden“. Neue Bestellungen von der Website erscheinen nach /reload.',COLORS.muted)
local bankOrderRows={}
for i=1,6 do
  bankOrderRows[i]=text(bank,'GameFontHighlightSmall',30,-322-(i-1)*20,940,'',COLORS.bright)
end
local bankOrdersMore=text(bank,'GameFontHighlightSmall',30,-446,940,'',COLORS.muted)

-- Sync: instructions and existing peer inbox/outbox without making live promises.
local help=panels.help
panelSurface(help,0,0,1004,469)
text(help,'GameFontNormalLarge',22,-20,760,'Synchronisierung & Übertragung',COLORS.gold)
text(help,'GameFontNormal',26,-76,925,
  'Dein Addon speichert Scans und Entwürfe lokal. Erst nach Prüfung im Web gelten sie als bestätigt.',COLORS.warn)
text(help,'GameFontHighlightSmall',26,-121,943,
  '1. Gildenbank öffnen und Inventur starten.\n\n'..
  '2. /reload oder ausloggen, damit WoW die SavedVariables auf die Festplatte schreibt.\n\n'..
  '3. Der optionale Windows-Companion überträgt gespeicherte Daten per HTTPS.\n\n'..
  '4. Verwalter bestätigen importierte Inventuren und Bestellungen über die Website.\n\n'..
  'Offline-Ingame-Entwürfe können an ONLINE-Spieler weitergegeben werden, sind aber keine Webaufträge.\n'..
  'Direkte Live-Kommunikation des laufenden Addons mit dem Webserver ist derzeit nicht verfügbar.',COLORS.bright)
local peerStatus=text(help,'GameFontHighlightSmall',27,-368,700,'',COLORS.muted)
push(help,'Eingänge anzeigen',28,-411,192,31,function()A:ShowPeerInbox()end)
push(help,'Ausgänge anzeigen',234,-411,192,31,function()A:ShowPeerOutbox()end)
push(help,'Zustellung erneut versuchen',440,-411,256,31,function()A:RetryPendingPeers(true)end)


-- Help & feedback: three clear choices, one short form, the own reports beside it.
local fb=panels.feedback
panelSurface(fb,0,0,1004,469)
text(fb,'GameFontNormalLarge',22,-16,470,'Hilfe & Feedback',COLORS.gold)
text(fb,'GameFontHighlightSmall',22,-42,470,'Deine Meldung geht an das Banklord-Projekt. Keine Konto- oder Charakterdaten.',COLORS.muted)
local fbKind,fbArea,fbHelp='bug',1,1
local fbKindButtons={}
for i,k in ipairs(A.FeedbackKinds) do
  fbKindButtons[k.key]=push(fb,k.caption,22+(i-1)*158,-64,150,30,function() fbKind=k.key;A:RefreshUI() end)
end
local fbKindHint=text(fb,'GameFontHighlightSmall',24,-100,470,'',COLORS.muted)
local fbAreaButton=push(fb,'',22,-124,230,26,function() fbArea=fbArea%#A.FeedbackAreas+1;A:RefreshUI() end)
local fbHelpButton=push(fb,'',262,-124,230,26,function() fbHelp=fbHelp%#A.FeedbackHelp+1;A:RefreshUI() end)
local fbTitleLabel=text(fb,'GameFontNormalSmall',24,-160,470,'Kurzer Titel',COLORS.gold)
local fbTitle=entry(fb,28,-176,462,120)
local fbTextLabel=text(fb,'GameFontNormalSmall',24,-210,470,'',COLORS.gold)
backdrop(fb,22,-228,470,166,.03,.028,.024,.92)
local fbScroll=CreateFrame('ScrollFrame','BanklordFeedbackScroll',fb,'UIPanelScrollFrameTemplate')
fbScroll:SetPoint('TOPLEFT',fb,'TOPLEFT',28,-233)
fbScroll:SetSize(436,156)
local fbText=CreateFrame('EditBox','BanklordFeedbackText',fbScroll)
if fbText.SetMultiLine then fbText:SetMultiLine(true) end
if fbText.SetFontObject then fbText:SetFontObject(ChatFontNormal or GameFontHighlight) end
fbText:SetWidth(430);fbText:SetHeight(156)
fbText:SetAutoFocus(false)
fbText:SetMaxLetters(2000)
fbText:SetScript('OnEscapePressed',function(self) self:ClearFocus() end)
if fbScroll.SetScrollChild then fbScroll:SetScrollChild(fbText) end
local fbFocus=CreateFrame('Button',nil,fb)
fbFocus:SetPoint('TOPLEFT',fb,'TOPLEFT',22,-228);fbFocus:SetSize(470,166)
if fbFocus.SetFrameLevel then fbFocus:SetFrameLevel(math.max(1,(fbScroll:GetFrameLevel() or 2)-1)) end
fbFocus:SetScript('OnClick',function() fbText:SetFocus() end)
text(fb,'GameFontHighlightSmall',24,-400,470,'Wird beim nächsten /reload über den Companion gesendet. Bitte niemals Passwörter eintragen.',COLORS.muted)
push(fb,'Absenden',22,-430,170,30,function()
  local f={area=A.FeedbackAreas[fbArea].key,help=A.FeedbackHelp[fbHelp].key,text=fbText:GetText()}
  if fbKind=='language' then f.language=fbTitle:GetText() else f.title=fbTitle:GetText() end
  local ok,why=A:QueueFeedback(fbKind,f)
  if not ok then A:Message(why);return end
  fbTitle:SetText('');fbText:SetText('');fbTitle:ClearFocus();fbText:ClearFocus()
  A:Message(A:Tr('Danke! Deine Meldung wird beim nächsten /reload gesendet.'))
  A:RefreshUI()
end)
push(fb,'Leeren',202,-430,120,30,function() fbTitle:SetText('');fbText:SetText('') end)
-- Right side: own reports with status and answer.
backdrop(fb,512,-14,1,440,.42,.34,.22,.7)
text(fb,'GameFontNormal',530,-18,450,'Meine Meldungen',COLORS.gold)
text(fb,'GameFontHighlightSmall',530,-38,450,'Status und Antworten erscheinen nach dem nächsten /reload.',COLORS.muted)
local fbRows={}
for i=1,8 do
  local r={}
  r.title=text(fb,'GameFontHighlight',530,-62-(i-1)*48,330,'',COLORS.bright)
  r.status=text(fb,'GameFontNormalSmall',870,-62-(i-1)*48,115,'',COLORS.green)
  r.reply=text(fb,'GameFontHighlightSmall',540,-80-(i-1)*48,440,'',COLORS.muted)
  fbRows[i]=r
end
local fbEmpty=text(fb,'GameFontHighlightSmall',530,-64,450,'Du hast noch nichts gemeldet.',COLORS.muted)
local fbHints={bug='Was ist passiert? Wie kann man es nachstellen?',idea='Was soll besser werden, und wie stellst du es dir vor?',language='Anmerkungen (optional)'}
local fbIcons={bug='|TInterface\\Icons\\INV_Misc_Bug_02:14|t ',idea='|TInterface\\Icons\\INV_Misc_Note_01:14|t ',language='|TInterface\\Icons\\INV_Misc_Book_09:14|t '}
local function refreshFeedback()
  for _,k in ipairs(A.FeedbackKinds) do
    local b=fbKindButtons[k.key];b.blSelected=k.key==fbKind;colorButton(b,b.blSelected)
    if k.key==fbKind then fbKindHint:SetText(A:Tr(k.hint)) end
  end
  fitText(fbAreaButton.caption,A:Tr('Betrifft: %s'):format(A:Tr(A.FeedbackAreas[fbArea].caption)),210)
  fitText(fbHelpButton.caption,A:Tr('Hilfe beim Übersetzen: %s'):format(A:Tr(A.FeedbackHelp[fbHelp].caption)),210)
  if fbKind=='language' then fbHelpButton:Show() else fbHelpButton:Hide() end
  fbTitleLabel:SetText(A:Tr(fbKind=='language' and 'Welche Sprache?' or 'Kurzer Titel'))
  fbTextLabel:SetText(A:Tr(fbHints[fbKind]))
  local rows=A:FeedbackOverview()
  for i,r in ipairs(fbRows) do
    local row=rows[i]
    if row then
      fitText(r.title,(fbIcons[row.kind] or '')..(row.number and ('#'..row.number..' ') or '')..tostring(row.title or ''),330,60)
      r.status:SetText(A:Tr(A.FeedbackStatus[row.status] or tostring(row.status)))
      fitText(r.reply,row.reply~='' and A:Tr('Antwort: %s'):format(row.reply) or '',440,90)
      r.title:Show();r.status:Show();r.reply:Show()
    else r.title:Hide();r.status:Hide();r.reply:Hide() end
  end
  if #rows==0 then fbEmpty:Show() else fbEmpty:Hide() end
end
A._refreshFeedbackPanel=refreshFeedback

search:SetScript('OnTextChanged',function(self,userInput)
  if userInput then itemOffset=0;A:RefreshUI()end
end)
search:SetScript('OnEnterPressed',function(self)self:ClearFocus()end)

local function buildItemIndex(scan)
  local items={}
  for _,raw in ipairs(scan and scan.items or {}) do
    local quality,level,class,subclass=itemProperties(raw.itemID)
    items[#items+1]={
      id=raw.itemID,key=raw.itemKey,link=raw.itemLink,name=itemName(raw),
      count=tonumber(raw.count) or 0,quality=quality,level=level,
      class=class or A:Tr('Weitere Gegenstände'),subclass=subclass,
      iconTexture=raw.iconTexture,
    }
  end
  return items
end
local function repaintCategories()
  categories=buildCategories(catalogItems)
  local selectedFound=false
  for _,row in ipairs(categories) do if row.key==activeCategory then selectedFound=true end end
  if not selectedFound then activeCategory='all' end
  for i,item in ipairs(categories) do
    local b=categoryButtons[i]
    if not b then
      b=categoryButton(categoryContent,154,26,function(self)
        local candidate=categories[self.index]
        if candidate then
          if candidate.depth==0 and candidate.key~='all' then
            expandedCategories[candidate.key]=not expandedCategories[candidate.key]
          end
          activeCategory=candidate.key
          itemOffset=0
          A:RefreshUI()
        end
      end)
      categoryButtons[i]=b
    end
    b.index=i
    b:ClearAllPoints()
    b:SetPoint('TOPLEFT',categoryContent,'TOPLEFT',0,-(i-1)*28)
    local prefix=item.depth==1 and '   ' or (item.key=='all' and '' or (expandedCategories[item.key] and '- ' or '+ '))
    fitText(b.caption,prefix..item.caption,94,36)
    b.count:SetText(tostring(item.count))
    -- Exact same stone for every category; active = gold frame, not yellow fill.
    b.bg:SetVertexColor(.82,.80,.78,.97)
    for _,edge in ipairs(b.selectedEdges) do
      if item.key==activeCategory then edge:Show() else edge:Hide() end
    end
    for _,glow in ipairs(b.selectedGlows) do
      if item.key==activeCategory then glow:Show() else glow:Hide() end
    end
    b.caption:SetTextColor(.95,.91,.84)
    b.count:SetTextColor(item.key==activeCategory and 1 or .84,
      item.key==activeCategory and .84 or .78,item.key==activeCategory and .46 or .68)
    b:Show()
  end
  for i=#categories+1,#categoryButtons do categoryButtons[i]:Hide()end
  local contentHeight=math.max(407,#categories*28)
  categoryContent:SetHeight(contentHeight)
  local maximum=math.max(0,contentHeight-categoryScroll:GetHeight())
  categorySlider:SetMinMaxValues(0,maximum)
  categorySlider:SetValue(math.max(0,math.min(maximum,categorySlider:GetValue())))
  -- Stone thumb keeps a constant 34-unit shape, matching the Auction filter tile.
  updateCategoryThumb(categorySlider:GetValue())
  if maximum==0 then categorySlider:Hide() else categorySlider:Show() end
end

function A:RefreshUI()
  if not self.db then return end
  updateWindowBackdrop()
  backgroundOption:SetChecked(self.db.settings and self.db.settings.solidBackground==true or false)
  local scan=latestScan()
  refreshGuildTitle()
  fitText(subtitle,scan and
    A:Tr('Letzte Inventur: %s   |   %s/%s Fächer'):format(snapshotTime(scan),tostring(scan.scannedTabs or '?'),tostring(scan.totalTabs or '?')) or
    A:Tr('Noch keine lokale Inventur. Zum Scannen bitte die Gildenbank öffnen.'),775,104)
  local cartRows,positions,units=cartInfo()
  fitText(footerCart.caption,A:Tr('Warenkorb: %d Pos. / %d Stk.'):format(positions,units),footerCart:GetWidth()-22,40)
  footerLeft:SetText(A:Tr('LOKALER SNAPSHOT  -  kein Live-Bestand · Übertragung nach WoW-Speicherung  ·  Addon %s'):format(tostring(A.version)))
  for key,panel in pairs(panels) do if key==active then panel:Show()else panel:Hide()end end
  for key,b in pairs(tabs) do
    b.blSelected=key==active
    colorButton(b,b.blSelected)
  end

  if active=='items' then
    catalogItems=buildItemIndex(scan)
    repaintCategories()
    filtered={}
    local q=(search:GetText() or ''):lower()
    for _,item in ipairs(catalogItems) do
      local inClass=activeCategory=='all' or activeCategory==item.class or
        activeCategory==item.class..'\031'..(item.subclass or '')
      if inClass and (q=='' or item.name:lower():find(q,1,true) or
        tostring(item.id):find(q,1,true) or item.class:lower():find(q,1,true)) then
        filtered[#filtered+1]=item
      end
    end
    table.sort(filtered,function(a,b)
      if sortMode=='stock' and a.count~=b.count then return a.count>b.count end
      local an=a.name:lower();local bn=b.name:lower()
      if an==bn then return tostring(a.key)<tostring(b.key)end
      return an<bn
    end)
    itemOffset=math.max(0,math.min(itemOffset,math.max(0,#filtered-ROWS)))
    sortButton.caption:SetText(sortMode=='stock' and A:Tr('Bestand') or A:Tr('Name'))
    pager:SetText(#filtered==0 and A:Tr('Keine Treffer') or
      A:Tr('Treffer %d–%d von %d'):format(itemOffset+1,math.min(#filtered,itemOffset+ROWS),#filtered))
    for i,r in ipairs(results) do
      local item=filtered[itemOffset+i]
      if item then
        r.icon:SetTexture(itemIcon(item.id,item.iconTexture))
        fitText(r.name,item.name,365,65)
        r.name:SetTextColor(itemColor(item.quality))
        r.stock:SetText(A:Tr('%s Stk.'):format(tostring(item.count)))
        r.bg:SetVertexColor(item.key==selectedKey and .30 or (i%2==0 and .092 or .12),
          item.key==selectedKey and .23 or .093,item.key==selectedKey and .11 or .088,.91)
        r:Show()
      else r:Hide()end
    end
    local chosen
    for _,item in ipairs(filtered) do if item.key==selectedKey then chosen=item;break end end
    if not chosen and filtered[1] then selectedKey=filtered[1].key;chosen=filtered[1]end
    if chosen then
      if lastDetailKey~=chosen.key then qty:SetText('1');lastDetailKey=chosen.key end
      detailIcon:SetTexture(itemIcon(chosen.id,chosen.iconTexture))
      detailTooltipButton.currentItem=chosen
      fitText(detailName,chosen.name,nil,31)
      detailName:SetTextColor(itemColor(chosen.quality))
      detailType:SetText(A:Tr('Kategorie: %s'):format(prefixChars(chosen.class,25))..'\n'..prefixChars(chosen.subclass or '',26))
      detailLevel:SetText(A:Tr('Stufe %s  -  Item-ID %s'):format(tostring(chosen.level or '?'),tostring(chosen.id)))
      detailStock:SetText(A:Tr('Inventur: %s Stück'):format(tostring(chosen.count)))
      addButton:Enable()
    else
      lastDetailKey=nil
      detailIcon:SetTexture('Interface\\Icons\\INV_Misc_QuestionMark')
      detailTooltipButton.currentItem=nil
      detailName:SetText(A:Tr('Kein Gegenstand ausgewählt'))
      detailType:SetText('');detailLevel:SetText('');detailStock:SetText('')
      addButton:Disable()
    end
    detailSnapshot:SetText(A:Tr('Snapshot: %s\nMengen können inzwischen abweichen.'):format(snapshotTime(scan)))
  elseif active=='cart' then
    cartOffset=math.max(0,math.min(cartOffset,math.max(0,#cartRows-8)))
    cartSummary:SetText(A:Tr('%d Positionen  ·  %d Stück  ·  lokal'):format(positions,units))
    for i,r in ipairs(cartButtons) do
      local row=cartRows[cartOffset+i]
      if row then
        r.icon:SetTexture(itemIcon(row.info.id))
        fitText(r.name,row.info.name or tostring(row.key),395,65)
        r.count:SetText('× '..tostring(row.info.quantity or 0))
        r:Show()
      else r:Hide()end
    end
    cartPage:SetText(positions==0 and A:Tr('Warenkorb ist leer.') or
      A:Tr('Positionen %d–%d von %d'):format(cartOffset+1,math.min(#cartRows,cartOffset+8),#cartRows))
    local orders,stamp=A:GetOrderOverview()
    for i,r in ipairs(orderRows) do
      local row=orders[i];r.row=row
      if row then r.title:SetText(row.title);r.status:SetText(row.status);r:Show() else r:Hide() end
    end
    if #orders==0 then orderEmpty:Show() else orderEmpty:Hide() end
    orderStamp:SetText(stamp and A:Tr('Serverstand: %s'):format(stamp) or A:Tr('Serverstand: noch keiner (Companion?)'))
    if positions==0 then submitButton:Disable() else submitButton:Enable() end
  elseif active=='bank' then
    local opened=self:IsGuildBankOpen() and A:Tr('geöffnet') or A:Tr('geschlossen')
    local run=self.scanRun
    bankState:SetText(A:Tr('Gildenbank: %s'):format(opened)..'\n\n'..(
      scan and A:Tr('Letzter Scan: %s/%s Fächer · %s belegte Slots · %s'):format(tostring(scan.scannedTabs),
      tostring(scan.totalTabs),tostring(scan.occupiedSlots),tostring(scan.quality)) or A:Tr('Noch keine Inventur vorhanden.')))
    bankProgress:SetText(run and A:Tr('Aktiver Scan: %s/%s Fächer'):format(tostring(run.completed),tostring(#run.queue)) or
      A:Tr('Kein aktiver Scan · SavedVariables werden beim /reload oder Ausloggen gespeichert.'))
    local orders=self:IsBankManager() and self:OpenManageOrders() or {}
    for i,row in ipairs(bankOrderRows) do
      local o=orders[i]
      if o then
        local who=(o.recipient~='' and o.recipient) or o.requester or '?'
        local why=self:OrderBlocked(o.id)
        row:SetText(('#%d  ·  %s  ·  %s%s'):format(o.id,who,self:OrderSummary(o),why and '  ·  '..why or ''))
      elseif i==1 then
        row:SetText(self:IsBankManager() and A:Tr('Keine offenen Bestellungen.') or A:Tr('Nur für Bankverwalter, Offiziere und Admins.'))
      else row:SetText('') end
    end
    bankOrdersMore:SetText(#orders>#bankOrderRows and A:Tr('… und %d weitere'):format(#orders-#bankOrderRows) or '')
  elseif active=='feedback' then
    refreshFeedback()
  elseif active=='help' then
    peerStatus:SetText(A:Tr('Ingame-Eingänge: %d   |   Ingame-Ausgänge: %d   |   Keine Serverbestätigung'):format(
      #(self.db.peerInbox or {}),#(self.db.peerOutbox or {})))
  end
end
-- Re-applies every static label in the active language (see A:Tr).
function A:ApplyUILocale()
  for _,entry in ipairs(localized) do
    if entry.fit then fitText(entry.font,A:Tr(entry.source),entry.fit)
    else entry.font:SetText(A:Tr(entry.source)) end
  end
  refreshGuildTitle()
  if window:IsShown() then self:RefreshUI() end
end
function A:ToggleUI()
  if window:IsShown() then window:Hide()
  else self:RefreshUI();window:Show();if self.UpdateMinimapBadge then self:UpdateMinimapBadge(false) end end
end
function A:ShowTab(key) active=key;self:RefreshUI();window:Show() end
function A:OpenCartUI()
  active='cart';self:RefreshUI();window:Show()
end
function A:OpenFeedbackUI()
  active='feedback';self:RefreshUI();window:Show()
end
