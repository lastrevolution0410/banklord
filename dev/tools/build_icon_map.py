"""Builds backend/src/icon-filedata.tsv.gz (FileDataID<TAB>iconname) from a wowdev listfile.

Usage: curl -sL <verified-listfile.csv> | grep -i ';interface/icons/[^/]*\\.blp$' > icons.csv
       python3 -I dev/tools/build_icon_map.py icons.csv backend/src/icon-filedata.tsv.gz
Must stay below 350 KB (limit of upgrade-web.php)."""
import re, sys, gzip
src, dst = sys.argv[1], sys.argv[2]
ok = re.compile(r'^[a-z0-9][a-z0-9_-]{0,79}$')
rows, skipped = {}, 0
for line in open(src, encoding='utf-8', errors='replace'):
    fid, _, path = line.strip().partition(';')
    name = path.lower().removeprefix('interface/icons/').removesuffix('.blp')
    if fid.isdigit() and ok.match(name): rows[int(fid)] = name
    else: skipped += 1
data = gzip.compress(''.join(f"{k}\t{rows[k]}\n" for k in sorted(rows)).encode(), 9, mtime=0)
assert len(data) < 350000, len(data)
open(dst, 'wb').write(data)
print(len(rows), 'icons,', skipped, 'skipped,', len(data), 'bytes')
