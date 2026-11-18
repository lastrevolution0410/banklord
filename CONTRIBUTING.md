# Mitmachen / Contributing

Danke fürs Mithelfen! Issues und Pull Requests sind auf Deutsch oder Englisch willkommen.
Thanks for helping! Issues and pull requests are welcome in German or English.

| Ich möchte … / I want to … | So geht's / How |
|---|---|
| einen Fehler melden / report a bug | [Fehler melden](https://github.com/lastrevolution0410/banklord/issues/new?template=1-fehler.yml) |
| eine Funktion vorschlagen / suggest a feature | [Funktionswunsch](https://github.com/lastrevolution0410/banklord/issues/new?template=2-wunsch.yml) |
| eine Sprache anfragen oder übersetzen / request or translate a language | [Sprache anfragen](https://github.com/lastrevolution0410/banklord/issues/new?template=3-sprache.yml) |
| eine Sicherheitslücke melden / report a vulnerability | vertraulich, siehe [SECURITY.md](SECURITY.md) |
| Code beitragen / contribute code | siehe unten / see below |

## Code

- Aufbau, lokale Umgebung und Tests: [docs/entwicklung.md](docs/entwicklung.md).
- Vor jedem Pull Request `sh dev/run-tests.sh` ausführen. Änderungen klein und fokussiert halten; jeder Fehler-Fix bekommt einen Test.
- **Übersetzungen:** Deutsch ist der Quelltext. Englische Texte stehen in `Banklord/Locale.lua` und `backend/public/locales.js`; eine neue Sprache bekommt dort einen eigenen Block.
- **Grafiken:** nur eigene Werke oder kompatibel lizenzierte. Keine Dateien aus dem Spiel.
- **Niemals** Passwörter, Zugangsschlüssel, Einladungscodes oder private Signaturschlüssel committen.

## Lizenz

Banklord steht unter CC BY-NC-ND 4.0 mit zusätzlichen Erlaubnissen. Mit einem Beitrag räumst du die in Abschnitt 4 von [LICENSE-ADDITIONAL-PERMISSIONS.md](LICENSE-ADDITIONAL-PERMISSIONS.md) beschriebenen Rechte ein.
