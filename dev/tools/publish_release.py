"""Builds, signs and publishes a release in one step.

Usage: python3 -I dev/tools/publish_release.py 0.9.2 [--server https://wow-bank.mgc-nom.net] [--dry-run]

Needs ~/.banklord/release-signing.pem (private, offline) and
~/.banklord/publish-token (created on the website: Updates & System).
The server applies the website part itself (with backup) and then announces
the release; background updaters on the PCs pick it up within ~15 s to 2 min."""
import argparse, hashlib, json, os, pathlib, re, subprocess, sys, urllib.parse, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).resolve().parents[2]
HOME = pathlib.Path.home() / '.banklord'
CHUNK = 4 * 1024 * 1024

ap = argparse.ArgumentParser()
ap.add_argument('version'); ap.add_argument('--server', default=os.environ.get('BANKLORD_SERVER','https://wow-bank.mgc-nom.net')); ap.add_argument('--dry-run', action='store_true'); ap.add_argument('--update-host', help='host in the signed download URLs (default: host of --server)'); ap.add_argument('--token-file', default=str(HOME / 'publish-token'))
a = ap.parse_args()
V = a.version
setup_v = re.search(r'const setupVersion = "([^"]+)"', (ROOT / 'app/manage.go').read_text()).group(1)
comp_v = re.search(r'const companionVersion = "([^"]+)"', (ROOT / 'app/companion/main.go').read_text()).group(1)
addon_v = re.search(r'^## Version: (\S+)', (ROOT / 'Banklord/Banklord.toc').read_text(), re.M).group(1)
if setup_v != V:
    sys.exit(f'setupVersion in manage.go ist {setup_v}, erwartet {V}. Bitte angleichen.')

subprocess.run([sys.executable, '-I', str(ROOT / 'dev/tools/build_release.py'), V], check=True)
dist = ROOT / 'dist' / f'Banklord-{V}'
signed = dist / '07_SIGNIERT'
files = {
    'addon': dist / '04_ADDON_MANUELL' / f'Banklord-Addon-{V}.zip',
    'companion': ROOT / 'app/assets/companion.exe',
    'updater': dist / '05_WINDOWS_INSTALLER' / f'Banklord-Setup-{V}-Windows-x64.exe',
    'web': dist / '02_WEB_PAYLOAD' / f'Banklord-Web-Payload-{V}.zip',
}
mounts = ['-v', f'{ROOT}/app:/src', '-v', f'{HOME}:/keys:ro', '-v', f'{dist}:/dist']
inside = lambda p: '/dist/' + str(p.relative_to(dist)) if dist in p.parents else '/src/assets/' + p.name
update_host = a.update_host or urllib.parse.urlsplit(a.server).netloc
subprocess.run(['docker', 'run', '--rm', *mounts, '-e', f'BANKLORD_UPDATE_HOST={update_host}', '-w', '/src', 'golang:1.23', 'go', 'run', './cmd/release-sign', '/keys/release-signing.pem', '/dist/07_SIGNIERT',
                inside(files['addon']), addon_v, inside(files['companion']), comp_v, inside(files['updater']), setup_v, inside(files['web']), V], check=True)
manifest = (signed / 'manifest.json').read_bytes()
print(f'Signiert: Web {V} · Addon {addon_v} · Companion {comp_v} · Updater {setup_v}')
if a.dry_run:
    sys.exit(0)

token = pathlib.Path(a.token_file).read_text().strip()
def call(method, path, body=b'', headers=None):
    req = urllib.request.Request(a.server + '/api/v1' + path, data=body if method == 'POST' else None, method=method,
                                 headers={'Authorization': 'Bearer ' + token, 'Content-Type': 'application/octet-stream', **(headers or {})})
    try:
        with urllib.request.urlopen(req, timeout=300) as r: return json.loads(r.read())
    except urllib.error.HTTPError as e:
        sys.exit(f'Server {e.code} bei {path}: {e.read()[:300].decode(errors="replace")}')
status = call('GET', '/releases/status')
if not status.get('sodium'):
    sys.exit('Der Server hat keine libsodium-Unterstützung (PHP-Erweiterung sodium). Bitte beim Webhoster aktivieren.')
for f in sorted((signed / 'files').iterdir()):
    data = f.read_bytes(); sha, ext = f.stem, f.suffix[1:]
    assert hashlib.sha256(data).hexdigest() == sha
    offset = 0
    while True:
        part = data[offset:offset + CHUNK]; final = offset + len(part) >= len(data)
        r = call('POST', f'/releases/files/{sha}.{ext}', part, {'X-WGB-Offset': str(offset), 'X-WGB-Final': '1' if final else '0'})
        if r.get('complete'): break
        if r.get('error') == 'OFFSET_MISMATCH': offset = r['size']; continue
        offset += len(part)
    print(f'  hochgeladen: {sha[:12]}….{ext} ({len(data)//1024} KiB)')
result = call('POST', '/releases/publish', manifest, {'Content-Type': 'application/json'})
print(json.dumps(result, indent=2, ensure_ascii=False))
