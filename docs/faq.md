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

Addons dürfen im Spiel nicht ins Internet. WoW speichert Addon-Daten nur bei `/reload` oder beim Ausloggen und liest sie nur beim Laden ein. `/reload` ist der Moment, in dem Banklord mit der Website „spricht“. Seit 1.8 musst du es meist nicht mehr tippen: An den wichtigen Stellen erscheint ein Fenster mit **„Jetzt übertragen“**.

### Warum ist der Bestand nicht live?

Den Inhalt der Gildenbank kann ein Addon nur lesen, wenn jemand sie geöffnet hat. Der Bestand ist deshalb der Stand vom letzten Scan; die Website zeigt, wann das war.

### Funktioniert Banklord mit WoW Forever?

Ja, seit 1.9. Das Setup findet den Ordner `_forever_` und installiert das Addon dort mit. Auf der Website wählst du beim Einrichten der Gilde (oder unter **Einstellungen**) die Spielversion **WoW Forever**. Forever hat keine Realms – das ist für Banklord kein Problem, Gilden werden dann am Namen erkannt. Forever ist noch neu: Fällt dir etwas auf, melde es bitte über **Hilfe & Feedback**.

Eine neue Gilde hat oft noch **kein Bankfach**. Dann gibt es nichts zu zählen – Gold ein- und auszahlen wird trotzdem im Kassenbuch erfasst. Sobald ihr das erste Fach kauft, zählt das Addon beim nächsten Öffnen der Gildenbank von selbst.

Mit einem **Testkonto** (Trial) lässt WoW keine Addon-Nachrichten zu. Die Freischaltung im Spiel klappt dann nicht – lass dir vom Bankverwalter stattdessen eine Einladung für die Website geben.

### Was passiert, wenn ich mehr bestelle, als in der Bank liegt?

Das geht. Banklord sagt dir dabei, wie viel gerade da ist. Der Bankverwalter schickt dir zuerst, was in der Bank liegt („Teil entnehmen“, „Teil senden“), der Rest bleibt als offene Bestellung stehen, bis nachgefüllt wird. Du bekommst im Spiel Bescheid, wenn ein Teil unterwegs ist. Den Rest kannst du jederzeit stornieren, oder der Bankverwalter lehnt ihn ab.

### Mehrere Charaktere und Gilden

Ja. Jeder Charakter wird beim Einloggen erkannt und seiner Gilde zugeordnet. Für jede Gilde mit eigener Banklord-Website gibt es eine eigene Verbindung im Companion.

**Ein Konto für mehrere Gilden:** Bist du schon auf der Website angemeldet und bekommst eine Einladung einer weiteren Gilde, löse sie unter **Mehr → Konto & Gilden → „Einladung einlösen“** ein. Ein zweites Konto brauchst du nicht; oben wechselst du zwischen deinen Gilden. Danach erzeugst du bei der neuen Gilde unter **Konto** einen Verbindungscode und fügst ihn im Companion unter **„Weitere Gilden auf diesem PC“** ein.

Auch verschiedene Spielversionen gehen mit einem Konto, zum Beispiel eine Gilde in MoP Classic und eine in Retail. Jede Gilde hat genau eine Spielversion; Gegenstände und Scans werden nie vermischt. Im Companion wählst du beim Hinzufügen der Gilde die Banklord.lua der passenden WoW-Installation (`_retail_`, `_classic_`, `_anniversary_`, `_forever_`).

**Beispiel MoP Classic, ein Realm:** Charakter A und B in derselben Gilde, Charakter C in einer anderen Gilde – das geht. Banklord merkt bei jedem Einloggen, in welcher Gilde der Charakter ist, und zeigt nur deren Bestand und Bestellungen. Eine Bestellung im Spiel landet immer bei der Gilde des Charakters, mit dem du bestellst.

**Bankverwalter mit dem einen, Mitglied mit dem anderen?** Die Rolle gehört zu deinem Website-Konto **pro Gilde**, nicht zum einzelnen Charakter:
- Bist du in Gilde 1 Bankverwalter und in Gilde 2 nur Mitglied, hat dein Charakter in Gilde 1 die Verwalter-Werkzeuge, dein Charakter in Gilde 2 nicht (seit 1.8.1).
- Innerhalb **derselben Gilde** haben alle deine Charaktere dieselbe Rolle – es bist ja dieselbe Person. Was ein Charakter aus der Gildenbank nehmen darf, bestimmt weiterhin der Gildenrang im Spiel.

### Wie melde ich einen Fehler?

- **Im Spiel:** `/banklord feedback` oder im Banklord-Fenster der Reiter **Hilfe & Feedback**. Die Meldung wird beim nächsten `/reload` über den Companion gesendet.
- **Auf der Website:** **Mehr → Hilfe & Feedback**.
- **Auf GitHub:** [Issue anlegen](https://github.com/lastrevolution0410/banklord/issues/new/choose).

Dort wählst du **Fehler melden**, **Idee vorschlagen** oder **Sprache anfragen**. Die Meldung geht direkt an das Banklord-Projekt; unter **Meine Meldungen** siehst du den Status und die Antwort. Mitgeschickt werden nur deine Angaben und die Versionsnummern – keine Konto- oder Charakterdaten.

Bitte nie Passwörter, Zugangsschlüssel oder Einladungscodes mitschicken.

### Ich möchte Banklord in meiner Sprache

Banklord gibt es auf Deutsch und Englisch. Weitere Sprachen kannst du im Spiel oder auf der Website über **Hilfe & Feedback → Sprache anfragen** oder auf GitHub als [Sprachwunsch](https://github.com/lastrevolution0410/banklord/issues/new/choose) anfragen – gern auch mit Hilfe beim Übersetzen.
