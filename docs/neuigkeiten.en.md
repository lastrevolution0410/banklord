# What's new and planned

[← Overview](README.en.md) · [Deutsch](neuigkeiten.md) · **English**

What is done, what was just added and what is still planned. Updates arrive automatically: the website installs them itself, and the addon and Companion update on every PC. In game a `/reload` is usually enough afterwards.

**Guides:** [For members](mitglieder.en.md) · [For bank managers](bankverwalter.en.md) · [FAQ](faq.en.md)

---

## Planned

Ideas without a fixed date. Send wishes via **Help & feedback → Suggest an idea** on the website or `/bl help` in game.

| Idea | What it is about |
|---|---|
| Cash book | Record and show gold deposits and withdrawals of the guild bank |
| Discord notifications | Send new orders and approvals to a Discord channel |
| Create guilds on the website | Add further guilds (e.g. in Retail) without the command line |
| Detailed tooltips for every game version | So far only in MoP Classic; otherwise icon and Wowhead link only |
| Finish macOS and Linux | Setup, Companion and updater are still beta there |

---

## Done

### 1.5 – Game versions (18 Nov 2026)
- **One game version per guild:** Retail, MoP Classic, Classic Anniversary or Classic Era. One account can have guilds in several versions. Items and scans are never mixed.
- The game version is chosen during setup. Admins can change it under **System** while the guild has no stock yet.
- Wowhead links and icons match the guild's game version.
- Companion: every guild can use the Banklord.lua of its own WoW installation (e.g. `_retail_` and `_classic_`).
- 1.5.1: setting up new websites works again without a game version.
- 1.5.2: this "What's new and planned" page, linked under **Help & feedback** and in the footer.

### 1.4 – Order rules and partial deliveries (18 Nov 2026)
- **Weekly limit** per role. Above it an officer or admin approves ("approval needed").
- **Old orders** are cancelled automatically after a configurable number of days. Anything already sent stays booked.
- **View-only tabs:** items are visible but cannot be ordered.
- **Partial delivery:** bank managers can send part of an order, the rest stays open.
- **In-game notice** as soon as your order is on its way.
- **Cancel in game:** right-click your own order or `/bl cancel <no>`.
- **Tooltip:** shows "Guild bank: 20" on every item (can be turned off in the settings).
- **Shopping list** for bank managers, copyable for Auctionator and TSM (`/bl shopping`).
- 1.4.1: fixed partial deliveries and expired orders. Clearer website: order rules under **Orders**, account and system reorganised.

### 1.3 – Help and easier use (18 Nov 2026)
- **Help & feedback** in game and on the website: report bugs, suggest ideas, request a language.
- Bank managers can **reject** orders in game. Full bags stop withdrawing cleanly; next time only the rest is taken.
- Clearer in-game window: orders table with status and one action per order.
- Minimap button shows the number of open orders and blinks for new ones.
- Scans of all bank managers, officers and admins are accepted; automatic reconciliation for scans up to 12 hours.
- Companion and updater: one desktop icon, no flashing windows, saving no longer hangs.

### 1.2 – Joining in game (18 Nov 2026)
- **Unlock by confirming in game**, no codes to type.
- Bank managers never send an order twice.

### 1.1 – Bank managers in game (18 Nov 2026)
- **Bank manager tools in game:** withdraw, send, book automatically.
- A recipient character is required for website orders.
- Several guilds on one PC, times in local time.
- Setup with program icon and a hint about the Windows warning.

### 1.0 – First release (17 Nov 2026)
- **Catalogue** of the guild bank on the website, with search, categories and wish quantities.
- **Ordering** on the website and in game; review, approve and reject orders.
- **Stock scans** by the addon, reconciliation on the server, minimum stock and restocking.
- **One setup** for addon, Companion and automatic updates; signed updates.
- **Self-hosting** with a setup page; the website updates itself (with backup).
- Public **demo** with a sample guild, website in German and English.
