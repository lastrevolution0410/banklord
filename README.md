# Banklord

**Guild bank catalogue and ordering system for World of Warcraft: Mists of Pandaria Classic.**
Members browse the guild bank on a website or in game, put items in a cart and order them.
Bank officers review, reserve and hand out orders. Inventory is kept in sync from in‑game scans.

*Deutsch weiter unten.*

## Parts

| Part | Folder | Language |
|---|---|---|
| In‑game addon | `Banklord/` | Lua (WoW API, Interface 50504) |
| Website and API | `backend/` | PHP 8.2 + MariaDB/MySQL |
| Desktop app (setup, updater, companion) | `app/` | Go 1.23 |
| Dev tools and tests | `dev/`, `tests/` | Docker, Python, shell |

The addon cannot use the network. The desktop app reads the addon's SavedVariables after `/reload` or logout and uploads scans and orders to the website. It also writes the server's order status back into the addon.

## Status

Preview. Used in production by one guild. Windows is supported today; macOS and Linux support for the desktop app is in progress.

## Running it locally

Requirements: Docker.

```bash
cd dev && docker compose up -d
docker compose exec php php /app/dev/seed.php   # demo data
```

Open <http://localhost:8080>. Run all tests with `sh dev/run-tests.sh`.

## Self-hosting

1. Create an empty MySQL or MariaDB database at your web host.
2. Upload the `backend/` folder. The web server's document root must point to `backend/public`. Required PHP extensions are `pdo_mysql`, `sodium` and `zip`.
3. Open your website. The setup page appears. Enter the database access, your guild and your admin account, and you're done.

The setup page only exists until Banklord is configured. Your settings are stored in `backend/private/config.local.php`, see `backend/config.local.example.php` for optional keys.

**Updates:** A self-hosted website updates itself from the official signed release channel. It checks at most every 6 hours, verifies the signature, makes a database and file backup and then installs the new version. Admins see the status under *System* and can trigger a check there. Set `UPDATE_AUTO` to `off` to disable automatic installs, or `UPDATE_SOURCE` to `off` to disable the check entirely. The desktop app and addon update from the official channel on their own.


## Updates and signing

Releases are signed with Ed25519. Websites and desktop apps accept only manifests signed with the project key. Forks must create their own key with `go run ./cmd/release-keygen` and replace the public key in `update.go` and `ReleasePublisher.php`. They must also build with `-ldflags "-X main.updateHost=<your host>"`.

## License

Copyright © 2026 Björn Thiel Ebert.

Banklord is **source-available** under [CC BY-NC-ND 4.0](LICENSE), with [additional permissions](LICENSE-ADDITIONAL-PERMISSIONS.md):

- **Allowed:**
  - Use and self-host Banklord for free for your own guild or community.
  - Configure it.
  - Run your own signed update channel.
  - Send fixes and translations to the project.
- **Not allowed:**
  - Selling it or offering it as a paid service.
  - Publishing modified versions or forks under another name.

World of Warcraft and Blizzard Entertainment are trademarks of Blizzard Entertainment, Inc. This project is not affiliated with or endorsed by Blizzard. See [NOTICE.md](NOTICE.md) for third-party data.

---

## Deutsch

Banklord ist ein Gildenbank-Katalog mit Bestellsystem für MoP Classic.

- Mitglieder sehen den Bankbestand auf der Website oder im Spiel und bestellen über einen Warenkorb.
- Bankverwalter prüfen und reservieren die Bestellungen und geben sie aus.
- Der Bestand wird aus Scans im Spiel abgeglichen.

Lokaler Start und Selbst-Hosting stehen oben. Fehler und Wünsche bitte als Issue melden. Beiträge sind willkommen, siehe [CONTRIBUTING.md](CONTRIBUTING.md).
