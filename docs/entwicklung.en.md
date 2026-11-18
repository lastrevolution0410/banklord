# Development

[← Overview](README.en.md) · [Deutsch](entwicklung.md) · **English**

## Structure

| Part | Folder | Technology |
|---|---|---|
| Addon | [`Banklord/`](../Banklord) | Lua 5.1, WoW API; one TOC for all versions (`## Interface: 11508, 20505, 50504, 110207, 120005`) |
| Website and API | [`backend/`](../backend) | PHP 8.2 without framework, MariaDB/MySQL; migrations in `backend/sql/` |
| Desktop app | [`app/`](../app) | Go 1.23: setup and updater (`app/`), Companion (`app/companion`, `app/cmd/companion`) |
| Tools | [`dev/`](../dev) | Docker environment, release build, simulations |
| Tests | [`tests/`](../tests) | PHP HTTP tests, Lua load and behaviour tests |

### Important addon files

| File | Content |
|---|---|
| `Core.lua` | Start-up, events, commands |
| `Scanner.lua` | Guild bank scan |
| `Catalog.lua`, `UI.lua`, `Minimap.lua` | Catalog, window, minimap button |
| `Orders.lua` | Cart and orders in game |
| `Manage.lua` | Bank managers: notices, withdraw, send, coordination, unlocking |
| `Peer.lua` | Hand a cart to another player |
| `Feedback.lua` | Help & Feedback in game |
| `Locale.lua` | English texts (German is the source text) |
| `ServerState.lua`, `Claim.lua` | Written by the Companion |

### Addon messages (guild channel)

| Prefix | Purpose |
|---|---|
| `BLNEW1` | `N` new order · `C` reservation · `D` sent · `X` released · `Q` request state |
| `BLJOIN1` | `K` public key of a new member · `S` reply with website address (whisper) |
| `BLSYNC1` | Cart hand-over between players |

## Running locally

Requirement: Docker.

```bash
cd dev && docker compose up -d
docker compose exec php php /app/dev/seed.php
```

Website: <http://localhost:8080>

## Tests

```bash
sh dev/run-tests.sh
```

Checks PHP syntax, HTTP flows (orders, permissions, unlocking, self-update), Lua syntax and behaviour (addon loads, manager tools, coordination between several managers, translations) as well as Go tests with `go vet` for Windows, macOS and Linux.

Every bug fix gets a test that fails without the fix.

## Own releases

Releases are signed with Ed25519. Websites and desktop apps only accept packages signed with the project key. For your own channel:

1. Generate a key: `cd app && go run ./cmd/release-keygen`
2. Replace the public key in `app/update.go` and `backend/src/ReleasePublisher.php`.
3. Build with `-ldflags "-X main.updateHost=<your host>"`.
4. Build and check the release:

```bash
python3 -I dev/tools/build_release.py <version>
sh dev/release-sim.sh <version>
```

Never put the private key into the repository.
