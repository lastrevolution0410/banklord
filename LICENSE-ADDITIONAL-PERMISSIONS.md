# Additional permissions for Banklord

Banklord is licensed under the Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 International license ([LICENSE](LICENSE), CC BY-NC-ND 4.0).

As the licensor, Björn Thiel Ebert grants the following additional permissions on top of that license. They are a separate arrangement in the sense of Section 7(b) of the license. Where they go further than the license, these permissions apply. Everything not allowed here or in the license stays reserved.

*Eine deutsche Zusammenfassung steht am Ende. Maßgeblich ist der englische Text.*

## 1. Self-hosting

You may install and run Banklord for your own guild, community or group of players, on your own computer or on rented web space. This includes the website, the in-game addon and the desktop app. All members of that guild or community may use it.

This is non-commercial use as long as nobody is charged for access to or use of Banklord. Sharing the actual hosting costs among members is allowed. Running Banklord as a paid service for others is not.

## 2. Configuration is not a modification

You may change anything Banklord is designed to have configured, and the result still counts as unmodified Banklord. This covers:

- the website's configuration file (`backend/private/config.local.php`), including database access, HTTPS and `UPDATE_HOST`,
- the web server settings, such as `.htaccess`,
- all settings in the website, addon and desktop app,
- the update server address used when building the desktop app (`-ldflags "-X main.updateHost=…"`).

## 3. Your own update channel

So that you can run your own signed update channel, you may:

1. create your own release signing key,
2. replace the public release key in `app/update.go` and `backend/src/ReleasePublisher.php` with your own,
3. build and sign releases of Banklord with that key.

You may distribute these builds only to members of the guild or community from Section 1, and only free of charge. You must mark them as unofficial builds and keep all copyright and license notices unchanged. You may not make any other changes to the code.

## 4. Fixes and contributions

You may change Banklord for your own private use. You may also prepare changes in order to send them to the project, for example as a GitHub pull request or as a patch in an issue. You may publish these proposed changes only in a fork on the same platform, for the purpose of that submission.

By submitting a contribution, you grant Björn Thiel Ebert a perpetual, worldwide, irrevocable, royalty-free right to use, modify, relicense and distribute it as part of Banklord. You also confirm that you have the right to grant this.

## 5. Translations

You may translate Banklord's user interface and documentation and submit the translations under Section 4. Published translations become part of the project.

## 6. Attribution

Keep the name "Banklord", the copyright notice "© Björn Thiel Ebert" and a link to the project or its license wherever Banklord shows them. In particular, do not remove the notices from the website footer, the addon's `.toc` file or `LICENSE`.

---

## Deutsche Zusammenfassung (nicht verbindlich)

- **Selbst-Hosting erlaubt:** Jede Gilde oder Spielgemeinschaft darf Banklord kostenlos für ihre Mitglieder betreiben, auch auf gemietetem Webspace. Hosting-Kosten unter den Mitgliedern zu teilen ist in Ordnung.
- **Nicht erlaubt:** Banklord als bezahlten Dienst anbieten oder verkaufen.
- **Konfiguration ist keine Veränderung:** Konfigurationsdatei, `.htaccess`, alle Einstellungen in Website, Addon und App sowie die Update-Adresse beim Bauen der App dürfen angepasst werden.
- **Eigener Update-Kanal erlaubt:**
  - Man darf einen eigenen Signaturschlüssel erzeugen, den öffentlichen Schlüssel im Code austauschen und eigene Builds signieren.
  - Diese Builds dürfen nur kostenlos an die eigenen Mitglieder gehen und müssen als inoffiziell gekennzeichnet sein.
  - Andere Änderungen am Code sind nicht erlaubt.
- **Privat ändern ist erlaubt.** Verbesserungen und Übersetzungen dürfen als Pull Request ans Projekt geschickt werden. Die Rechte daran gehen an den Projektinhaber.
- **Namensnennung:** Name, Copyright-Hinweis und Lizenz-Hinweise müssen sichtbar bleiben.
