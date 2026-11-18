# Security and privacy

[← Overview](README.en.md) · [Deutsch](sicherheit.md) · **English**

## In short

- Banklord needs **no Blizzard credentials** and reads nothing but its own addon storage.
- Data only goes to **your guild's website** (HTTPS). Update checks go to the official release channel; no personal data is sent.
- Every update is **digitally signed**; unsigned, tampered or older packages are rejected.

## Which data goes where?

| Data | Stored at | Who can see it |
|---|---|---|
| Guild bank stock (scans) | Guild website | All guild members |
| Orders, recipient character | Guild website | The member, bank managers, officers, admins |
| E-mail, display name, password hash | Guild website | Admins (e-mail), nobody (password) |
| Character names and realm | Guild website | Guild members |
| Help & Feedback reports | Banklord project (official site); on the guild website only title and status | Project maintainers. No account, character or guild data; e-mail only if given voluntarily |
| Companion access key | PC: Windows data protection (DPAPI), macOS Keychain, Linux Secret Service. Website: hash only. | Nobody in plain text |

## Unlocking in game

Goal: only people who are **really in the guild** get access – without invitation codes.

1. The Companion creates a **key pair (Ed25519)** on the PC. The private key never leaves the PC and is protected like the access key.
2. Only the **public** key goes into the addon and is sent on the guild addon channel.
3. The addon of a **bank manager, officer or admin** checks the sender against the real guild roster. The sender of an addon message is set by Blizzard's server and cannot be forged.
4. The manager's Companion uploads the confirmation with **their own** access. The website only accepts it from these roles, only for its own guild, and only if it is at most 14 days old.
5. The member's Companion fetches a **one-time random number** (valid for 5 minutes) and signs it together with the **website address**. A signature for another website is worthless there; each random number is valid only once.
6. Only then is there a personal access (order rights, 180 days; for bank managers, officers and admins the website also accepts scans through it – the current role is checked on every upload) and, for new accounts, a **one-time registration link** (7 days) with which the member sets their own e-mail and password.

The Companion never connects on its own: you see the website address and click “Connect”.

## Website

- Passwords with `password_hash` (PHP default, bcrypt), at least 14 characters at registration.
- Protection against cross-site requests (CSRF tokens), strict Content Security Policy, secure cookies.
- Access keys and one-time links are only stored as SHA-256 hashes.
- All important actions end up in the **log**.

## Desktop app

- The local control page of the Companion and the setup is only reachable from this PC and protected by a secret random path and a CSRF token.
- Updates are verified with Ed25519 before installation; downgrades are impossible.
- Files Banklord writes into the addon folder (`ServerState.lua`, `Claim.lua`) are generated from verified data; text from the website is escaped so it is never executed as code in game.

## Found a vulnerability?

Please **not** as a public issue, but as described in [SECURITY.md](../SECURITY.md).
