# Neuigkeiten und Pläne

[← Übersicht](README.md) · **Deutsch** · [English](neuigkeiten.en.md)

Was fertig ist, was gerade neu dazukam und was noch geplant ist. Updates kommen automatisch: Die Website spielt sie selbst ein, Addon und Companion aktualisieren sich auf jedem PC. Im Spiel reicht danach meist ein `/reload`.

**Anleitungen:** [Für Mitglieder](mitglieder.md) · [Für Bankverwalter](bankverwalter.md) · [Häufige Fragen](faq.md)

---

## In Planung

Ideen ohne festen Termin. Wünsche gern über **Hilfe & Feedback → Idee vorschlagen** auf der Website oder `/bl hilfe` im Spiel.

| Idee | Worum es geht |
|---|---|
| Kassenbuch | Gold-Ein- und -Auszahlungen der Gildenbank mitschreiben und anzeigen |
| Discord-Benachrichtigungen | Neue Bestellungen und Freigaben in einen Discord-Kanal schicken |
| Gilde auf der Website anlegen | Weitere Gilden (z. B. in Retail) ohne Kommandozeile anlegen |
| Ausführliche Tooltips für alle Spielversionen | Bisher nur in MoP Classic; sonst nur Symbol und Wowhead-Link |
| macOS und Linux fertigstellen | Setup, Companion und Updater laufen dort noch als Beta |

---

## Fertig

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
