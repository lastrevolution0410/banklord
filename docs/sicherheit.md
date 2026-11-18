# Sicherheit und Datenschutz

[← Übersicht](README.md) · **Deutsch** · [English](sicherheit.en.md)

## Kurz gesagt

- Banklord braucht **keine Blizzard-Zugangsdaten** und liest nichts außer dem eigenen Addon-Speicher.
- Daten gehen nur zur **Website deiner Gilde** (HTTPS). Update-Prüfungen gehen an den offiziellen Release-Kanal; dabei werden keine persönlichen Daten gesendet.
- Jedes Update ist **digital signiert**; unsignierte, manipulierte oder ältere Pakete werden abgelehnt.

## Welche Daten wohin?

| Daten | Wo gespeichert | Wer sieht sie |
|---|---|---|
| Gildenbank-Bestand (Scans) | Website der Gilde | Alle Mitglieder der Gilde |
| Bestellungen, Empfänger-Charakter | Website der Gilde | Das Mitglied selbst, Bankverwalter, Offiziere, Admins |
| E-Mail, Anzeigename, Passwort-Hash | Website der Gilde | Admins (E-Mail), niemand (Passwort) |
| Charakternamen und Realm | Website der Gilde | Mitglieder der Gilde |
| Kassenbuch (Bankprotokoll aus dem Spiel: Spielername, Gold, Gegenstände) | Website der Gilde | Bankverwalter, Offiziere, Admins; Mitglieder sehen nur Goldstand und Bankwert |
| Discord-Webhook-URL | Website der Gilde; Nachrichten gehen nur an discord.com | Wird nach dem Speichern nie wieder angezeigt. Nachrichten ohne @-Erwähnungen |
| Hilfe-&-Feedback-Meldungen | Banklord-Projekt (offizielle Seite); auf der Gilden-Website nur Titel und Status | Projektbetreuer. Ohne Konto-, Charakter- oder Gildendaten; E-Mail nur, wenn freiwillig angegeben |
| Zugangsschlüssel des Companions | PC: Windows-Datenschutz (DPAPI), macOS-Schlüsselbund, Linux Secret Service. Website: nur als Hash. | Niemand im Klartext |

## Freischaltung im Spiel

Ziel: Nur wer **wirklich in der Gilde** ist, bekommt Zugang – ohne Einladungscodes.

1. Der Companion erzeugt auf dem PC ein **Schlüsselpaar (Ed25519)**. Der private Schlüssel verlässt den PC nie und ist wie der Zugangsschlüssel geschützt.
2. Nur der **öffentliche** Schlüssel kommt ins Addon und wird im Gilden-Addon-Kanal gesendet.
3. Das Addon eines **Bankverwalters, Offiziers oder Admins** prüft den Absender gegen die echte Gildenliste. Den Absender einer Addon-Nachricht setzt Blizzards Server; er kann nicht gefälscht werden.
4. Der Companion des Verwalters lädt die Bestätigung mit **seinem** Zugang hoch. Die Website akzeptiert sie nur von diesen Rollen, nur für die eigene Gilde und nur, wenn sie höchstens 14 Tage alt ist.
5. Der Companion des Mitglieds holt eine **einmalige Zufallszahl** (5 Minuten gültig) und unterschreibt sie zusammen mit der **Adresse der Website**. Eine Unterschrift für eine andere Website ist dort wertlos; jede Zufallszahl gilt nur einmal.
6. Erst dann gibt es einen eigenen Zugang (Bestell-Rechte, 180 Tage; bei Bankverwaltern, Offizieren und Admins nimmt die Website darüber auch Scans an – geprüft wird bei jedem Upload die aktuelle Rolle) und für neue Konten einen **einmaligen Registrierungslink** (7 Tage), mit dem das Mitglied selbst E-Mail und Passwort festlegt.

Der Companion verbindet sich nicht von selbst: Du siehst die Website-Adresse und klickst „Verbinden“.

## Website

- Passwörter mit `password_hash` (PHP-Standard, bcrypt), mindestens 14 Zeichen bei der Registrierung.
- Schutz gegen Cross-Site-Requests (CSRF-Token), strikte Content-Security-Policy, sichere Cookies.
- Zugangsschlüssel und Einmal-Links werden nur als SHA-256-Hash gespeichert.
- Alle wichtigen Aktionen landen im **Protokoll**.

## Desktop-App

- Die lokale Bedienseite des Companions und des Setups ist nur von diesem PC erreichbar und mit einem geheimen, zufälligen Pfad und CSRF-Token geschützt.
- Updates werden mit Ed25519 geprüft, bevor sie installiert werden; ein Downgrade ist ausgeschlossen.
- Dateien, die Banklord in den Addon-Ordner schreibt (`ServerState.lua`, `Claim.lua`), werden aus geprüften Daten erzeugt; Texte von der Website werden so maskiert, dass sie im Spiel nie als Code ausgeführt werden.

## Sicherheitslücke gefunden?

Bitte **nicht** als öffentliches Issue, sondern wie in [SECURITY.md](../SECURITY.md) beschrieben.
