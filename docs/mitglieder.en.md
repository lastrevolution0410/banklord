# Guide for members

[← Overview](README.en.md) · [Deutsch](mitglieder.md) · **English**

## Contents

1. [Set up once](#set-up-once)
2. [Ordering in game](#ordering-in-game)
3. [Ordering on the website](#ordering-on-the-website)
4. [What happens after ordering?](#what-happens-after-ordering)
5. [Help & Feedback](#help--feedback)
6. [In-game commands](#in-game-commands)

---

## Set up once

### 1. Install

1. Open your guild's Banklord website and click **“Download Windows setup”** at the bottom.
2. Run the file. The first time, Windows warns about an unknown app: **“More info” → “Run anyway”** ([why?](installation.en.md#windows-warns-about-an-unknown-app)).
3. All WoW versions found are already ticked in the setup. Click **“Install / Update”**.
4. The Companion then opens in your browser. You can leave the window open or close it – the Companion keeps running.

### 2. Unlock – automatically in game

1. Start WoW and log in with a character in your guild.
2. Type `/reload` once. From now on your addon announces itself to your guild's bank managers.
3. As soon as a **bank manager, officer or admin is online**, their addon checks that you really are in the guild and confirms you.
4. The Companion opens with **“Unlock in game”**. Check that the website address shown is your guild's and click **“Connect”**.
5. If this is your first access, the website opens: choose an **e-mail, display name and password** (at least 14 characters). The link is valid for 7 days and only once.

> **Does it say “not confirmed yet”?** The manager has to do one `/reload` so that their confirmation reaches the website. Your Companion keeps retrying on its own.

### Fallback: invitation

If no manager is online right now, an admin can create an invitation for you on the website under **Members**. Use it to register on the website, then connect the Companion under **Account**.

### Several characters or guilds

- **Several characters:** Just log in with each one once. Banklord remembers the characters and assigns them to the right guild.
- **Several guilds** with their own Banklord website: each guild is unlocked separately. In the Companion they are listed under **“Other guilds on this PC”**.

---

## Ordering in game

1. Open Banklord with `/banklord` or the minimap button.
2. Search for the item, enter the quantity and put it in the **cart**.
3. In the cart, click **“Order”**.
4. Type `/reload` or log out. The Companion uploads the order; bank managers who are online get a notice right away.

The stock shown in game is the state of the last guild bank scan. Items marked “reserved” have already been ordered by someone else.

## Ordering on the website

1. Sign in. Under **Guild bank** you see the stock with search and filters.
2. Put items in the **cart** or on the **wish list**.
3. In the cart, choose the **recipient character** – the bank manager mails the items to this character. Only characters you have logged in with Banklord at least once are listed.
4. In a hurry, e.g. for the next raid? Tick **“Urgent”**. The bank managers see it right away.
5. **“Order”**.

## What happens after ordering?

| Status | Meaning |
|---|---|
| **Submitted** | The order has arrived and is waiting for a bank manager. |
| **Reserved** / **Partial** | The manager is holding the items (or some of them) for you. |
| **Issued** | The mail is on its way. Check your mailbox. |
| **Rejected** / **Cancelled** | The order will not be delivered. The reason is shown under **My orders**. |

You see news on the website under **Notifications** and in game after the next `/reload`. All times are shown in your local time.


- **Instant notice:** As soon as a bank manager sends your mail, Banklord says “Your order #… is on its way” in game – without `/reload`, if the recipient character is online.
- **Partial delivery:** If something is missing in the guild bank, the manager can send part of it already. The rest stays open and follows later.
- **Cancel:** In the Banklord window under **Cart → My orders** right-click the order, use `/banklord stornieren <no>` or the website. Items already sent stay with you.
- **Weekly limit:** If your guild set a weekly limit, the cart shows it. Above it the order is still accepted and an officer approves it.
- **Old orders:** The guild can have open orders cancelled automatically after a set number of days.
- **View only:** Items from locked guild bank tabs are visible but cannot be ordered.
- **Tooltip:** Hovering an item shows “Guild bank: 20” (from the last scan). On/off: `/banklord tooltip`.

---

## Help & Feedback

Something is stuck, you have an idea, or you would like Banklord in your language?

| Where | How |
|---|---|
| **In game** | `/banklord feedback` or the **Help & Feedback** tab in the Banklord window. Sent with the next `/reload`. |
| **Website** | **Help & Feedback** tab. |

1. Choose **Report a bug**, **Suggest an idea** or **Request a language**.
2. Fill in the short form and click **Send**.
3. Under **My reports** you see whether your report was received, planned or done – and the answer.

The report goes straight to the Banklord project. Only what you enter and the version numbers are sent, no account or character data. An e-mail address for follow-up questions is optional.

---

## In-game commands

All commands start with `/banklord`. The commands are German words; the table lists what they do:

| Command | Effect |
|---|---|
| `/banklord` | Open or close the Banklord window |
| `liste`, `suche <name>` | List or search the stock in chat |
| `merken <item ID> <quantity>` | Put an item in the cart |
| `korb` | Show the cart |
| `bestellen` | Order the cart |
| `leeren` | Empty the cart |
| `senden <player>` | Hand your cart to a guildmate who uploads it for you (if you have no Companion) |
| `eingang`, `ausgang`, `erneut` | View handed-over carts or send them again |
| `status` | State of the addon and the last scan |
| `stornieren <no>` | Cancel your own open order |
| `bestellungen` | Open orders (bank managers only) |
| `einkauf` | Shopping list for Auctionator/TSM (bank managers only) |
| `tooltip` | Guild bank count in tooltips on/off |
| `feedback` (or `help`) | Help & Feedback: report a bug, suggest an idea, request a language |
| `minimap` | Show or hide the minimap button |
| `lang de`, `lang en`, `lang auto` | Change the language |
