"""Collects user-visible German strings from the web UI for translation.

Usage: python3 -I dev/tools/extract_ui_strings.py > strings.json
Literal strings become exact keys; template literals with ${...} become
patterns where each placeholder is written as {}.
"""
import html, json, pathlib, re, sys

ROOT = pathlib.Path(__file__).resolve().parents[2] / 'backend' / 'public'
GERMAN_WORDS = re.compile(r'[äöüÄÖÜß]|\b(?:und|oder|der|die|das|nicht|noch|nur|mit|für|auf|ist|sind|wird|kein|keine|Bitte|Gilde|Bestellung|Bestand|Mitglied|Warenkorb|Inventur|Konto|Speichern|Abbrechen|Schließen|Löschen|Freigeben|Anmelden|Zurück|Neu|Alle|zuletzt|Fehler)\b')
SKIP = re.compile(r'^(?:[a-z0-9_.#\[\]=:*>~+/-]+|[A-Z0-9_]+|https?://\S+|/api/\S*|[a-z-]+(?: [a-z-]+)*)$')  # code-like
HUMAN = re.compile(r'[A-ZÄÖÜ][a-zäöüß]|[äöüß]')


class _German:
    @staticmethod
    def search(s):
        return HUMAN.search(s) and not SKIP.match(s) and not re.fullmatch(r'[A-Za-z]+(?:[A-Z][a-z]+)+', s)


GERMAN = _German()


def strings_js(text):
    out = set()
    for m in re.finditer(r"'((?:[^'\\\n]|\\.)*)'|\"((?:[^\"\\\n]|\\.)*)\"|`((?:[^`\\]|\\.)*)`", text):
        s = next(g for g in m.groups() if g is not None)
        if m.group(3) is not None:
            if '${' in s:
                s = re.sub(r'\$\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}', '{}', s)
        s = s.replace("\\'", "'").replace('\\"', '"').strip()
        if len(s) < 2 or '<' in s and '>' in s and not GERMAN.search(re.sub(r'<[^>]+>', '', s)):
            continue
        if GERMAN.search(s) and not SKIP.match(s):
            out.add(s)
    return out


def strings_html(text):
    out = set()
    text = re.sub(r'<script.*?</script>|<style.*?</style>', '', text, flags=re.S)
    for t in re.findall(r'>([^<>]+)<', text):
        t = html.unescape(' '.join(t.split()))
        if t and GERMAN.search(t):
            out.add(t)
    for t in re.findall(r'(?:placeholder|title|aria-label|alt)="([^"]+)"', text):
        t = html.unescape(t)
        if GERMAN.search(t):
            out.add(t)
    return out


found = set()
for f in ('app.js',):
    found |= strings_js((ROOT / f).read_text())
for f in ('app.html',):
    found |= strings_html((ROOT / f).read_text())
json.dump(sorted(found), sys.stdout, ensure_ascii=False, indent=0)
