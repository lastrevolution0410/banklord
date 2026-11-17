"""Builds dist/Banklord-<version>/ : web payload + upgrader with digests, addon zip,
companion + Windows installer (via Docker golang:1.23), SHA256SUMS.
Usage: python3 -I dev/tools/build_release.py 0.9.0"""
import hashlib, os, re, shutil, subprocess, sys, zipfile, pathlib
V = sys.argv[1]
ROOT = pathlib.Path(__file__).resolve().parents[2]
DIST = ROOT / 'dist' / f'Banklord-{V}'
FIXED = (2026, 1, 1, 0, 0, 0)
def sha(b): return hashlib.sha256(b).hexdigest()
def zip_files(target, entries):
    target.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as z:
        for entry in entries:
            name, data = entry[0], entry[1]
            mode = entry[2] if len(entry) > 2 else 0o644
            info = zipfile.ZipInfo(name, FIXED); info.compress_type = zipfile.ZIP_DEFLATED; info.external_attr = mode << 16
            z.writestr(info, data)
if DIST.exists(): shutil.rmtree(DIST)
# 1) web payload
vf = ROOT / 'backend' / 'src' / 'Version.php'
vf.write_text(re.sub(r"WEB = '[^']*'", f"WEB = '{V}'", vf.read_text()))
files = sorted(p for d in ('bin', 'public', 'sql', 'src') for p in (ROOT / 'backend' / d).iterdir()
               if p.is_file() and not p.name.startswith('.'))
entries = [(f'backend/{p.parent.name}/{p.name}', p.read_bytes()) for p in files]
for n, b in entries: assert 0 < len(b) <= 350000, (n, len(b))
payload = DIST / '02_WEB_PAYLOAD' / f'Banklord-Web-Payload-{V}.zip'
zip_files(payload, entries)
# 2) upgrader with matching digests
up = (ROOT / 'upgrade-web.php').read_text()
up = re.sub(r"const BANKLORD_VERSION='[^']*';", f"const BANKLORD_VERSION='{V}';", up)
up = re.sub(r"const BANKLORD_PAYLOAD_SHA256 = '[0-9a-f]*';", f"const BANKLORD_PAYLOAD_SHA256 = '{sha(payload.read_bytes())}';", up)
digests = 'const BANKLORD_FILE_DIGESTS = [\n' + ''.join(f"    '{n}' => '{sha(b)}',\n" for n, b in entries) + '];'
up, n = re.subn(r'const BANKLORD_FILE_DIGESTS = \[.*?\n\];', lambda m: digests, up, flags=re.S); assert n == 1
(ROOT / 'upgrade-web.php').write_text(up)
(DIST / '01_PUBLIC_TEMP').mkdir(parents=True); shutil.copy(ROOT / 'upgrade-web.php', DIST / '01_PUBLIC_TEMP' / 'upgrade-web.php')
shutil.copy(ROOT / 'deploy' / 'htaccess-waehrend-upgrade', DIST / '01_PUBLIC_TEMP' / '.htaccess')
(DIST / '03_HTACCESS_NACH_UPGRADE').mkdir(); shutil.copy(ROOT / 'deploy' / 'htaccess-nach-upgrade', DIST / '03_HTACCESS_NACH_UPGRADE' / '.htaccess')
for doc in ('00_START_HIER.md', '00_QA.md'):
    if (ROOT / 'deploy' / 'private' / doc).exists(): shutil.copy(ROOT / 'deploy' / 'private' / doc, DIST / doc)  # guild-specific notes, not in the public repo
# 3) addon
addon_dir = ROOT / 'Banklord'
addon_entries = [(f'Banklord/{p.name}', p.read_bytes()) for p in sorted(addon_dir.iterdir()) if p.is_file() and not p.name.startswith('.')]
addon_zip = DIST / '04_ADDON_MANUELL' / f'Banklord-Addon-{V}.zip'
zip_files(addon_zip, addon_entries)
shutil.copytree(addon_dir, DIST / '04_ADDON_MANUELL' / 'Banklord', ignore=shutil.ignore_patterns('.*'))
assets = ROOT / 'app' / 'assets'; assets.mkdir(exist_ok=True)
shutil.copy(addon_zip, assets / 'addon.zip')
# 4) companion + installer
script = ('set -e; cd /src && GOOS=windows GOARCH=amd64 go build -trimpath -ldflags "-s -w -H windowsgui" -o assets/companion.exe ./cmd/companion '
          '&& cd /src && go test ./... >/dev/null && GOOS=windows GOARCH=amd64 go build -trimpath -ldflags "-s -w" -o /out/setup.exe . '
          '&& for t in darwin/arm64 darwin/amd64 linux/amd64 linux/arm64; do GOOS=${t%/*} GOARCH=${t#*/} go build -trimpath -ldflags "-s -w" -o /out/banklord-${t%/*}-${t#*/} . ; done')
out = DIST / '05_WINDOWS_INSTALLER'; out.mkdir(parents=True)
subprocess.run(['docker', 'run', '--rm', '-v', f'{ROOT/"app"}:/src', '-v', f'{out}:/out', 'golang:1.23', 'sh', '-c', script], check=True)
(out / 'setup.exe').rename(out / f'Banklord-Setup-{V}-Windows-x64.exe')
# 4b) macOS app bundles and Linux packages (unsigned; see the included notes)
PLIST = '''<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
<key>CFBundleName</key><string>Banklord</string><key>CFBundleDisplayName</key><string>Banklord</string>
<key>CFBundleIdentifier</key><string>net.banklord.app</string><key>CFBundleExecutable</key><string>Banklord</string>
<key>CFBundlePackageType</key><string>APPL</string><key>CFBundleVersion</key><string>{v}</string>
<key>CFBundleShortVersionString</key><string>{v}</string><key>LSMinimumSystemVersion</key><string>11.0</string>
</dict></plist>
'''
MAC_NOTE = '''Banklord {v} for macOS / für macOS

EN: Unzip, move Banklord.app to Applications. The app is not signed by Apple yet:
the first time, right-click Banklord.app and choose "Open", then "Open" again.
It opens the setup in your browser (install addon, connect to your guild website).

DE: Entpacken und Banklord.app in den Ordner „Programme“ ziehen. Die App ist noch nicht
von Apple signiert: Beim ersten Mal Rechtsklick auf Banklord.app → „Öffnen“ → „Öffnen“.
Danach öffnet sich die Einrichtung im Browser (Addon installieren, mit der Gilden-Website verbinden).
'''
LINUX_NOTE = '''Banklord {v} for Linux / für Linux

EN: Unzip and run ./banklord in a terminal. It opens the setup in your browser and finds
WoW in Wine, Lutris, Steam (Proton) and Bottles prefixes. Autostart uses ~/.config/autostart.
DE: Entpacken und ./banklord im Terminal starten. Die Einrichtung öffnet sich im Browser und findet
WoW in Wine-, Lutris-, Steam- (Proton) und Bottles-Umgebungen. Autostart über ~/.config/autostart.
'''
apps = {}
for goos, arch, label in [('darwin', 'arm64', 'macOS-AppleSilicon'), ('darwin', 'amd64', 'macOS-Intel'), ('linux', 'amd64', 'Linux-x64'), ('linux', 'arm64', 'Linux-ARM64')]:
    binary = out / f'banklord-{goos}-{arch}'
    data = binary.read_bytes(); binary.unlink()
    if goos == 'darwin':
        entries = [('Banklord.app/Contents/Info.plist', PLIST.format(v=V).encode()), ('Banklord.app/Contents/MacOS/Banklord', data, 0o755),
                   ('LIESMICH - README.txt', MAC_NOTE.format(v=V).encode())]
    else:
        entries = [('banklord', data, 0o755), ('LIESMICH - README.txt', LINUX_NOTE.format(v=V).encode())]
    target = DIST / '08_MAC_LINUX' / f'Banklord-{V}-{label}.zip'
    zip_files(target, entries)
    apps[f'{goos}-{arch}'] = target
# 5) source snapshot + checksums
src = DIST / '06_QUELLCODE' / f'Banklord-{V}-Quellcode.zip'
tracked = subprocess.run(['git', '-C', str(ROOT), 'ls-files'], capture_output=True, text=True, check=True).stdout.split()
zip_files(src, [(t, (ROOT / t).read_bytes()) for t in tracked if (ROOT / t).is_file()])
sums = [f'{sha(p.read_bytes())}  {p.relative_to(DIST)}' for p in sorted(DIST.rglob('*')) if p.is_file() and p.suffix in ('.zip', '.exe', '.php')]
(DIST / 'SHA256SUMS.txt').write_text('\n'.join(sums) + '\n')
print('built', DIST)
