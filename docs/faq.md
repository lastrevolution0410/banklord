# Häufige Fragen

[← Übersicht](README.md)

**Allgemein**
- [Kostet Banklord etwas?](#kostet-banklord-etwas)
- [Ist das erlaubt? Verstößt es gegen Blizzards Regeln?](#ist-das-erlaubt)
- [Brauche ich den Companion?](#brauche-ich-den-companion)
- [Was bringt „Banklord – sicher starten“?](#was-bringt-banklord--sicher-starten)

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

### Was bringt „Banklord – sicher starten“?

Es prüft vor dem Spielstart online, ob das Addon die neueste Version hat, aktualisiert es falls nötig und startet dann WoW. Nötig ist es nicht – der Updater hält alles im Hintergrund aktuell. Siehe [Installation](installation.md#was-wird-installiert).

### Warum muss ich so oft `/reload` machen?

Addons dürfen im Spiel nicht ins Internet. WoW speichert Addon-Daten nur bei `/reload` oder beim Ausloggen und liest sie nur beim Laden ein. `/reload` ist der Moment, in dem Banklord mit der Website „spricht“.

### Warum ist der Bestand nicht live?

Den Inhalt der Gildenbank kann ein Addon nur lesen, wenn jemand sie geöffnet hat. Der Bestand ist deshalb der Stand vom letzten Scan; die Website zeigt, wann das war.

### Mehrere Charaktere und Gilden

Ja. Jeder Charakter wird beim Einloggen erkannt und seiner Gilde zugeordnet. Für jede Gilde mit eigener Banklord-Website gibt es eine eigene Verbindung im Companion.

### Wie melde ich einen Fehler?

- **Im Spiel:** `/banklord` → **Hilfe & Feedback** *(ab einer der nächsten Versionen)*.
- **Auf der Website:** Menü **Hilfe & Feedback** *(ab einer der nächsten Versionen)*.
- **Auf GitHub:** [Issue anlegen](https://github.com/lastrevolution0410/banklord/issues/new/choose).

Bitte nie Passwörter, Zugangsschlüssel oder Einladungscodes mitschicken.

### Ich möchte Banklord in meiner Sprache

Banklord gibt es auf Deutsch und Englisch. Weitere Sprachen kannst du über **Hilfe & Feedback** oder auf GitHub als [Sprachwunsch](https://github.com/lastrevolution0410/banklord/issues/new/choose) anfragen – gern auch mit Hilfe beim Übersetzen.
