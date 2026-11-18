# Guide for bank managers

[← Overview](README.en.md) · [Deutsch](bankverwalter.md) · **English**

This guide applies to everyone with the role **bank manager**, **officer** or **admin**. Set up as in the [guide for members](mitglieder.en.md#set-up-once); an admin assigns the role on the website under **Members**.

## Contents

1. [Noticing new orders](#noticing-new-orders)
2. [At the guild bank: withdraw](#at-the-guild-bank-withdraw)
3. [At the mailbox: send](#at-the-mailbox-send)
4. [Several bank managers](#several-bank-managers)
5. [Confirming new members](#confirming-new-members)
6. [On the website](#on-the-website)
7. [Together with other addons](#together-with-other-addons)

---

## Noticing new orders

- **Live:** If a member orders in game while you are online, you get a chat message with a sound and the minimap button blinks with the number of open orders.
- **On login:** Orders received since your last visit (including those from the website) are announced after you log in.
- **List:** `/banklord bestellungen` shows all open orders.

> New orders from the website appear in game after the next `/reload`, because WoW only reads addon data while loading.

## At the guild bank: withdraw

1. Open the guild bank. **“Banklord – withdraw open orders”** appears next to the window.
2. **“Withdraw”** for one order or **“Withdraw all”**.
3. Banklord finds the matching stacks in all tabs, splits stacks if needed and puts exactly the ordered quantity into your bags.
4. If something is missing, chat tells you what and how much. That order is released again.

The window can be moved and remembers its position.

> **Updating the stock:** When you open the guild bank, Banklord scans all tabs automatically. After `/reload` or logout the Companion uploads the scan. If an admin has turned on automatic reconciliation under **System**, the website takes over the scan of every bank manager, officer and admin right away; otherwise it waits under **Inventory** for confirmation.

## At the mailbox: send

1. Open a mailbox. **“Banklord – send orders”** appears.
2. **“Send”** is only active when you have all items of the order in your bags.
3. Banklord fills in the **recipient**, **subject** (`Banklord #<number>`) and **attachments**. You only click **“Send”**.
4. With **“Send all”** the next mail is prepared automatically after each one.
5. After sending, the order counts as issued. The website is updated with the next `/reload`; the member is notified.

Only mails Banklord prepared itself count as issued. If you change the subject, nothing is booked.

**Bags full?** Banklord stops, tells you which order is only partly withdrawn, and the next “Withdraw” takes only the rest.

**Partial delivery:** If the stock is not enough, Banklord offers “Take part”. At the mailbox “Send part” mails what is in your bags. Exactly that amount is booked; the rest stays open for every manager.

**Member notice:** After sending, the recipient gets a message in game right away if they are online.

**Weekly limit:** Orders above the limit show “waiting for an officer”. Officers and admins see “Approve” instead (in game or on the website under **Orders**). An admin sets the rules on the website under **Members → Order rules**: weekly limits, automatic cancelling of old orders and view-only tabs.

**Shopping list:** **Orders → Shopping list** (or `/banklord einkauf`) shows what fell below the minimum stock, ready to copy for Auctionator and TSM. Set minimum and target stock on the website under **Restock**; the same lists can be copied there.

**If a member cancels** their order in game, it is locked for you right away.

**Reject:** In the Banklord window under **Orders**, “Reject” next to each order (with confirmation), or `/banklord ablehnen <no>`. Other managers see the order as rejected right away; the website and the member learn about it after your next `/reload`.

---

## Several bank managers

Several managers can work at the same time. **An order is never withdrawn or sent twice**, because the addons coordinate on the guild addon channel:

1. **Reserve:** Before withdrawing or preparing a mail, your addon announces the order as “reserved” and waits 3 seconds for objections.
2. **Simultaneous access:** All addons decide by the same rule. An existing reservation always wins, otherwise the earlier time (server time), and on a tie the alphabetically first name. The other manager sees **“in progress by <name>”** and a locked button.
3. **Lost?** If you had already prepared a mail, it is cleared and you are asked to put the items back.
4. **After sending** your addon reports “sent”. Every Banklord in the guild remembers this for 30 days and tells managers who log in later. Someone who was offline therefore cannot send the order again.
5. **Release:** If items are missing, the reservation is released immediately. A reservation without sending expires after 6 hours.

**What to keep in mind:**

- Coordination requires the addons to hear each other. If nobody with Banklord was online during sending, the website protects as well: after the next upload and `/reload` the order disappears from all lists.
- Sending items **by hand** without the Banklord buttons bypasses the coordination. In that case mark the order as issued on the website.

## Confirming new members

You do not need to do anything. When you are online and a member with a freshly installed Banklord logs in:

1. Your addon checks the name against the **real guild roster** (the sender of an addon message is set by Blizzard's server and cannot be forged).
2. It answers the member with your guild's website address and remembers the confirmation.
3. After your next `/reload` your Companion uploads the confirmation. The website only accepts it from bank managers, officers and admins.
4. The member clicks “Connect” in the Companion and is unlocked.

More about the process and why it is safe: [Security](sicherheit.en.md#unlocking-in-game).

## On the website

| Area | Purpose |
|---|---|
| **Orders** | Review all orders, reserve, issue or reject them |
| **Inventory** | Latest guild bank scans, comparison with the stock |
| **Restock** | What is missing or running low |
| **Members** | Assign roles, create invitations, block access (admins) |
| **Log** | Who did what and when |
| **System** | Version, updates, connected devices (admins) |

## Together with other addons

Banklord uses the game API directly instead of the Blizzard windows. It therefore also works with addons that show the guild bank as one big tab, or with mailbox addons. If the Banklord window appears in an awkward place, just move it.
