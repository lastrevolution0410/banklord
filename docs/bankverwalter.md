# Anleitung für Bankverwalter

[← Übersicht](README.md) · **Deutsch** · [English](bankverwalter.en.md)

Diese Anleitung gilt für alle mit der Rolle **Bankverwalter**, **Offizier** oder **Admin**. Einrichten wie in der [Anleitung für Mitglieder](mitglieder.md#einmal-einrichten); die Rolle vergibt ein Admin auf der Website unter **Mitglieder**.

## Inhalt

1. [Neue Bestellungen bemerken](#neue-bestellungen-bemerken)
2. [An der Gildenbank: entnehmen](#an-der-gildenbank-entnehmen)
3. [Am Briefkasten: versenden](#am-briefkasten-versenden)
4. [Mehrere Bankverwalter](#mehrere-bankverwalter)
5. [Neue Mitglieder bestätigen](#neue-mitglieder-bestätigen)
6. [Auf der Website](#auf-der-website)
7. [Zusammen mit anderen Addons](#zusammen-mit-anderen-addons)

---

## Neue Bestellungen bemerken

- **Live:** Bestellt ein Mitglied im Spiel, während du online bist, erscheint sofort ein Hinweis in der Bildschirmmitte mit Ton.
- **Beim Einloggen:** Bestellungen, die seit deinem letzten Besuch eingegangen sind (auch von der Website), werden nach dem Einloggen angesagt.
- **Liste:** `/banklord bestellungen` zeigt alle offenen Bestellungen.

> Neue Bestellungen von der Website erscheinen im Spiel nach dem nächsten `/reload`, weil WoW Addon-Daten nur beim Laden einliest.

## An der Gildenbank: entnehmen

1. Öffne die Gildenbank. Neben dem Fenster erscheint **„Banklord – offene Bestellungen entnehmen“**.
2. **„Entnehmen“** bei einer Bestellung oder **„Alle entnehmen“**.
3. Banklord sucht die passenden Stapel in allen Fächern, teilt Stapel bei Bedarf und legt genau die bestellte Menge in deine Taschen.
4. Fehlt etwas, steht im Chat, was und wie viel. Diese Bestellung wird wieder freigegeben.

Das Fenster lässt sich verschieben und merkt sich seine Position.

> **Bestand aktualisieren:** Beim Öffnen der Gildenbank scannt Banklord automatisch alle Fächer. Nach `/reload` oder Logout lädt der Companion den Scan hoch. Hat ein Admin unter **System** den automatischen Abgleich eingeschaltet, übernimmt die Website den Scan jedes Bankverwalters, Offiziers und Admins sofort; sonst wartet er unter **Inventur** auf Bestätigung.

## Am Briefkasten: versenden

1. Öffne einen Briefkasten. Es erscheint **„Banklord – Bestellungen versenden“**.
2. **„Versenden“** ist nur aktiv, wenn du alle Gegenstände der Bestellung in den Taschen hast.
3. Banklord füllt **Empfänger**, **Betreff** (`Banklord #<Nummer>`) und die **Anhänge** aus. Du klickst nur noch auf **„Senden“**.
4. Mit **„Alle versenden“** wird nach jedem Brief automatisch der nächste vorbereitet.
5. Nach dem Senden gilt die Bestellung als ausgegeben. Die Website wird beim nächsten `/reload` aktualisiert; das Mitglied wird benachrichtigt.

Nur Briefe, die Banklord selbst vorbereitet hat, zählen als Ausgabe. Änderst du den Betreff, wird nichts gebucht.

**Taschen voll?** Banklord stoppt, sagt welche Bestellung erst teilweise entnommen ist, und nimmt beim nächsten „Entnehmen“ nur noch den Rest.

**Ablehnen:** Im Banklord-Fenster unter **Aufträge** neben jeder Bestellung „Ablehnen“ (mit Rückfrage) oder `/banklord ablehnen <Nr>`. Andere Verwalter sehen die Bestellung sofort als abgelehnt; die Website und das Mitglied erfahren es nach deinem nächsten `/reload`.

---

## Mehrere Bankverwalter

Mehrere Verwalter können gleichzeitig arbeiten. **Eine Bestellung wird nie doppelt entnommen oder verschickt**, weil sich die Addons im Gilden-Addon-Kanal absprechen:

1. **Reservieren:** Bevor entnommen oder ein Brief vorbereitet wird, kündigt dein Addon die Bestellung als „reserviert“ an und wartet 3 Sekunden auf Einspruch.
2. **Gleichzeitiger Zugriff:** Alle Addons entscheiden nach derselben Regel. Eine bestehende Reservierung gewinnt immer, sonst die frühere Uhrzeit (Serverzeit), bei Gleichstand der alphabetisch erste Name. Der andere sieht **„in Arbeit bei <Name>“** und einen gesperrten Knopf.
3. **Verloren?** Hattest du schon einen Brief vorbereitet, wird er geleert, und du wirst gebeten, die Gegenstände zurückzulegen.
4. **Nach dem Versand** meldet dein Addon „versendet“. Jedes Banklord in der Gilde merkt sich das 30 Tage und erzählt es Verwaltern, die sich später einloggen. Wer vorher offline war, kann die Bestellung deshalb nicht noch einmal schicken.
5. **Freigabe:** Fehlen Gegenstände, wird die Reservierung sofort freigegeben. Eine Reservierung ohne Versand verfällt nach 6 Stunden.

**Was du beachten solltest:**

- Die Absprache braucht, dass die Addons sich hören. War beim Versand niemand mit Banklord online, schützt zusätzlich die Website: Nach dem nächsten Hochladen und `/reload` verschwindet die Bestellung aus allen Listen.
- Wer Gegenstände **von Hand** ohne die Banklord-Knöpfe verschickt, umgeht die Absprache. Dann die Bestellung auf der Website als ausgegeben markieren.

## Neue Mitglieder bestätigen

Du musst nichts tun. Wenn du online bist und ein Mitglied mit frisch installiertem Banklord einloggt:

1. Dein Addon prüft den Namen gegen die **echte Gildenliste** (der Absender einer Addon-Nachricht wird von Blizzards Server gesetzt und kann nicht gefälscht werden).
2. Es antwortet dem Mitglied mit der Website-Adresse deiner Gilde und merkt sich die Bestätigung.
3. Nach deinem nächsten `/reload` lädt dein Companion die Bestätigung hoch. Die Website nimmt sie nur von Bankverwaltern, Offizieren und Admins an.
4. Das Mitglied klickt im Companion auf „Verbinden“ und ist freigeschaltet.

Mehr zum Ablauf und warum er sicher ist: [Sicherheit](sicherheit.md#freischaltung-im-spiel).

## Auf der Website

| Bereich | Wofür |
|---|---|
| **Aufträge** | Alle Bestellungen prüfen, reservieren, ausgeben oder ablehnen |
| **Inventur** | Letzte Scans der Gildenbank, Abgleich mit dem Bestand |
| **Nachkauf** | Was fehlt oder knapp ist |
| **Mitglieder** | Rollen vergeben, Einladungen erstellen, Zugänge sperren (Admins) |
| **Protokoll** | Wer hat wann was getan |
| **System** | Version, Updates, verbundene Geräte (Admins) |

## Zusammen mit anderen Addons

Banklord nutzt die Spiel-API direkt und nicht die Blizzard-Fenster. Deshalb funktioniert es auch mit Addons, die die Gildenbank als ein großes Fach anzeigen, oder mit Postfach-Addons. Erscheint das Banklord-Fenster an einer ungünstigen Stelle, einfach verschieben.
