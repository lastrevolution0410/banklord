# Häufige Fragen

[← Übersicht](README.md) · **Deutsch** · [English](faq.en.md)

**Allgemein**
- [Kostet Banklord etwas?](#kostet-banklord-etwas)
- [Ist das erlaubt? Verstößt es gegen Blizzards Regeln?](#ist-das-erlaubt)
- [Brauche ich den Companion?](#brauche-ich-den-companion)
- [Muss ich vor dem Spielen etwas aktualisieren?](#muss-ich-vor-dem-spielen-etwas-aktualisieren)

**Benutzung**
- [Warum muss ich so oft `/reload` machen?](#warum-muss-ich-so-oft-reload-machen)
- [Warum ist der Bestand nicht live?](#warum-ist-der-bestand-nicht-live)
- [Ich habe mehrere Charaktere und Gilden – geht das?](#mehrere-charaktere-und-gilden)

**Hilfe**
- [Wie melde ich einen Fehler?](#wie-melde-ich-einen-fehler)
- [Ich möchte Banklord in meiner Sprache](#ich-möchte-banklord-in-meiner-sprache)

---

### Kostet Banklord etwas?

Nein. Banklord ist ein kostenloses Fanprojekt. Verkaufen oder als bezahlten Dienst anbieten ist laut [Lizenz](../LICENSE-ADDITIONAL-PERMISSIONS.md) nicht erlaubt.

### Ist das erlaubt?

Banklord ist ein normales Addon und nutzt nur die offizielle Addon-Schnittstelle. Es automatisiert kein Spielen: Jeder Klick in der Gildenbank und jedes „Senden“ am Briefkasten macht der Spieler selbst. Der Companion liest nur die Datei, die WoW für Addons speichert; er greift nicht ins laufende Spiel ein.

### Brauche ich den Companion?

Für das Bestellen im Spiel mit automatischer Übertragung: ja. Ohne Companion kannst du auf der Website bestellen oder deinen Warenkorb mit `/banklord senden <Spieler>` an jemanden übergeben, der ihn hochlädt. Bankverwalter brauchen den Companion, damit Scans und Ausgaben auf der Website ankommen.

### Muss ich vor dem Spielen etwas aktualisieren?

Nein. Der Updater hält Addon und Companion im Hintergrund automatisch aktuell – beim Windows-Start, laufend auf neue Veröffentlichungen und direkt nach dem Beenden von WoW. Starte WoW einfach wie immer über Battle.net.

### Warum muss ich so oft `/reload` machen?

Addons dürfen im Spiel nicht ins Internet. WoW speichert Addon-Daten nur bei `/reload` oder beim Ausloggen und liest sie nur beim Laden ein. `/reload` ist der Moment, in dem Banklord mit der Website „spricht“.

### Warum ist der Bestand nicht live?

Den Inhalt der Gildenbank kann ein Addon nur lesen, wenn jemand sie geöffnet hat. Der Bestand ist deshalb der Stand vom letzten Scan; die Website zeigt, wann das war.

### Mehrere Charaktere und Gilden

Ja. Jeder Charakter wird beim Einloggen erkannt und seiner Gilde zugeordnet. Für jede Gilde mit eigener Banklord-Website gibt es eine eigene Verbindung im Companion.

Auch verschiedene Spielversionen gehen mit einem Konto, zum Beispiel eine Gilde in MoP Classic und eine in Retail. Jede Gilde hat genau eine Spielversion; Gegenstände und Scans werden nie vermischt. Im Companion wählst du beim Hinzufügen der Gilde die Banklord.lua der passenden WoW-Installation (`_retail_`, `_classic_`, `_anniversary_`).

### Wie melde ich einen Fehler?

- **Im Spiel:** `/banklord feedback` oder im Banklord-Fenster der Reiter **Hilfe & Feedback**. Die Meldung wird beim nächsten `/reload` über den Companion gesendet.
- **Auf der Website:** Reiter **Hilfe & Feedback**.
- **Auf GitHub:** [Issue anlegen](https://github.com/lastrevolution0410/banklord/issues/new/choose).

Dort wählst du **Fehler melden**, **Idee vorschlagen** oder **Sprache anfragen**. Die Meldung geht direkt an das Banklord-Projekt; unter **Meine Meldungen** siehst du den Status und die Antwort. Mitgeschickt werden nur deine Angaben und die Versionsnummern – keine Konto- oder Charakterdaten.

Bitte nie Passwörter, Zugangsschlüssel oder Einladungscodes mitschicken.

### Ich möchte Banklord in meiner Sprache

Banklord gibt es auf Deutsch und Englisch. Weitere Sprachen kannst du im Spiel oder auf der Website über **Hilfe & Feedback → Sprache anfragen** oder auf GitHub als [Sprachwunsch](https://github.com/lastrevolution0410/banklord/issues/new/choose) anfragen – gern auch mit Hilfe beim Übersetzen.
