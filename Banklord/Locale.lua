-- Localization. German is the source language; EN maps every German display
-- text (or format string, same placeholders in the same order) to English.
-- Language: BanklordDB.settings.locale ('deDE' | 'enUS') if set, otherwise the
-- game client: deDE -> German, every other locale -> English.
local A=Banklord
local EN={
  -- Tabs, headings, buttons
  ['Gegenstände']='Items',
  ['Warenkorb']='Cart',
  ['Bankverwaltung']='Bank management',
  ['Synchronisierung']='Sync',
  ['Erweitert']='Advanced',
  ['Lokaler Bestand']='Local stock',
  ['Gegenstand']='Item',
  ['Gegenstand auswählen']='Select an item',
  ['Alle Gegenstände']='All items',
  ['Weitere Gegenstände']='Other items',
  ['Kategorien']='Categories',
  ['Gildenbank durchsuchen']='Search guild bank',
  ['Suche:']='Search:',
  ['Bestand']='Stock',
  ['Name']='Name',
  ['Menge']='Quantity',
  ['Status']='Status',
  ['Zurück']='Back',
  ['Weiter']='Next',
  ['Menge vormerken']='Quantity to add',
  ['Zum Warenkorb']='Add to cart',
  ['Mein Warenkorb']='My cart',
  ['Zurück zum Katalog']='Back to catalog',
  ['Warenkorb leeren']='Empty cart',
  ['Warenkorb öffnen']='Open cart',
  ['Entfernen']='Remove',
  ['Meine Bestellungen']='My orders',
  ['Bestellung absenden']='Submit order',
  ['Code erzeugen']='Create code',
  ['An Spieler']='To player',
  ['Alle Fächer scannen']='Scan all tabs',
  ['Aktuelles Fach scannen']='Scan current tab',
  ['Scan abbrechen']='Cancel scan',
  ['Inventur & Bankscanner']='Scan & bank scanner',
  ['Synchronisierung & Übertragung']='Sync & transfer',
  ['Eingänge anzeigen']='Show inbox',
  ['Ausgänge anzeigen']='Show outbox',
  ['Zustellung erneut versuchen']='Retry delivery',
  ['Erweitert – Darstellung']='Advanced – Appearance',
  ['Ausgefüllter, blickdichter Hintergrund']='Solid, opaque background',
  ['Gildenbank und Bestellungen']='Guild bank & orders',
  ['Linksklick: Gildenbank öffnen']='Left click: Open guild bank',
  ['Rechtsklick: Warenkorb öffnen']='Right click: Open cart',
  ['Ziehen: Position ändern']='Drag: Move icon',
  ['Lokale Daten sind kein Live-Bestand.']='Local data is not live stock.',
  ['Gildenbank']='Guild bank',
  ['Gilde']='Guild',
  ['Gildenbank - %s - Banklord']='Guild bank - %s - Banklord',

  -- UI static texts
  ['Inventur wird geladen ...']='Loading scan ...',
  ['Lokale Daten • nicht live • Bestellungen werden erst durch den Server verbindlich.']=
    'Local data • not live • Orders only become binding once the server confirms them.',
  ['Im Reiter Warenkorb mit „Bestellung absenden“\nverbindlich beim Server anfragen.']=
    'Use “Submit order” on the Cart tab\nto send a binding request to the server.',
  ['Noch keine Bestellungen.\n\nWarenkorb füllen und unten auf „Bestellung absenden“ klicken. Der Companion überträgt sie nach /reload oder Logout.']=
    'No orders yet.\n\nFill the cart and click “Submit order” below. The Companion uploads it after /reload or logout.',
  ['Wähle, wie der Hintergrund des Addon-Fensters dargestellt werden soll.']=
    'Choose how the background of the addon window is displayed.',
  ['Deaktiviert: transparente Ansicht mit sichtbarer Spielwelt im Hintergrund.\n'..
   'Aktiviert: vollständig dunkle Fensterfläche, besser lesbar in hellen Spielbereichen.\n'..
   'Die Einstellung wird pro WoW-Konto gespeichert. Beim nächsten Spielstart bleibt sie erhalten.']=
    'Off: transparent view with the game world visible in the background.\n'..
    'On: fully dark window, easier to read in bright areas.\n'..
    'The setting is saved per WoW account and kept for the next session.',
  ['Reguläre und mobile Gildenbank werden ausschließlich über Blizzard-APIs gelesen.\n'..
   'Nur vollständig gelesene Fächer zählen als erfasst. Andere Addons werden nicht benötigt.\n'..
   'Inventuren sind zunächst lokal und müssen anschließend im Webportal überprüft werden.']=
    'Regular and mobile guild banks are read exclusively through Blizzard APIs.\n'..
    'Only completely read tabs count as captured. No other addons are required.\n'..
    'Scans are local at first and must then be reviewed in the web portal.',
  ['Dein Addon speichert Scans und Entwürfe lokal. Erst nach Prüfung im Web gelten sie als bestätigt.']=
    'Your addon stores scans and drafts locally. They only count as confirmed after review on the web.',
  ['1. Gildenbank öffnen und Inventur starten.\n\n'..
   '2. /reload oder ausloggen, damit WoW die SavedVariables auf die Festplatte schreibt.\n\n'..
   '3. Der optionale Windows-Companion überträgt gespeicherte Daten per HTTPS.\n\n'..
   '4. Verwalter bestätigen importierte Inventuren und Bestellungen über die Website.\n\n'..
   'Offline-Ingame-Entwürfe können an ONLINE-Spieler weitergegeben werden, sind aber keine Webaufträge.\n'..
   'Direkte Live-Kommunikation des laufenden Addons mit dem Webserver ist derzeit nicht verfügbar.']=
    '1. Open the guild bank and start a scan.\n\n'..
    '2. /reload or log out so WoW writes the SavedVariables to disk.\n\n'..
    '3. The optional Windows Companion uploads the saved data via HTTPS.\n\n'..
    '4. Managers confirm imported scans and orders on the website.\n\n'..
    'Offline in-game drafts can be passed to ONLINE players, but they are not web orders.\n'..
    'Direct live communication between the running addon and the web server is currently not available.',

  -- UI dynamic texts
  ['Gegenstand #%s']='Item #%s',
  ['Keine Inventur']='No scan',
  ['%d.%m.%Y %H:%M']='%Y-%m-%d %H:%M',
  ['%d.%m. %H:%M']='%m-%d %H:%M',
  ['Zeitpunkt unbekannt']='Time unknown',
  ['Letzte Inventur: %s   |   %s/%s Fächer']='Last scan: %s   |   %s/%s tabs',
  ['Noch keine lokale Inventur. Zum Scannen bitte die Gildenbank öffnen.']='No local scan yet. Open the guild bank to scan.',
  ['Warenkorb: %d Pos. / %d Stk.']='Cart: %d lines / %d pcs.',
  ['LOKALER SNAPSHOT  -  kein Live-Bestand · Übertragung nach WoW-Speicherung  ·  Addon %s']=
    'LOCAL SNAPSHOT  -  not live stock · upload after WoW saves  ·  Addon %s',
  ['Keine Treffer']='No results',
  ['Treffer %d–%d von %d']='Results %d–%d of %d',
  ['%s Stk.']='%s pcs.',
  ['Kategorie: %s']='Category: %s',
  ['Stufe %s  -  Item-ID %s']='Level %s  -  Item ID %s',
  ['Inventur: %s Stück']='Scanned: %s pcs.',
  ['Kein Gegenstand ausgewählt']='No item selected',
  ['Snapshot: %s\nMengen können inzwischen abweichen.']='Snapshot: %s\nQuantities may have changed since.',
  ['%d Positionen  ·  %d Stück  ·  lokal']='%d lines  ·  %d pcs.  ·  local',
  ['Warenkorb ist leer.']='Cart is empty.',
  ['Positionen %d–%d von %d']='Lines %d–%d of %d',
  ['Serverstand: %s']='Server state: %s',
  ['Serverstand: noch keiner (Companion?)']='Server state: none yet (Companion?)',
  ['geöffnet']='open',
  ['geschlossen']='closed',
  ['Gildenbank: %s']='Guild bank: %s',
  ['Letzter Scan: %s/%s Fächer · %s belegte Slots · %s']='Last scan: %s/%s tabs · %s occupied slots · %s',
  ['Noch keine Inventur vorhanden.']='No scan available yet.',
  ['Aktiver Scan: %s/%s Fächer']='Active scan: %s/%s tabs',
  ['Kein aktiver Scan · SavedVariables werden beim /reload oder Ausloggen gespeichert.']=
    'No active scan · SavedVariables are saved on /reload or logout.',
  ['Ingame-Eingänge: %d   |   Ingame-Ausgänge: %d   |   Keine Serverbestätigung']=
    'In-game inbox: %d   |   In-game outbox: %d   |   No server confirmation',
  ['Zuerst einen Gegenstand auswählen.']='Select an item first.',
  ['Bitte eine gültige Menge zwischen 1 und 1000000 angeben.']='Please enter a valid quantity between 1 and 1000000.',
  ['Übertragung nicht möglich.']='Transfer not possible.',
  ['Übertragungscode markiert: mit Strg+C kopieren. Keine Serverbestellung!']=
    'Transfer code selected: copy it with Ctrl+C. Not a server order!',

  -- Core / slash commands
  ['v%s geladen. /banklord zeigt die Befehle.']='v%s loaded. /banklord lists the commands.',
  ['Kein Scan aktiv.']='No scan running.',
  ['Befehle: /banklord open, scan, scanfach, liste, suche <Name>, merken <Item-ID> <Menge>, korb, code, leeren, senden <Spieler>, eingang, ausgang, erneut, status, debug, minimap, lang de|en|auto']=
    'Commands: /banklord open, scan, scanfach (current tab), liste (list), suche <name> (search), merken <item ID> <qty> (add to cart), korb (cart), code, leeren (empty cart), senden <player> (send), eingang (inbox), ausgang (outbox), erneut (retry), status, debug, minimap, lang de|en|auto (language)',
  ['nicht verfügbar']='not available',
  ['API-Fehler']='API error',
  ['unbekannt']='unknown',
  ['noch keines']='none yet',
  ['%s/Leserecht=%s']='%s/viewable=%s',
  ['DEBUG v%s: Bank=%s, API=%s, Fach=%s']='DEBUG v%s: bank=%s, API=%s, tab=%s',
  ['DEBUG Quelle=%s, UI sichtbar=%s, letztes Bankereignis=%s']='DEBUG source=%s, UI visible=%s, last bank event=%s',
  ['DEBUG Fachinfo=%s, Scan ausstehend=%s']='DEBUG tab info=%s, scan pending=%s',
  ['DEBUG Gesamtscan: %d/%d, Fach=%s, Versuch=%s']='DEBUG full scan: %d/%d, tab=%s, attempt=%s',
  ['Deutsch']='German',
  ['Englisch']='English',
  ['automatisch – %s (Client: %s)']='automatic – %s (client: %s)',
  ['Sprache: %s. /reload wendet sie überall an.']='Language: %s. /reload applies it everywhere.',
  ['Sprache: %s. Ändern mit /banklord lang de | en | auto']='Language: %s. Change it with /banklord lang de | en | auto',

  -- Scanner
  ['Gesamtscan abgebrochen: %s. Keine neuen Daten als Gesamtbestand gespeichert.']=
    'Full scan cancelled: %s. No new data saved as full stock.',
  ['Scan beendet: %d/%d lesbare Fächer, %d belegte Slots, %d unterschiedliche Item-Varianten. Status=%s.']=
    'Scan finished: %d/%d readable tabs, %d occupied slots, %d distinct item variants. Status=%s.',
  ['Lokal erfasst. Companion überträgt automatisch nach /reload oder Logout.']=
    'Captured locally. The Companion uploads automatically after /reload or logout.',
  ['Scan fehlgeschlagen: keine vollständigen Fach-Daten empfangen. Alter Snapshot bleibt bestehen.']=
    'Scan failed: no complete tab data received. The previous snapshot is kept.',
  ['Fach %s konnte nicht verlässlich erfasst werden (%s).']='Tab %s could not be captured reliably (%s).',
  ['Lese Fach %d/%d: %s (#%d; Versuch %d).']='Reading tab %d/%d: %s (#%d; attempt %d).',
  ['Fach %s: keine eindeutige Datenaktualisierung; erneuter Versuch.']='Tab %s: no clear data update; retrying.',
  ['Fach %s: Daten noch in Änderung, warte weiter.']='Tab %s: data still changing, waiting.',
  ['Fach %s: %d belegte Slots (%s).']='Tab %s: %d occupied slots (%s).',
  ['Ein Scan läuft bereits. /banklord stop zum Abbrechen.']='A scan is already running. /banklord stop to cancel.',
  ['Datenbank noch nicht bereit.']='Database not ready yet.',
  ['Bitte zuerst die Gildenbank öffnen. /banklord debug']='Please open the guild bank first. /banklord debug',
  ['Gilden- oder Realmzuordnung fehlt; Scan nicht gestartet.']='Guild or realm unknown; scan not started.',
  ['Anzahl der Bankfächer ist ungültig/nicht verfügbar: %s']='Number of bank tabs is invalid/unavailable: %s',
  ['Bitte ein gültiges Bankfach auswählen.']='Please select a valid bank tab.',
  ['Fach %s: Informationen fehlen (%s).']='Tab %s: information missing (%s).',
  ['Keine lesbaren Bankfächer gefunden.']='No readable bank tabs found.',
  ['Dieser Client bietet keinen geeigneten Timer für sichere Scans.']='This client has no suitable timer for safe scans.',
  ['Starte Einzelfachscan: %d lesbare Fächer, %d übersprungen.']='Starting single-tab scan: %d readable tabs, %d skipped.',
  ['Starte Gesamtscan: %d lesbare Fächer, %d übersprungen.']='Starting full scan: %d readable tabs, %d skipped.',
  ['Nicht geladen.']='Not loaded.',
  ['Gesamtscan läuft: %d/%d fertig; aktuelles Fach %s.']='Full scan running: %d/%d done; current tab %s.',
  ['Gesamtscan-Snapshots lokal: %d (max. %d)']='Local full-scan snapshots: %d (max. %d)',
  ['Letzter Snapshot: %s / %s/%s Fächer / %s Slots / %d Varianten / %s.']='Last snapshot: %s / %s/%s tabs / %s slots / %d variants / %s.',
  ['Vorherige Einzeltest-Scans: %d']='Previous single test scans: %d',
  ['Alle Snapshots sind lokal und NICHT serverbestätigt.']='All snapshots are local and NOT confirmed by the server.',
  -- Scan abort/failure reasons (stored German in SavedVariables, translated for display)
  ['Bankinteraktion beendet']='bank interaction ended',
  ['Bankfenster geschlossen']='bank window closed',
  ['Manuell abgebrochen']='cancelled manually',
  ['Gildenbank geschlossen']='guild bank closed',
  ['C_Timer.After nicht verfügbar']='C_Timer.After not available',
  ['Leserecht inzwischen entfallen']='view permission no longer granted',
  ['Blizzard-Bank-API fehlt']='Blizzard bank API missing',
  ['Fachauswahl-API fehlt']='tab selection API missing',
  ['Fachauswahl fehlgeschlagen']='tab selection failed',
  ['Fachauswahl weicht ab (möglicher UI-/Addon-Konflikt)']='selected tab differs (possible UI/addon conflict)',
  ['Bankabfrage fehlgeschlagen']='bank query failed',
  ['Timeout ohne bestätigtes Bankereignis']='timeout without confirmed bank event',
  ['Bankfach während des Scans gewechselt; Zuordnung unsicher']='bank tab changed during the scan; assignment uncertain',
  ['Bankfach während des Scans gewechselt']='bank tab changed during the scan',

  -- Catalog / cart
  ['Unbekannter Gegenstand']='Unknown item',
  ['Noch keine Inventur. /banklord scan bei der Gildenbank ausführen.']='No scan yet. Run /banklord scan at the guild bank.',
  ['Lokaler Bestand: %d Treffer, Snapshot vom %s (NICHT live/serverbestätigt).']=
    'Local stock: %d results, snapshot from %s (NOT live/server-confirmed).',
  ['%s · %s · zuletzt %s Stück']='%s · %s · last seen %s pcs.',
  ['Nur die ersten 15 Treffer. Suche bitte eingrenzen.']='Only the first 15 results. Please narrow your search.',
  ['Ungültiger Gegenstand oder Menge.']='Invalid item or quantity.',
  ['Zuerst einen gültigen Scan laden.']='Load a valid scan first.',
  ['Gegenstand nicht im lokalen Snapshot.']='Item not in the local snapshot.',
  ['Entwurfsmenge zu hoch.']='Draft quantity too high.',
  ['%d x %s lokal vorgemerkt. NICHT bestellt!']='%d x %s added to the local cart. NOT ordered!',
  ['Syntax: /banklord merken <Item-ID> <Menge>']='Syntax: /banklord merken <item ID> <quantity>',
  ['Mehrere Itemvarianten vorhanden. Bitte über die Katalog-Oberfläche auswählen.']=
    'Several item variants exist. Please choose one in the catalog window.',
  ['Item-ID im lokalen Snapshot nicht gefunden.']='Item ID not found in the local snapshot.',
  ['%s · %s Stück (lokaler Entwurf)']='%s · %s pcs. (local draft)',
  ['Lokaler Warenkorb ist leer.']='Local cart is empty.',
  ['ACHTUNG: Kein Serverauftrag! Verbindliche Bestellung über die Website.']=
    'NOTE: Not a server order! Place binding orders via the website.',
  ['Lokaler Warenkorb geleert (keine Serverbestellung betroffen).']='Local cart emptied (no server order affected).',
  ['Item-Variante nicht übertragbar.']='Item variant cannot be transferred.',
  ['Ungültiger Warenkorbeintrag.']='Invalid cart entry.',
  ['Der Warenkorb ist leer.']='The cart is empty.',
  ['Maximal 50 Positionen übertragbar.']='At most 50 lines can be transferred.',
  ['Übertragungscode zu lang.']='Transfer code too long.',

  -- Orders
  ['Eingereicht – wartet auf Prüfung']='Submitted – awaiting review',
  ['Teilweise genehmigt']='Partially approved',
  ['Genehmigt – liegt bereit']='Approved – ready for pickup',
  ['Abgelehnt']='Rejected',
  ['Teilweise ausgegeben']='Partially handed out',
  ['Ausgegeben']='Handed out',
  ['Storniert']='Cancelled',
  ['Gegenstand auf dem Server unbekannt – Inventur abwarten']='Item unknown to the server – wait for a scan',
  ['Keine Berechtigung in dieser Gilde']='No permission in this guild',
  ['Ungültige Positionen']='Invalid lines',
  ['Ungültige Menge']='Invalid quantity',
  ['Kennung bereits verwendet']='ID already used',
  ['Bestellung nicht möglich.']='Order not possible.',
  ['Zu viele Bestellungen warten auf Übertragung. Bitte /reload ausführen und den Companion laufen lassen.']=
    'Too many orders are waiting for upload. Please /reload and keep the Companion running.',
  ['Bestellung vorgemerkt. Der Companion überträgt sie nach /reload oder Logout; der Status erscheint danach hier.']=
    'Order queued. The Companion uploads it after /reload or logout; its status will appear here afterwards.',
  ['Neu · %s']='New · %s',
  ['Wartet auf Übertragung (/reload)']='Waiting for upload (/reload)',
  ['Nicht angenommen']='Not accepted',
  ['Fehler']='Error',
  ['%d bestellt']='%d ordered',
  [' · %d genehmigt']=' · %d approved',
  [' · %d ausgegeben']=' · %d handed out',
  ['Item %s']='Item %s',
  ['%s Stück']='%s pcs.',
  ['Rechtsklick in der Liste entfernt die Meldungen.']='Right-click in the list to dismiss these messages.',

  -- Minimap
  ['Minimap-Symbol ausgeblendet. Mit /banklord minimap wieder anzeigen.']='Minimap icon hidden. Show it again with /banklord minimap.',
  ['Minimap-Symbol eingeblendet.']='Minimap icon shown.',
  ['Addon-Fenster nicht geladen. /reload und Lua-Fehler mit /console scriptErrors 1 pruefen.']=
    'Addon window not loaded. /reload and check Lua errors with /console scriptErrors 1.',

  -- Item metadata
  ['Item-Daten erfasst: %d/%d Varianten. /reload oder Ausloggen speichert die Daten für den Companion.']=
    'Item data captured: %d/%d variants. /reload or logging out saves the data for the Companion.',

  -- Peer (in-game drafts)
  ['Entwurf an %s zurzeit nicht zustellbar. Bleibt lokal vorgemerkt.']='Draft to %s cannot be delivered right now. It stays queued locally.',
  ['Ingame-Kommunikation nicht verfügbar; Entwürfe bleiben lokal.']='In-game communication not available; drafts stay local.',
  ['Empfänger ungültig. Syntax: /banklord senden Charakter-Realm']='Invalid recipient. Syntax: /banklord senden Character-Realm',
  ['Warenkorb leer.']='Cart empty.',
  ['Warenkorbcode ungültig.']='Cart code invalid.',
  ['Lokaler Speicher noch nicht geladen.']='Local storage not loaded yet.',
  ['Ausgang voll. Bitte offene Entwürfe prüfen.']='Outbox full. Please check pending drafts.',
  ['Entwurf %s lokal vorgemerkt. KEINE Serverbestellung. Automatische Ingame-Zustellversuche laufen.']=
    'Draft %s queued locally. NOT a server order. Automatic in-game delivery attempts are running.',
  ['Ingame-Empfang durch %s bestätigt. Noch NICHT auf der Website eingegangen.']=
    'In-game receipt confirmed by %s. NOT yet received on the website.',
  ['Ingame-Eingang voll; neuer Entwurf NICHT angenommen. Bitte zuerst Eingänge im Web prüfen.']=
    'In-game inbox full; new draft NOT accepted. Please review the inbox on the web first.',
  ['Ungeprüfter Entwurf von %s gespeichert. Noch keine Webbestellung!']='Unreviewed draft from %s saved. Not a web order yet!',
  ['Ingame-Eingang: %d von %d Entwürfen; Webannahme UNBESTÄTIGT.']='In-game inbox: %d of %d drafts; web acceptance UNCONFIRMED.',
  ['%d · %s · %s · %d Zeichen']='%d · %s · %s · %d characters',
  ['%s · %s · %s · Versuche %s']='%s · %s · %s · attempts %s',
  ['Keine gespeicherten Ingame-Sendeaufträge.']='No saved in-game send jobs.',
  ['Peer-ACK bedeutet nicht Serverannahme. Bei fehlender Verbindung: direkt im Web bestellen.']=
    'A peer ACK does not mean server acceptance. If there is no connection, order directly on the web.',
}
A._localeEN=EN -- exposed for the locale smoke test

local function clientLocale()
  return type(GetLocale)=='function' and GetLocale() or 'deDE'
end
-- 'deDE' or 'enUS'. A saved override wins over the client language.
function A:GetLanguage()
  local db=type(BanklordDB)=='table' and BanklordDB or nil
  local override=db and type(db.settings)=='table' and db.settings.locale
  if override=='deDE' or override=='enUS' then return override end
  return clientLocale()=='deDE' and 'deDE' or 'enUS'
end
function A:Tr(text)
  if A:GetLanguage()=='deDE' or type(text)~='string' then return text end
  return EN[text] or text -- Explicit fallback for not-yet-translated keys.
end
-- Translates a stored reason; "Prefix: details" reasons translate the prefix only.
function A:TrReason(reason)
  reason=tostring(reason)
  if A:GetLanguage()=='deDE' or EN[reason] then return A:Tr(reason) end
  local prefix,rest=reason:match('^(.-): (.*)$')
  if prefix and EN[prefix] then return EN[prefix]..': '..rest end
  return reason
end

local function languageName(code) return code=='deDE' and A:Tr('Deutsch') or A:Tr('Englisch') end
-- /banklord lang de | en | auto  (empty argument shows the current setting)
function A:SetLanguage(choice)
  choice=tostring(choice or ''):lower()
  local value
  if choice=='de' or choice=='dede' or choice=='deutsch' then value='deDE'
  elseif choice=='en' or choice=='enus' or choice=='english' then value='enUS'
  elseif choice=='auto' then value=false
  end
  local function describe()
    local settings=self.db and self.db.settings
    if settings and settings.locale then return languageName(settings.locale) end
    return A:Tr('automatisch – %s (Client: %s)'):format(languageName(A:GetLanguage()),tostring(clientLocale()))
  end
  if value==nil or not self.db then
    return self:Message(A:Tr('Sprache: %s. Ändern mit /banklord lang de | en | auto'):format(describe()))
  end
  self.db.settings=type(self.db.settings)=='table' and self.db.settings or {}
  self.db.settings.locale=value or nil
  if self.ApplyUILocale then self:ApplyUILocale() end
  self:Message(A:Tr('Sprache: %s. /reload wendet sie überall an.'):format(describe()))
end
