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

No. The Updater keeps addon and Companion up to date automatically in the background – when Windows starts, continuously for new releases, and right after WoW closes. Just start WoW as usual through Battle.net. The former “Banklord – sicher starten” shortcut is therefore gone and is removed with the update.

### Why do I have to `/reload` so often?

Addons are not allowed to access the internet in game. WoW only saves addon data on `/reload` or logout and only reads it while loading. `/reload` is the moment Banklord “talks” to the website.

### Why is the stock not live?

An addon can only read the guild bank's contents while someone has it open. The stock is therefore the state of the last scan; the website shows when that was.

### Several characters and guilds

Yes. Every character is recognised on login and assigned to its guild. Each guild with its own Banklord website gets its own connection in the Companion.

### How do I report a bug?

- **In game:** `/banklord feedback` or the **Help & Feedback** tab in the Banklord window. The report is sent through the Companion with the next `/reload`.
- **On the website:** **Help & Feedback** tab.
- **On GitHub:** [open an issue](https://github.com/lastrevolution0410/banklord/issues/new/choose).

There you choose **Report a bug**, **Suggest an idea** or **Request a language**. The report goes straight to the Banklord project; under **My reports** you see its status and the answer. Only what you enter and the version numbers are sent – no account or character data.

Please never include passwords, access keys or invitation codes.

### I would like Banklord in my language

Banklord is available in German and English. You can request more languages in game or on the website via **Help & Feedback → Request a language**, or on GitHub as a [language request](https://github.com/lastrevolution0410/banklord/issues/new/choose) – help with translating is very welcome.
