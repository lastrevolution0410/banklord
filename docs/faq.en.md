# Frequently asked questions

[← Overview](README.en.md) · [Deutsch](faq.md) · **English**

**General**
- [Does Banklord cost anything?](#does-banklord-cost-anything)
- [Is this allowed? Does it break Blizzard's rules?](#is-this-allowed)
- [Do I need the Companion?](#do-i-need-the-companion)
- [Do I have to update anything before playing?](#do-i-have-to-update-anything-before-playing)

**Usage**
- [Why do I have to `/reload` so often?](#why-do-i-have-to-reload-so-often)
- [Why is the stock not live?](#why-is-the-stock-not-live)
- [I have several characters and guilds – does that work?](#several-characters-and-guilds)

**Help**
- [How do I report a bug?](#how-do-i-report-a-bug)
- [I would like Banklord in my language](#i-would-like-banklord-in-my-language)

---

### Does Banklord cost anything?

No. Banklord is a free fan project. Selling it or offering it as a paid service is not allowed under the [license](../LICENSE-ADDITIONAL-PERMISSIONS.md).

### Is this allowed?

Banklord is a normal addon and only uses the official addon interface. It does not automate gameplay: every click in the guild bank and every “Send” at the mailbox is done by the player. The Companion only reads the file WoW saves for addons; it does not interfere with the running game.

### Do I need the Companion?

For ordering in game with automatic transfer: yes. Without the Companion you can order on the website or hand your cart to someone with `/banklord senden <player>` who uploads it. Bank managers need the Companion so that scans and deliveries reach the website.

### Do I have to update anything before playing?

No. The Updater keeps addon and Companion up to date automatically in the background – when Windows starts, continuously for new releases, and right after WoW closes. Just start WoW as usual through Battle.net.

### Why do I have to `/reload` so often?

Addons are not allowed to access the internet in game. WoW only saves addon data on `/reload` or logout and only reads it while loading. `/reload` is the moment Banklord “talks” to the website. Since 1.8 you rarely have to type it: at the important moments a window with **“Transfer now”** appears.

### Why is the stock not live?

An addon can only read the guild bank's contents while someone has it open. The stock is therefore the state of the last scan; the website shows when that was.

### Does Banklord work with WoW Forever?

Yes, since 1.9. The setup finds the `_forever_` folder and installs the addon there too. On the website choose the game version **WoW Forever** when setting up the guild (or under **Settings**). Forever has no realms – that is no problem for Banklord, guilds are then recognised by name. Forever is still new: if you notice anything, please report it via **Help & feedback**.

### Several characters and guilds

Yes. Every character is recognised on login and assigned to its guild. Each guild with its own Banklord website gets its own connection in the Companion.

Different game versions work with one account too, for example one guild in MoP Classic and one in Retail. Every guild has exactly one game version; items and scans are never mixed. When adding the guild in the Companion, choose the Banklord.lua of the matching WoW installation (`_retail_`, `_classic_`, `_anniversary_`, `_forever_`).

**One account for several guilds:** if you already have an account on the website and get an invitation from another guild, redeem it under **More → Account & guilds → “Redeem invitation”**. No second account needed; switch between your guilds at the top. Then create a connection code for the new guild under **Account** and paste it into the Companion under **“More guilds on this PC”**.

**Example MoP Classic, one realm:** characters A and B in the same guild, character C in another guild – that works. On every login Banklord notices which guild the character is in and only shows that guild's stock and orders. An in-game order always goes to the guild of the character you order with.

**Bank manager on one character, member on another?** The role belongs to your website account **per guild**, not to a single character:
- If you are bank manager in guild 1 and only a member in guild 2, your character in guild 1 has the manager tools and your character in guild 2 does not (since 1.8.1).
- Within **the same guild** all your characters have the same role – it is the same person. What a character may take from the guild bank is still decided by the guild rank in game.

### How do I report a bug?

- **In game:** `/banklord feedback` or the **Help & Feedback** tab in the Banklord window. The report is sent through the Companion with the next `/reload`.
- **On the website:** **More → Help & feedback**.
- **On GitHub:** [open an issue](https://github.com/lastrevolution0410/banklord/issues/new/choose).

There you choose **Report a bug**, **Suggest an idea** or **Request a language**. The report goes straight to the Banklord project; under **My reports** you see its status and the answer. Only what you enter and the version numbers are sent – no account or character data.

Please never include passwords, access keys or invitation codes.

### I would like Banklord in my language

Banklord is available in German and English. You can request more languages in game or on the website via **Help & Feedback → Request a language**, or on GitHub as a [language request](https://github.com/lastrevolution0410/banklord/issues/new/choose) – help with translating is very welcome.
