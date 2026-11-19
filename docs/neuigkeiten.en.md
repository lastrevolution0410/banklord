# What's new and planned

[← Overview](README.en.md) · [Deutsch](neuigkeiten.md) · **English**

What is done, what was just added and what is still planned. Updates arrive automatically: the website installs them itself, and the addon and Companion update on every PC. In game a `/reload` is usually enough afterwards.

**Guides:** [For members](mitglieder.en.md) · [For bank managers](bankverwalter.en.md) · [FAQ](faq.en.md)

---

## Planned

Ideas without a fixed date. Send wishes via **Help & feedback → Suggest an idea** on the website or `/bl help` in game.

| Idea | What it is about |
|---|---|
| Finish macOS and Linux | Setup, Companion and updater are still beta there; tests on real devices are missing |

---

## Done

### 1.9 – WoW Forever (18 Nov 2026)
- **WoW Forever is supported:** its own game version on the website, the setup recognises the `_forever_` folder, addon, Companion and Wowhead links match. Forever has no realms; guilds are then recognised by name.
- Guide pages now show the Banklord icon in the browser tab too.
- 1.12.0: **Manage accounts:** under **Operator → Accounts** the operator locks and unlocks single accounts and resets passwords (link by e-mail or to pass on). Locked accounts are signed out at once, their Companion uploads nothing. The operator settings are laid out more clearly.
- 1.11.0: **Forgot password?** The website can now send e-mails: the start page offers a reset link (valid for one hour, usable once). The operator enters a mailbox under **Operator → Settings → Sending e-mail**; the password is stored encrypted.
- 1.10.0: **Guild leaders sign up on their own:** if the operator switches it on, the start page offers “Sign up your own guild for free” – account and guild in one step, no invitation code. With a daily limit, a per-connection brake and the per-account limit. The operator can **lock** single guilds.
- 1.9.13: registering with an invitation now needs a password of at least 14 characters, like every other way (it said 12 before).
- 1.9.12: when setting up a **WoW Forever guild** the website no longer asks for a realm (Forever has none).
- 1.9.11: **Guild bank without a tab** (e.g. a new guild in Forever): no more five times “Number of bank tabs is invalid”; a manual scan explains it in one sentence. Gold still counts.
- 1.9.11: **Ordering more than there is:** game and website now say right away “Only X in the bank – the rest stays open until restocked”.
- 1.9.11: **Version hint:** if the addon sees a newer Banklord version in your guild, it tells you once (start the Companion, then `/reload`).
- 1.9.11: **Trial accounts:** WoW does not allow addon messages on trial accounts. Instead of waiting for the in-game confirmation forever, the addon points to a website invitation.
- 1.9.10: the decorative corner at the top left no longer covers the “Guild bank” button.
- 1.9.9: **Tidier stock count:** the top tells you at once whether a new scan is waiting for approval (“Check now”) or everything is up to date. Below are checking and automatic approval; connecting the Companion, uploading a scan by hand and drafts from the game are under **“Advanced”** (it opens by itself while no Companion is connected yet).
- 1.9.9: **Clearer guild bank:** “You can order: X pieces” instead of jargon, one big **“Add to cart”** button, wishlist buttons smaller below it, technical numbers under “Details”. Stock that is not approved yet is now called exactly that.
- 1.9.9: **Restock:** as long as no minimum stock is set, the form is at the top.
- 1.9.9: every page starts with a short **“What do I do here?”**.
- 1.9.8: **New navigation:** the three main ways **Guild bank · Cart · My orders** are big at the top. Wishlist, “Account & guilds” and Help & feedback are in the **“More”** menu. Management is a calm second row, and the active tab stands out clearly.
- 1.9.8: **“Account & guilds”:** your guilds with role and “Switch” on top; “Redeem invitation” and “Set up your own guild” only open when clicked.
- 1.9.8: the feedback inbox (reports of all guilds) now sits with the operator under **Operator → Feedback inbox**; Help & feedback only shows guides, reporting and your own reports.
- 1.9.7: **Clearer layout:** the former “System” tab is split. Guild admins find game version, Discord and the guild's PCs under **Settings**. The operator has a tab of their own, **Operator**, with three sections: **Guilds** (table with search, game version filter and pages), **Settings** (limit for new guilds, demo) and **Updates & releases**.
- 1.9.6: **Set up your own guild – for everyone:** guild leaders and bank managers set up their guild themselves under **Account → “Set up your own guild”** and then see the next steps. The operator decides how many guilds an account may set up.
- 1.9.6: **Operator area:** the website's operator sees all guilds in the guild picker and under **System**, can open any of them (written to the guild's activity log) and sets the limit. Feedback inbox, website updates, demo switch and release keys are now operator-only – guild admins manage only their own guild.
- 1.9.5: **One account for several guilds:** if you are already signed in, redeem another guild's invitation under **Account → “Join another guild”** – no second account needed. Registering with an e-mail that already has an account now tells you exactly that.
- 1.9.4: the guides now show real in-game pictures (cart, guild bank, mailbox, orders). Under “Orders” the page count fits on one line again (“1 order · page 1/1”).
- 1.9.3: **Order history and activity log in game** for bank managers and officers: Orders → **Activity log** (or `/bl verlauf`, `/bl protokoll`) lists the guild's latest orders – including finished, rejected and cancelled ones – and the same activity log as the website.
- 1.9.3: the website's activity log now also reads clearly for “mailed”, “expired”, “approved above the weekly limit”, in-game joins and changed order rules.
- 1.9.2: **Orders reliably reach the game:** WoW only reads the Companion's data while loading. After “Transfer now” Banklord therefore offers a second “Load now” after about 25 seconds – then the order is in game. The minimap number stays until then.
- 1.9.2: **“My orders” tidied up:** only open orders by default; ticking “Show finished” adds delivered, rejected and cancelled ones. The right-click hint no longer covers the urgent option.
- 1.9.2: an order you just sent shows “On its way to the website – status arrives with the next load”.
- 1.9.1: **New in-game cash book:** clear window with an overview per player, gold and items in columns, deposits green, withdrawals red, no longer editable (the shopping list can only be copied, not changed, too). **Gold like in the game** with coin icons and thousands separators (1,661 ● 57 ● 61 ●) – in game and on the website.
- 1.9.1: arrows that showed as empty boxes in game are replaced.
- 1.9.1: in game, **“My orders”** shows “Right-click an order: cancel” whenever something can be cancelled.

### 1.8 – As simple as it gets (18 Nov 2026)
- **No more typing `/reload`:** at the important moments Banklord shows a window in game with a **“Transfer now”** button – after you are confirmed as a new member, after an order, for managers after confirming a member and when closing the mailbox after sending. Never in combat.
- **Start page with “Ready in 3 steps”** and a big download button instead of a small link at the bottom.
- **Clear hints:** if the Companion is missing, the addon says exactly what to do. Waiting for the unlock shows “you do not need to do anything”.
- **Guides with pictures,** step by step, with a “Not working?” table. For bank managers: “Your routine in 3 steps”.
- Setup and Companion only explain the simple way (start WoW → “Connect”).
- 1.8.1: **Manager role per guild:** an alt in another guild where you are only a member no longer gets the manager tools there.

### 1.7 – Cash book and "Urgent" in game (18 Nov 2026)
- **Cash book in game:** the guild bank's gold and latest entries in the addon window (**Orders → Cash book** or `/bl kasse`).
- **"Urgent" in game:** tick the box in the cart or use `/bl order urgent`. Bank managers see urgent orders with a red **!** at the top.
- 1.7.1: **Guides and this page right on the website,** also without signing in (links on the sign-in page, under **Help & feedback** and in the footer). They come with every update and always match GitHub.

### 1.6 – Cash book, bank value and Discord (18 Nov 2026)
We first compared what other guild bank addons offer (e.g. Guild Bank Ledger, Guild Bank Manager, TOGBankClassic, GuildVault) and took the best of it:
- **Cash book:** gold and item log of the guild bank, kept permanently instead of only the last 25 entries. With filters, contributions per player and CSV download.
- **Gold and bank value:** gold in the guild bank and the estimated market value of all items (TSM or Auctionator), also per item.
- **Discord notifications:** new orders, approval needed, sent, below minimum stock.
- **Urgent orders:** can be marked "Urgent" on the website, visible under Orders and in Discord.
- **Create further guilds on the website,** e.g. for another game version.
- **Tooltips in every game version:** the values come straight from the game; since 1.5 this works for every game version.

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
