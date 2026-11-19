# Neuigkeiten und Pläne

[← Übersicht](README.md) · **Deutsch** · [English](neuigkeiten.en.md)

Was fertig ist, was gerade neu dazukam und was noch geplant ist. Updates kommen automatisch: Die Website spielt sie selbst ein, Addon und Companion aktualisieren sich auf jedem PC. Im Spiel reicht danach meist ein `/reload`.

**Anleitungen:** [Für Mitglieder](mitglieder.md) · [Für Bankverwalter](bankverwalter.md) · [Häufige Fragen](faq.md)

---

## In Planung

Ideen ohne festen Termin. Wünsche gern über **Hilfe & Feedback → Idee vorschlagen** auf der Website oder `/bl hilfe` im Spiel.

| Idee | Worum es geht |
|---|---|
| macOS und Linux fertigstellen | Setup, Companion und Updater laufen dort noch als Beta; es fehlen Tests auf echten Geräten |

---

## Fertig

### 1.9 – WoW Forever (18.11.2026)
- **WoW Forever wird unterstützt:** eigene Spielversion auf der Website, das Setup erkennt den Ordner `_forever_`, Addon, Companion und Wowhead-Links passen dazu. Forever hat keine Realms; Gilden werden dann am Namen erkannt.
- Anleitungsseiten zeigen jetzt auch das Banklord-Symbol im Browser-Tab.
- 1.12.5: Keine rätselhafte Meldung „LOGIN_REQUIRED“ mehr: Ist die Anmeldung abgelaufen, erscheint einfach wieder die Anmeldeseite mit einem kurzen Hinweis.
- 1.12.4: Aufgeräumte Startseite: Anmelden, „Neu hier?“ und „Gilde anmelden“ sind jetzt Reiter statt einer langen Liste. Passwort vergessen und Einladungscode sitzen direkt unter dem Anmelden-Formular.
- 1.12.3: E-Mail-Versand: versehentliche Leerzeichen vor oder nach dem eingefügten Passwort werden entfernt, und das Formular gibt Tipps, wenn die Anmeldung am Mailserver scheitert.
- 1.12.2: Klappt die Test-E-Mail nicht, sagt die Website jetzt genau, woran es liegt (Server nicht erreichbar, Verschlüsselung, Benutzer/Passwort falsch, Absender abgelehnt) – mit dem Code des Mailservers.
- 1.12.1: **Betreiber → Konten** findet Konten jetzt auch über den Charakternamen und zeigt die Charaktere an; das Suchfeld wird nicht mehr vom Browser vorausgefüllt.
- 1.12.0: **Konten verwalten:** Unter **Betreiber → Konten** sperrt und entsperrt der Betreiber einzelne Konten und setzt Passwörter zurück (Link per E-Mail oder zum Weitergeben). Gesperrte Konten fliegen sofort raus, ihr Companion lädt nichts mehr hoch. Die Betreiber-Einstellungen sind übersichtlicher angeordnet.
- 1.11.0: **Passwort vergessen?** Die Website kann jetzt E-Mails verschicken: Auf der Startseite gibt es einen Link zum Zurücksetzen (eine Stunde gültig, nur einmal nutzbar). Der Betreiber trägt dafür unter **Betreiber → Einstellungen → E-Mail-Versand** ein Postfach ein; das Passwort wird verschlüsselt gespeichert.
- 1.10.0: **Gildenleiter melden sich selbst an:** Schaltet der Betreiber es ein, steht auf der Startseite „Eigene Gilde kostenlos anmelden“ – Konto und Gilde in einem Schritt, ohne Einladungscode. Mit Tageslimit, Bremse pro Anschluss und Konto-Limit. Der Betreiber kann einzelne Gilden **sperren**.
- 1.9.13: Registrieren mit Einladung verlangt jetzt wie alle anderen Wege ein Passwort mit mindestens 14 Zeichen (vorher stand dort 12).
- 1.9.12: Beim Anlegen einer **WoW-Forever-Gilde** fragt die Website nicht mehr nach einem Realm (Forever hat keine).
- 1.9.11: **Gildenbank ohne Bankfach** (z. B. neue Gilde in Forever): statt fünfmal „Anzahl der Bankfächer ist ungültig“ kommt kein Spam mehr; ein Scan von Hand erklärt es in einem Satz. Gold zählt trotzdem.
- 1.9.11: **Mehr bestellen als da ist:** Spiel und Website sagen jetzt sofort „Nur X in der Bank – der Rest bleibt offen, bis nachgefüllt wird“.
- 1.9.11: **Versionshinweis:** Sieht das Addon in der Gilde eine neuere Banklord-Version, sagt es einmal Bescheid (Companion starten, dann `/reload`).
- 1.9.11: **Testkonten:** WoW erlaubt Testkonten keine Addon-Nachrichten. Statt endlos auf die Freischaltung zu warten, verweist das Addon auf eine Einladung für die Website.
- 1.9.10: Die Zierecke oben links überdeckt den Knopf „Gildenbank“ nicht mehr.
- 1.9.9: **Inventur aufgeräumt:** oben steht sofort, ob ein neuer Scan auf Freigabe wartet („Jetzt prüfen“) oder alles aktuell ist. Darunter Prüfen und automatische Freigabe; Companion verbinden, Scan von Hand hochladen und Entwürfe aus dem Spiel stecken unter **„Erweitert“** (öffnet sich von selbst, solange noch kein Companion verbunden ist).
- 1.9.9: **Gildenbank verständlicher:** „Du kannst bestellen: X Stück“ statt Fachbegriffen, ein großer Knopf **„In den Warenkorb“**, Merken und Wunschliste klein darunter, technische Nummern unter „Details“. Noch nicht freigegebene Bestände heißen jetzt auch so.
- 1.9.9: **Nachkauf:** solange noch kein Mindestbestand festgelegt ist, steht das Formular ganz oben.
- 1.9.9: jede Seite beginnt mit einem kurzen **„Was mache ich hier?“**.
- 1.9.8: **Neue Navigation:** Oben stehen groß die drei Hauptwege **Gildenbank · Warenkorb · Meine Bestellungen**. Merkliste, „Konto & Gilden“ und Hilfe & Feedback stecken im Menü **„Mehr“**. Die Verwaltung ist eine ruhige zweite Zeile, der aktive Reiter ist deutlich hervorgehoben.
- 1.9.8: **„Konto & Gilden“:** oben deine Gilden mit Rolle und „Wechseln“; „Einladung einlösen“ und „Eigene Gilde anlegen“ klappen erst beim Klick auf.
- 1.9.8: Der Feedback-Eingang (Meldungen aller Gilden) liegt jetzt beim Betreiber unter **Betreiber → Feedback-Eingang**; Hilfe & Feedback zeigt nur noch Anleitungen, Melden und deine Meldungen.
- 1.9.7: **Übersichtlicher:** Der frühere Reiter „System“ ist aufgeteilt. Gilden-Admins finden Spielversion, Discord und die PCs der Gilde unter **Einstellungen**. Der Betreiber hat einen eigenen Reiter **Betreiber** mit drei Bereichen: **Gilden** (Tabelle mit Suche, Filter nach Spielversion und Seiten), **Einstellungen** (Limit für neue Gilden, Demo) und **Updates & Releases**.
- 1.9.6: **Eigene Gilde anlegen – für jeden:** Gildenleiter und Bankverwalter legen ihre Gilde selbst unter **Konto → „Eigene Gilde anlegen“** an und bekommen danach die nächsten Schritte angezeigt. Wie viele Gilden ein Konto anlegen darf, stellt der Betreiber ein.
- 1.9.6: **Betreiber-Bereich:** Der Betreiber der Website sieht alle Gilden im Auswahlfeld und unter **System**, kann jede öffnen (steht im Protokoll der Gilde) und stellt das Limit ein. Feedback-Eingang, Website-Updates, Demo-Schalter und Release-Schlüssel sind jetzt nur noch für den Betreiber da – Gilden-Admins verwalten nur ihre eigene Gilde.
- 1.9.5: **Ein Konto für mehrere Gilden:** Wer schon angemeldet ist, löst eine Einladung einer weiteren Gilde unter **Konto → „Weiterer Gilde beitreten“** ein – kein zweites Konto mehr nötig. Wer beim Registrieren eine schon vorhandene E-Mail eingibt, bekommt genau diesen Hinweis.
- 1.9.4: Die Anleitungen zeigen jetzt echte Bilder aus dem Spiel (Warenkorb, Gildenbank, Briefkasten, Aufträge). Unter „Aufträge“ steht die Seitenzahl wieder in einer Zeile („1 Auftrag · Seite 1/1“).
- 1.9.3: **Bestellverlauf und Protokoll im Spiel** für Bankverwalter und Offiziere: Aufträge → **Protokoll** (oder `/bl verlauf`, `/bl protokoll`) zeigt die letzten Bestellungen der ganzen Gilde – auch erledigte, abgelehnte und stornierte – und dasselbe Protokoll wie auf der Website.
- 1.9.3: Im Protokoll der Website erscheinen jetzt auch „per Post verschickt“, „abgelaufen“, „über dem Wochenlimit freigegeben“, Beitritte im Spiel und geänderte Bestellregeln verständlich.
- 1.9.2: **Bestellung kommt sicher ins Spiel:** WoW liest die Daten des Companions nur beim Laden. Nach „Jetzt übertragen“ bietet Banklord darum nach etwa 25 Sekunden ein zweites „Jetzt laden“ an – dann ist die Bestellung im Spiel. Die Zahl an der Minikarte bleibt bis dahin stehen.
- 1.9.2: **„Meine Bestellungen“ aufgeräumt:** standardmäßig nur offene Bestellungen; ein Haken bei „Erledigte zeigen“ blendet ausgegebene, abgelehnte und stornierte ein. Der Rechtsklick-Hinweis überdeckt die Dringend-Option nicht mehr.
- 1.9.2: Eine gerade abgeschickte Bestellung zeigt „Unterwegs zur Website – Stand kommt beim nächsten Laden“.
- 1.9.1: **Kassenbuch im Spiel neu:** übersichtliches Fenster mit Übersicht pro Spieler, Gold und Gegenständen in Spalten, Einzahlungen grün, Entnahmen rot, nicht mehr beschreibbar (auch die Einkaufsliste lässt sich nur noch kopieren, nicht mehr ändern). **Gold wie im Spiel** mit Münz-Symbolen und Tausenderpunkt (1.661 ● 57 ● 61 ●) – im Spiel und auf der Website.
- 1.9.1: Pfeile, die im Spiel als leere Kästchen erschienen, sind ersetzt.
- 1.9.1: Im Spiel steht unter **„Meine Bestellungen“** sichtbar „Rechtsklick auf eine Bestellung: stornieren“, sobald etwas stornierbar ist.

### 1.8 – Einfacher geht's nicht (18.11.2026)
- **Kein `/reload` mehr tippen:** An den wichtigen Stellen fragt Banklord im Spiel mit einem Fenster und dem Knopf **„Jetzt übertragen“** – nach der Bestätigung als neues Mitglied, nach einer Bestellung, für Verwalter nach dem Bestätigen eines Mitglieds und beim Schließen des Briefkastens nach dem Versenden. Nie im Kampf.
- **Startseite mit „In 3 Schritten startklar“** und großem Download-Knopf statt eines kleinen Links unten.
- **Klare Hinweise:** Fehlt der Companion, sagt das Addon genau, was zu tun ist. Wer auf die Freischaltung wartet, liest „du musst nichts tun“.
- **Anleitungen mit Bildern,** Schritt für Schritt, mit „Klappt nicht?“-Tabelle. Für Bankverwalter: „Dein Alltag in 3 Schritten“.
- Setup und Companion erklären nur noch den einfachen Weg (WoW starten → „Verbinden“).
- 1.8.1: **Verwalter-Rolle pro Gilde:** Ein Twink in einer anderen Gilde, in der du nur Mitglied bist, bekommt dort keine Verwalter-Werkzeuge mehr.

### 1.7 – Kassenbuch und „Dringend“ im Spiel (18.11.2026)
- **Kassenbuch im Spiel:** Goldstand der Gildenbank und die letzten Einträge im Addon-Fenster (**Aufträge → Kassenbuch** oder `/bl kasse`).
- **„Dringend“ im Spiel:** Häkchen im Warenkorb oder `/bl bestellen dringend`. Bankverwalter sehen dringende Bestellungen mit rotem **!** ganz oben.
- 1.7.1: **Anleitungen und diese Seite direkt auf der Website,** auch ohne Anmeldung (Links auf der Anmeldeseite, unter **Hilfe & Feedback** und in der Fußzeile). Sie kommen mit jedem Update mit und haben immer denselben Stand wie GitHub.

### 1.6 – Kassenbuch, Bankwert und Discord (18.11.2026)
Vorher haben wir verglichen, was andere Gildenbank-Addons können (z. B. Guild Bank Ledger, Guild Bank Manager, TOGBankClassic, GuildVault), und das Beste übernommen:
- **Kassenbuch:** Gold- und Gegenstandsprotokoll der Gildenbank, dauerhaft gespeichert statt nur der letzten 25 Einträge. Mit Filtern, Beiträgen pro Spieler und CSV-Download.
- **Goldstand und Bankwert:** Gold in der Gildenbank und geschätzter Marktwert aller Gegenstände (TSM oder Auctionator), auch pro Gegenstand.
- **Discord-Benachrichtigungen:** neue Bestellungen, Freigabe nötig, verschickt, unter Mindestbestand.
- **Dringende Bestellungen:** auf der Website als „Dringend“ markierbar, in Aufträgen und Discord sichtbar.
- **Weitere Gilden auf der Website anlegen,** z. B. für eine andere Spielversion.
- **Tooltips in allen Spielversionen:** Die Werte kommen direkt aus dem Spiel; das gilt seit 1.5 für jede Spielversion.

### 1.5 – Spielversionen (18.11.2026)
- **Eine Spielversion pro Gilde:** Retail, MoP Classic, Classic Anniversary oder Classic Era. Ein Konto kann Gilden in mehreren Versionen haben. Gegenstände und Scans werden nie vermischt.
- Die Spielversion wird beim Einrichten gewählt. Admins können sie unter **System** ändern, solange die Gilde noch keinen Bestand hat.
- Wowhead-Links und Symbole passen zur Spielversion der Gilde.
- Companion: Jede Gilde kann die Banklord.lua ihrer eigenen WoW-Installation nutzen (z. B. `_retail_` und `_classic_`).
- 1.5.1: Einrichtung neuer Websites funktioniert wieder ohne Angabe der Spielversion.
- 1.5.2: Diese Seite „Neuigkeiten und Pläne“, verlinkt unter **Hilfe & Feedback** und in der Fußzeile.

### 1.4 – Bestellregeln und Teillieferungen (18.11.2026)
- **Wochenlimit** pro Rolle. Darüber gibt ein Offizier oder Admin frei („Freigabe nötig“).
- **Alte Bestellungen** werden nach einer einstellbaren Zahl von Tagen automatisch storniert. Schon Verschicktes bleibt gebucht.
- **Nur-ansehen-Tabs:** Gegenstände sind sichtbar, aber nicht bestellbar.
- **Teillieferung:** Bankverwalter können einen Teil verschicken, der Rest bleibt offen.
- **Hinweis im Spiel**, sobald deine Bestellung unterwegs ist.
- **Stornieren im Spiel:** Rechtsklick auf die eigene Bestellung oder `/bl stornieren <Nr>`.
- **Tooltip:** zeigt „Gildenbank: 20“ an jedem Gegenstand (in den Einstellungen abschaltbar).
- **Einkaufsliste** für Bankverwalter, kopierbar für Auctionator und TSM (`/bl einkauf`).
- 1.4.1: Fehler bei Teillieferungen und abgelaufenen Bestellungen behoben. Website übersichtlicher: Bestellregeln unter **Aufträge**, Konto und System neu gegliedert.

### 1.3 – Hilfe und bessere Bedienung (18.11.2026)
- **Hilfe & Feedback** im Spiel und auf der Website: Fehler melden, Ideen vorschlagen, Sprache anfragen.
- Bankverwalter können Bestellungen im Spiel **ablehnen**. Volle Taschen unterbrechen das Entnehmen sauber; beim nächsten Mal wird nur der Rest geholt.
- Übersichtlicheres Fenster im Spiel: Aufträge-Tabelle mit Status und einer Aktion pro Bestellung.
- Minimap-Knopf zeigt die Zahl offener Bestellungen und blinkt bei neuen.
- Scans aller Bankverwalter, Offiziere und Admins werden angenommen; automatischer Abgleich für Scans bis 12 Stunden.
- Companion und Updater: ein Desktop-Symbol, keine aufblitzenden Fenster, Speichern hängt nicht mehr.

### 1.2 – Beitritt im Spiel (18.11.2026)
- **Freischalten per Bestätigung im Spiel**, ohne Codes abzutippen.
- Bankverwalter verschicken eine Bestellung nie doppelt.

### 1.1 – Bankverwalter im Spiel (18.11.2026)
- **Werkzeuge für Bankverwalter im Spiel:** entnehmen, verschicken, automatisch buchen.
- Empfänger-Charakter ist bei Bestellungen über die Website Pflicht.
- Mehrere Gilden auf einem PC, Zeiten in Ortszeit.
- Setup mit Programmsymbol und Hinweis zur Windows-Warnung.

### 1.0 – Erste Version (17.11.2026)
- **Katalog** der Gildenbank auf der Website, mit Suche, Kategorien und Wunschmengen.
- **Bestellen** auf der Website und im Spiel, Bestellungen prüfen, freigeben und ablehnen.
- **Inventur** per Addon-Scan, Abgleich auf dem Server, Mindestbestände und Nachkauf.
- **Ein Setup** für Addon, Companion und automatische Updates; signierte Updates.
- **Selbst hosten** mit Einrichtungsseite; die Website aktualisiert sich selbst (mit Backup).
- Öffentliche **Demo** mit Beispielgilde, Website auf Deutsch und Englisch.
