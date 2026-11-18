# Entwicklung

[← Übersicht](README.md)

## Aufbau

| Teil | Ordner | Technik |
|---|---|---|
| Addon | [`Banklord/`](../Banklord) | Lua 5.1, WoW-API; eine TOC für alle Versionen (`## Interface: 11508, 20505, 50504, 110207, 120005`) |
| Website und API | [`backend/`](../backend) | PHP 8.2 ohne Framework, MariaDB/MySQL; Migrationen in `backend/sql/` |
| Desktop-App | [`app/`](../app) | Go 1.23: Setup und Updater (`app/`), Companion (`app/companion`, `app/cmd/companion`) |
| Werkzeuge | [`dev/`](../dev) | Docker-Umgebung, Release-Bau, Simulationen |
| Tests | [`tests/`](../tests) | PHP-HTTP-Tests, Lua-Lade- und Verhaltenstests |

### Wichtige Dateien im Addon

| Datei | Inhalt |
|---|---|
| `Core.lua` | Start, Ereignisse, Befehle |
| `Scanner.lua` | Gildenbank-Scan |
| `Catalog.lua`, `UI.lua`, `Minimap.lua` | Katalog, Fenster, Minikarten-Knopf |
| `Orders.lua` | Warenkorb und Bestellungen im Spiel |
| `Manage.lua` | Bankverwalter: Hinweise, Entnehmen, Versenden, Absprache, Freischaltung |
| `Peer.lua` | Warenkorb an Mitspieler übergeben |
| `Feedback.lua` | Hilfe & Feedback im Spiel |
| `Locale.lua` | Englische Texte (Deutsch ist der Quelltext) |
| `ServerState.lua`, `Claim.lua` | Werden vom Companion geschrieben |

### Addon-Nachrichten (Gildenkanal)

| Präfix | Zweck |
|---|---|
| `BLNEW1` | `N` neue Bestellung · `C` Reservierung · `D` versendet · `X` freigegeben · `Q` Stand erfragen |
| `BLJOIN1` | `K` öffentlicher Schlüssel eines neuen Mitglieds · `S` Antwort mit Website-Adresse (Flüstern) |
| `BLSYNC1` | Warenkorb-Übergabe zwischen Spielern |

## Lokal starten

Voraussetzung: Docker.

```bash
cd dev && docker compose up -d
docker compose exec php php /app/dev/seed.php
```

Website: <http://localhost:8080>

## Tests

```bash
sh dev/run-tests.sh
```

Prüft PHP-Syntax, HTTP-Abläufe (Bestellungen, Rechte, Freischaltung, Selbst-Update), Lua-Syntax und -Verhalten (Addon lädt, Verwalter-Werkzeuge, Absprache mehrerer Verwalter, Übersetzungen) sowie Go-Tests mit `go vet` für Windows, macOS und Linux.

Jeder Fehler-Fix bekommt einen Test, der ohne den Fix fehlschlägt.

## Eigene Releases

Releases sind mit Ed25519 signiert. Websites und Desktop-Apps nehmen nur Pakete an, die mit dem Projektschlüssel signiert sind. Für einen eigenen Kanal:

1. Schlüssel erzeugen: `cd app && go run ./cmd/release-keygen`
2. Den öffentlichen Schlüssel in `app/update.go` und `backend/src/ReleasePublisher.php` ersetzen.
3. Mit `-ldflags "-X main.updateHost=<dein Host>"` bauen.
4. Release bauen und prüfen:

```bash
python3 -I dev/tools/build_release.py <version>
sh dev/release-sim.sh <version>
```

Den privaten Schlüssel nie ins Repository legen.
