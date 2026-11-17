-- Local catalog and cart draft: entirely independent from Baganator.
-- NO server submission is implied; an in-game order is NOT confirmed until the server ACKs it.
local A=Banklord

local function latest(self)
  local list=self.db and self.db.fullScans or {}
  return list[#list]
end
local function cleanName(link)
  return (type(link)=='string' and link:match('|h%[([^%]]+)%]|h')) or 'Unbekannter Gegenstand'
end
function A:SearchLocalItems(query)
  local scan=latest(self)
  if not scan then self:Message('Noch keine Inventur. /banklord scan bei der Gildenbank ausführen.');return {} end
  local found={};query=tostring(query or ''):lower()
  for _,item in ipairs(scan.items or {}) do
    local name=cleanName(item.itemLink)
    if name:lower():find(query,1,true) or tostring(item.itemID)==query or query=='' then
      found[#found+1]={id=item.itemID,name=name,count=item.count,key=item.itemKey,itemLink=item.itemLink}
    end
  end
  table.sort(found,function(a,b) return a.name < b.name end)
  self:Message('Lokaler Bestand: '..#found..' Treffer, Snapshot vom '..(date and date('%d.%m.%Y %H:%M',scan.capturedAt) or tostring(scan.capturedAt))..' (NICHT live/serverbestätigt).')
  for n=1,math.min(15,#found) do
    local item=found[n];self:Message(tostring(item.id)..' · '..item.name..' · zuletzt '..item.count..' Stück')
  end
  if #found>15 then self:Message('Nur die ersten 15 Treffer. Suche bitte eingrenzen.') end
  return found
end
function A:AddCartDraftByKey(key,quantity)
  if type(key)~='string' or not key:match('^item:[%d:%-]+$') or
     type(quantity)~='number' or quantity<1 or quantity>1000000 or quantity%1~=0 then
    return self:Message('Ungültiger Gegenstand oder Menge.')
  end
  local scan=latest(self)
  if not scan then return self:Message('Zuerst einen gültigen Scan laden.') end
  local match
  for _,entry in ipairs(scan.items or {}) do if entry.itemKey==key then match=entry;break end end
  if not match then return self:Message('Gegenstand nicht im lokalen Snapshot.') end
  self.db.localCartDraft=self.db.localCartDraft or {}
  local current=self.db.localCartDraft[key] or {quantity=0}
  if current.quantity+quantity>1000000 then return self:Message('Entwurfsmenge zu hoch.') end
  self.db.localCartDraft[key]={quantity=current.quantity+quantity,id=match.itemID,key=key,name=cleanName(match.itemLink)}
  self:Message(quantity..' x '..cleanName(match.itemLink)..' lokal vorgemerkt. NICHT bestellt!')
end
function A:AddCartDraft(id,quantity)
  if type(id)~='number' or id<1 or type(quantity)~='number' or quantity<1 or quantity>1000000 or quantity%1~=0 then
    return self:Message('Syntax: /banklord merken <Item-ID> <Menge>')
  end
  local scan=latest(self)
  if not scan then return self:Message('Zuerst einen gültigen Scan laden.') end
  local match
  for _,entry in ipairs(scan.items or {}) do
    if entry.itemID==id then
      if match and match.itemKey~=entry.itemKey then
        return self:Message('Mehrere Itemvarianten vorhanden. Bitte über die Katalog-Oberfläche auswählen.')
      end
      match=entry
    end
  end
  if not match then return self:Message('Item-ID im lokalen Snapshot nicht gefunden.') end
  return self:AddCartDraftByKey(match.itemKey,quantity)
end
function A:CartDraftStatus()
  local cart=self.db and self.db.localCartDraft or {};local count=0
  for _,row in pairs(cart) do count=count+1;self:Message(row.name..' · '..row.quantity..' Stück (lokaler Entwurf)') end
  if count==0 then self:Message('Lokaler Warenkorb ist leer.') end
  self:Message('ACHTUNG: Kein Serverauftrag! Verbindliche Bestellung über die Website.')
end
function A:ClearCartDraft()
  if not self.db then return end
  self.db.localCartDraft={};self:Message('Lokaler Warenkorb geleert (keine Serverbestellung betroffen).')
end

-- Compact, textual transfer for the web cart. Never contains credentials or server claims.
function A:BuildCartTransferCode()
  local parts={}
  for key,row in pairs(self.db and self.db.localCartDraft or {}) do
    if type(key)~='string' or not key:match('^item:[%d:%-]+$') then return nil,'Item-Variante nicht übertragbar.' end
    if type(row)~='table' or type(row.id)~='number' or type(row.quantity)~='number' or
       row.quantity<1 or row.quantity>1000000 or row.quantity%1~=0 or row.id<1 or row.id%1~=0 or
       not key:match('^item:'..tostring(row.id)..':') then return nil,'Ungültiger Warenkorbeintrag.' end
    parts[#parts+1]=tostring(row.id)..','..tostring(row.quantity)..','..key
  end
  if #parts==0 then return nil,'Der Warenkorb ist leer.' end
  if #parts>50 then return nil,'Maximal 50 Positionen übertragbar.' end
  table.sort(parts)
  local transfer='WGB1|'..table.concat(parts,'|')
  if #transfer>4000 then return nil,'Übertragungscode zu lang.' end
  return transfer
end
