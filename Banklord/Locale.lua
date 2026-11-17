-- Localization with stable keys: client locale is the source of truth.
local A=Banklord
local EN={
  ['Gegenstände']='Items',
  ['Warenkorb']='Cart',
  ['Bankverwaltung']='Bank management',
  ['Synchronisierung']='Sync',
  ['Lokaler Bestand']='Local inventory',
  ['Gegenstand']='Item',
  ['Gegenstand auswählen']='Select an item',
  ['Alle Gegenstände']='All items',
  ['Suche:']='Search:',
  ['Bestand']='Stock',
  ['Zurück']='Back',
  ['Zum Warenkorb']='Add to cart',
  ['Mein Warenkorb']='My cart',
  ['Zurück zum Katalog']='Back to catalog',
  ['Warenkorb leeren']='Empty cart',
  ['Warenkorb öffnen']='Open cart',
  ['Alle Fächer scannen']='Scan all tabs',
  ['Synchronisierung & Übertragung']='Sync & transfer',
  ['Gildenbank und Bestellungen']='Guild bank & orders',
  ['Linksklick: Gildenbank öffnen']='Left click: Open guild bank',
  ['Rechtsklick: Warenkorb öffnen']='Right click: Open cart',
  ['Ziehen: Position ändern']='Drag: Move icon',
  ['Lokale Daten sind kein Live-Bestand.']='Local data is not live inventory.',
  ['Gildenbank']='Guild bank',
  ['Gilde']='Guild',
}
function A:Tr(text)
  local locale=type(GetLocale)=='function' and GetLocale() or 'deDE'
  if locale=='deDE' then return text end
  return EN[text] or text -- Explicit fallback for not-yet-translated keys.
end
