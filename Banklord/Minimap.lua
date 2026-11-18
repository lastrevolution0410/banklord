-- Optional, standalone minimap launcher. Pure Blizzard UI, independent of LibDBIcon.
-- Never accesses Windows, network or protected game actions.
local A=Banklord
local button=CreateFrame('Button','BanklordMinimapButton',Minimap)
button:SetSize(31,31)
button:SetFrameStrata('MEDIUM')
button:SetFrameLevel((Minimap:GetFrameLevel() or 0)+8)
button:RegisterForClicks('LeftButtonUp','RightButtonUp')
button:RegisterForDrag('LeftButton')
local icon=button:CreateTexture(nil,'BACKGROUND')
icon:SetTexture('Interface\\AddOns\\Banklord\\GoldBars')
icon:SetTexCoord(.08,.92,.08,.92)
icon:SetSize(20,20)
icon:SetPoint('CENTER',button,'CENTER',0,0)
local border=button:CreateTexture(nil,'OVERLAY')
border:SetTexture('Interface\\Minimap\\MiniMap-TrackingBorder')
border:SetSize(53,53)
border:SetPoint('TOPLEFT',button,'TOPLEFT',0,0)
local highlight=button:CreateTexture(nil,'HIGHLIGHT')
highlight:SetTexture('Interface\\Minimap\\UI-Minimap-ZoomButton-Highlight')
highlight:SetBlendMode('ADD')
highlight:SetAllPoints(button)
-- Order badge (bank managers): a small count on the OUTER side of the button,
-- so it never covers the minimap, and a pulsing glow while new orders are unseen.
local glow=button:CreateTexture(nil,'OVERLAY')
glow:SetTexture('Interface\\Buttons\\UI-ActionButton-Border')
glow:SetBlendMode('ADD')
glow:SetVertexColor(1,.82,.2)
glow:SetSize(58,58)
glow:SetPoint('CENTER',button,'CENTER',0,0)
glow:Hide()
local badge=CreateFrame('Frame',nil,button)
badge:SetSize(16,16)
badge:SetFrameLevel(button:GetFrameLevel()+2)
local badgeBg=badge:CreateTexture(nil,'ARTWORK')
badgeBg:SetTexture('Interface\\Buttons\\WHITE8X8')
badgeBg:SetVertexColor(.78,.08,.06,.95)
badgeBg:SetAllPoints(badge)
local badgeText=badge:CreateFontString(nil,'OVERLAY','GameFontHighlightSmall')
badgeText:SetPoint('CENTER',badge,'CENTER',0,0)
badge:Hide()
local pulse=CreateFrame('Frame')
local elapsed=0
local function pulseStep(_,dt)
  elapsed=elapsed+(dt or 0)
  glow:SetAlpha(.25+.75*math.abs(math.sin(elapsed*3)))
end
local function placeBadge(angle)
  local rad=math.rad(angle or 225)
  badge:ClearAllPoints()
  -- 15px further out along the same direction as the button sits.
  badge:SetPoint('CENTER',button,'CENTER',math.cos(rad)*15,math.sin(rad)*15)
end
local function settings()
  if not A.db then return nil end
  A.db.settings=A.db.settings or {}
  return A.db.settings
end
local function place(angle)
  angle=tonumber(angle) or 225
  angle=angle%360
  button:ClearAllPoints()
  local rad=math.rad(angle)
  button:SetPoint('CENTER',Minimap,'CENTER',math.cos(rad)*80,math.sin(rad)*80)
  placeBadge(angle)
end

-- Count of open orders for bank managers (plus live orders announced since the
-- last /reload). flash=true starts the glow, false stops it, nil keeps it.
function A:UpdateMinimapBadge(flash)
  local count=0
  if self.IsBankManager and self:IsBankManager() and self.OpenManageOrders then
    count=#self:OpenManageOrders()+(self.liveNewOrders or 0)
  end
  if count>0 then
    badgeText:SetText(count>99 and '99+' or tostring(count))
    badge:SetWidth(count>9 and 22 or 16)
    badge:Show()
  else badge:Hide() end
  if flash~=nil then self.minimapFlash=flash and count>0 end
  if count==0 then self.minimapFlash=false end
  if self.minimapFlash then
    glow:Show();pulse:SetScript('OnUpdate',pulseStep)
  else
    glow:Hide();pulse:SetScript('OnUpdate',nil);elapsed=0
  end
  return count
end
function A:InitMinimapButton()
  local cfg=settings()
  if not cfg then return end
  place(cfg.minimapAngle)
  if cfg.minimapHidden then button:Hide() else button:Show() end
  A:UpdateMinimapBadge()
end
function A:ToggleMinimapButton()
  local cfg=settings()
  if not cfg then return end
  cfg.minimapHidden=not cfg.minimapHidden
  if cfg.minimapHidden then
    button:Hide()
    A:Message(A:Tr('Minimap-Symbol ausgeblendet. Mit /banklord minimap wieder anzeigen.'))
  else
    button:Show()
    place(cfg.minimapAngle)
    A:Message(A:Tr('Minimap-Symbol eingeblendet.'))
  end
end
button:SetScript('OnClick',function(_,which)
  A:UpdateMinimapBadge(false)
  if which=='RightButton' and A.OpenCartUI then A:OpenCartUI()
  elseif A.ToggleUI then A:ToggleUI()
  else A:Message(A:Tr('Addon-Fenster nicht geladen. /reload und Lua-Fehler mit /console scriptErrors 1 pruefen.')) end
end)
button:SetScript('OnEnter',function(self)
  if not GameTooltip then return end
  GameTooltip:SetOwner(self,'ANCHOR_LEFT')
  GameTooltip:AddLine('Banklord',1,.82,.36)
  GameTooltip:AddLine(A:Tr('Gildenbank und Bestellungen'),1,1,1)
  local open=A:UpdateMinimapBadge()
  if open>0 then GameTooltip:AddLine(A:Tr('Offene Bestellungen: %d'):format(open),1,.3,.25) end
  GameTooltip:AddLine(A:Tr('Linksklick: Gildenbank öffnen'),.88,.82,.66)
  GameTooltip:AddLine(A:Tr('Rechtsklick: Warenkorb öffnen'),.88,.82,.66)
  GameTooltip:AddLine(A:Tr('Ziehen: Position ändern'),.65,.65,.65)
  GameTooltip:AddLine(A:Tr('Lokale Daten sind kein Live-Bestand.'),1,.67,.26)
  GameTooltip:Show()
end)
button:SetScript('OnLeave',function() if GameTooltip then GameTooltip:Hide() end end)
local dragging=false
local function moveToCursor()
  local x,y=GetCursorPosition()
  local cx,cy=Minimap:GetCenter()
  local scale=Minimap:GetEffectiveScale()
  if not (x and y and cx and cy and scale and scale>0) then return end
  x=x/scale-cx;y=y/scale-cy
  local angle
  if math.atan2 then angle=math.atan2(y,x)
  elseif x==0 then angle=y>=0 and math.pi/2 or -math.pi/2
  else angle=math.atan(y/x);if x<0 then angle=angle+math.pi end end
  local deg=(math.deg(angle)+360)%360
  place(deg)
  local cfg=settings()
  if cfg then cfg.minimapAngle=deg end
end
button:SetScript('OnDragStart',function() dragging=true;button:SetScript('OnUpdate',moveToCursor) end)
button:SetScript('OnDragStop',function() dragging=false;button:SetScript('OnUpdate',nil);moveToCursor() end)
button:Hide() -- Shown on ADDON_LOADED, after SavedVariables initialization.
