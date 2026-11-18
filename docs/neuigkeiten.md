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

### 1.8 – Einfacher geht's nicht (18.11.2026)
- **Kein `/reload` mehr tippen:** An den wichtigen Stellen fragt Banklord im Spiel mit einem Fenster und dem Knopf **„Jetzt übertragen“** – nach der Bestätigung als neues Mitglied, nach einer Bestellung, für Verwalter nach dem Bestätigen eines Mitglieds und beim Schließen des Briefkastens nach dem Versenden. Nie im Kampf.
- **Startseite mit „In 3 Schritten startklar“** und großem Download-Knopf statt eines kleinen Links unten.
- **Klare Hinweise:** Fehlt der Companion, sagt das Addon genau, was zu tun ist. Wer auf die Freischaltung wartet, liest „du musst nichts tun“.
- **Anleitungen mit Bildern,** Schritt für Schritt, mit „Klappt nicht?“-Tabelle. Für Bankverwalter: „Dein Alltag in 3 Schritten“.
- Setup und Companion erklären nur noch den einfachen Weg (WoW starten → „Verbinden“).

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
