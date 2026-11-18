# Anleitung für Mitglieder

[← Übersicht](README.md)

## Inhalt

1. [Einmal einrichten](#einmal-einrichten)
2. [Bestellen im Spiel](#bestellen-im-spiel)
3. [Bestellen auf der Website](#bestellen-auf-der-website)
4. [Was passiert nach der Bestellung?](#was-passiert-nach-der-bestellung)
5. [Hilfe & Feedback](#hilfe--feedback)
6. [Befehle im Spiel](#befehle-im-spiel)

---

## Einmal einrichten

### 1. Installieren

1. Öffne die Banklord-Website deiner Gilde und klicke unten auf **„Windows-Setup herunterladen“**.
2. Starte die Datei. Windows warnt beim ersten Mal vor einer unbekannten App: **„Weitere Informationen“ → „Trotzdem ausführen“** ([warum?](installation.md#windows-warnt-vor-einer-unbekannten-app)).
3. Im Setup sind alle gefundenen WoW-Versionen schon angehakt. Klicke **„Installieren / Aktualisieren“**.
4. Danach öffnet sich der Companion im Browser. Das Fenster kannst du offen lassen oder schließen – der Companion läuft weiter.

### 2. Freischalten – automatisch im Spiel

1. Starte WoW und logge dich mit einem Charakter deiner Gilde ein.
2. Gib einmal `/reload` ein. Ab jetzt meldet sich dein Addon bei den Bankverwaltern deiner Gilde.
3. Sobald ein **Bankverwalter, Offizier oder Admin online** ist, prüft sein Addon, dass du wirklich in der Gilde bist, und bestätigt dich.
4. Der Companion öffnet sich mit **„Freischaltung im Spiel“**. Prüfe, dass die angezeigte Website-Adresse die deiner Gilde ist, und klicke **„Verbinden“**.
5. Ist das dein erster Zugang, öffnet sich die Website: Lege **E-Mail, Anzeigename und ein Passwort** (mindestens 14 Zeichen) fest. Der Link gilt 7 Tage und nur einmal.

> **Erscheint „noch nicht bestätigt“?** Der Verwalter muss einmal `/reload` machen, damit seine Bestätigung zur Website gelangt. Dein Companion versucht es von selbst weiter.

### Ausweichweg: Einladung

Ist gerade kein Verwalter online, kann dir ein Admin auf der Website unter **Mitglieder** eine Einladung erstellen. Damit registrierst du dich auf der Website und verbindest danach den Companion unter **Konto**.

### Mehrere Charaktere oder Gilden

- **Mehrere Charaktere:** Einfach mit jedem einmal einloggen. Banklord merkt sich die Charaktere und ordnet sie der richtigen Gilde zu.
- **Mehrere Gilden** mit eigener Banklord-Website: Jede Gilde wird einzeln freigeschaltet. Im Companion stehen sie unter **„Weitere Gilden auf diesem PC“**.

---

## Bestellen im Spiel

1. Öffne Banklord mit `/banklord` oder über den Knopf an der Minikarte.
2. Suche den Gegenstand, gib die Menge ein und lege ihn in den **Warenkorb**.
3. Im Warenkorb auf **„Bestellen“**.
4. Gib `/reload` ein oder logge aus. Der Companion lädt die Bestellung hoch; Bankverwalter, die online sind, bekommen sofort einen Hinweis.

Der Bestand im Spiel ist der Stand vom letzten Scan der Gildenbank. Was „reserviert“ ist, hat schon jemand anderes bestellt.

## Bestellen auf der Website

1. Melde dich an. Unter **Gildenbank** siehst du den Bestand mit Suche und Filtern.
2. Lege Gegenstände in den **Warenkorb** oder auf die **Merkliste**.
3. Wähle im Warenkorb den **Empfänger-Charakter** – an ihn schickt der Bankverwalter die Post. Es erscheinen nur Charaktere, mit denen du dich mindestens einmal im Spiel mit Banklord eingeloggt hast.
4. **„Bestellen“**.

## Was passiert nach der Bestellung?

| Status | Bedeutung |
|---|---|
| **Eingereicht** | Die Bestellung ist da und wartet auf einen Bankverwalter. |
| **Reserviert** / **Teilweise** | Der Verwalter hält die Gegenstände (oder einen Teil) für dich zurück. |
| **Ausgegeben** | Die Post ist unterwegs. Schau in deinen Briefkasten. |
| **Abgelehnt** / **Storniert** | Die Bestellung wird nicht ausgeliefert. Der Grund steht unter **Meine Bestellungen**. |

Neuigkeiten siehst du auf der Website unter **Mitteilungen** und im Spiel nach dem nächsten `/reload`. Alle Zeiten werden in deiner Ortszeit angezeigt.

---

## Hilfe & Feedback

Etwas klemmt, du hast eine Idee oder möchtest Banklord in deiner Sprache?

| Wo | So geht's |
|---|---|
| **Im Spiel** | `/banklord feedback` oder Reiter **Hilfe & Feedback** im Banklord-Fenster. Gesendet wird beim nächsten `/reload`. |
| **Website** | Reiter **Hilfe & Feedback**. |

1. Wähle **Fehler melden**, **Idee vorschlagen** oder **Sprache anfragen**.
2. Fülle das kurze Formular aus und klicke **Absenden**.
3. Unter **Meine Meldungen** siehst du, ob deine Meldung eingegangen, geplant oder erledigt ist – und die Antwort.

Die Meldung geht direkt an das Banklord-Projekt. Mitgeschickt werden nur deine Angaben und die Versionsnummern, keine Konto- oder Charakterdaten. Eine E-Mail für Rückfragen ist freiwillig.

---

## Befehle im Spiel

Alle Befehle beginnen mit `/banklord`:

| Befehl | Wirkung |
|---|---|
| `/banklord` | Banklord-Fenster öffnen oder schließen |
| `liste`, `suche <Name>` | Bestand im Chat auflisten oder durchsuchen |
| `merken <Item-ID> <Menge>` | Gegenstand in den Warenkorb legen |
| `korb` | Warenkorb anzeigen |
| `bestellen` | Warenkorb bestellen |
| `leeren` | Warenkorb leeren |
| `senden <Spieler>` | Warenkorb an einen Mitspieler übergeben, der ihn für dich hochlädt (falls du keinen Companion hast) |
| `eingang`, `ausgang`, `erneut` | Übergebene Warenkörbe ansehen oder erneut senden |
| `status` | Zustand von Addon und letztem Scan |
| `bestellungen` | Offene Bestellungen (nur Bankverwalter) |
| `feedback` | Hilfe & Feedback: Fehler melden, Idee vorschlagen, Sprache anfragen |
| `minimap` | Minikarten-Knopf ein- oder ausblenden |
| `lang de`, `lang en`, `lang auto` | Sprache umstellen |
