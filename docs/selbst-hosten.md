# Banklord selbst hosten

[← Übersicht](README.md)

Banklord darf jede Gilde kostenlos auf ihrem eigenen Webspace betreiben ([Lizenz](../LICENSE-ADDITIONAL-PERMISSIONS.md)).

## Voraussetzungen

| | Mindestens |
|---|---|
| PHP | 8.2 mit `pdo_mysql`, `sodium`, `zip` |
| Datenbank | MariaDB 10.6+ oder MySQL 8 |
| Webserver | Apache oder nginx, **HTTPS** |
| Speicher | ca. 50 MB plus Datenbank |

## Einrichten

1. **Datenbank** beim Hoster anlegen (leer).
2. **Dateien hochladen:** den Ordner `backend/` aus dem [neuesten Release](https://github.com/lastrevolution0410/banklord/releases) (Web-Paket). Das **Document Root** der Domain muss auf `backend/public` zeigen.
3. **Website öffnen.** Die Einrichtungsseite erscheint. Datenbank-Zugang, Gildenname, Realm und dein Admin-Konto eintragen – fertig.

Die Einrichtungsseite existiert nur, bis Banklord konfiguriert ist. Die Einstellungen liegen in `backend/private/config.local.php`. Optionale Schlüssel zeigt [`backend/config.local.example.php`](../backend/config.local.example.php).

## Erste Schritte als Admin

1. Unter **Mitglieder** die Bankverwalter festlegen (Rolle *Bankverwalter* oder *Offizier*).
2. Die Bankverwalter installieren das Setup von deiner Website. Ab dann schalten sie neue Mitglieder automatisch im Spiel frei ([wie?](bankverwalter.md#neue-mitglieder-bestätigen)).
3. Ein Bankverwalter öffnet einmal die Gildenbank im Spiel und macht `/reload` – der erste Bestand erscheint auf der Website.

## Updates

Die Website aktualisiert sich selbst aus dem offiziellen, signierten Release-Kanal:

- Prüfung höchstens alle 6 Stunden, Signaturprüfung, danach **Backup von Datenbank und Dateien**, dann Installation inklusive Datenbank-Änderungen.
- Status und „Jetzt prüfen“ unter **System**.
- In `config.local.php`:
  - `'UPDATE_AUTO' => 'off'` – nicht automatisch installieren (Knopf unter System geht weiter).
  - `'UPDATE_SOURCE' => 'off'` – nie den offiziellen Kanal kontaktieren.

Addon und Desktop-App holen ihre Updates selbst; dafür musst du nichts tun.

## Hilfe & Feedback

Meldungen deiner Mitglieder (Fehler, Ideen, Sprachwünsche) leitet deine Website an das Banklord-Projekt weiter – an dieselbe Adresse wie der Update-Kanal, ohne Konto- oder Charakterdaten.

- `'FEEDBACK' => 'off'` – Meldungen ausschalten.
- Mit `'UPDATE_SOURCE' => 'off'` bleiben Meldungen auf deiner eigenen Website; Admins sehen sie dann unter **Hilfe & Feedback → Posteingang**.

## Backups

Vor jedem Update legt Banklord ein Backup an. Zusätzlich empfohlen: das regelmäßige Datenbank-Backup deines Hosters einschalten.

## Eigener Update-Kanal

Wer eigene Builds verteilen will, braucht einen eigenen Signaturschlüssel – siehe [Entwicklung](entwicklung.md#eigene-releases).
