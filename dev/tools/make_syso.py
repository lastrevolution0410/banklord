#!/usr/bin/env python3
"""Turn an .ico into a Windows resource object (.syso) that `go build` links
into the .exe, so Explorer, shortcuts and the taskbar show the icon.

Usage: python3 -I dev/tools/make_syso.py app/winres/banklord.ico app/rsrc_windows_amd64.syso
Writes a COFF object with one .rsrc section (RT_ICON + RT_GROUP_ICON).
"""
import struct
import sys

RT_ICON, RT_GROUP_ICON, LANG = 3, 14, 0x0409


def read_ico(path):
    data = open(path, 'rb').read()
    _, kind, count = struct.unpack_from('<HHH', data, 0)
    if kind != 1 or count < 1:
        raise SystemExit('not an icon file')
    images = []
    for i in range(count):
        w, h, colors, _, planes, bpp, size, offset = struct.unpack_from('<BBBBHHII', data, 6 + 16 * i)
        images.append(((w, h, colors, planes, bpp, size), data[offset:offset + size]))
    return images


def build(images):
    group = struct.pack('<HHH', 0, 1, len(images))
    for n, (meta, _) in enumerate(images, 1):
        w, h, colors, planes, bpp, size = meta
        group += struct.pack('<BBBBHHIH', w, h, colors, 0, planes, bpp, size, n)
    leaves = [(RT_ICON, n, blob) for n, (_, blob) in enumerate(images, 1)] + [(RT_GROUP_ICON, 1, group)]
    types = sorted({t for t, _, _ in leaves})

    def directory(n):
        return 16 + 8 * n

    # Layout: root, type dirs, name dirs, language dirs, data entries, blobs.
    off = directory(len(types))
    type_dir = {}
    for t in types:
        type_dir[t] = off
        off += directory(sum(1 for x in leaves if x[0] == t))
    name_dir = {}
    for leaf in leaves:
        name_dir[leaf[:2]] = off
        off += directory(1)
    entry = {}
    for leaf in leaves:
        entry[leaf[:2]] = off
        off += 16
    blob_at = {}
    for leaf in leaves:
        off = (off + 7) & ~7
        blob_at[leaf[:2]] = off
        off += len(leaf[2])
    raw = bytearray(off)
    relocs = []

    def put_dir(at, items):
        struct.pack_into('<IIHHHH', raw, at, 0, 0, 0, 0, 0, len(items))
        for i, (ident, target, subdir) in enumerate(items):
            struct.pack_into('<II', raw, at + 16 + 8 * i, ident, target | (0x80000000 if subdir else 0))

    put_dir(0, [(t, type_dir[t], True) for t in types])
    for t in types:
        put_dir(type_dir[t], [(n, name_dir[(t, n)], True) for tt, n, _ in leaves if tt == t])
    for t, n, blob in leaves:
        put_dir(name_dir[(t, n)], [(LANG, entry[(t, n)], False)])
        struct.pack_into('<IIII', raw, entry[(t, n)], blob_at[(t, n)], len(blob), 0, 0)
        relocs.append(entry[(t, n)])  # OffsetToData is an RVA: needs relocation
        raw[blob_at[(t, n)]:blob_at[(t, n)] + len(blob)] = blob
    return bytes(raw), relocs


def coff(raw, relocs):
    header, section = 20, 40
    data_at = header + section
    reloc_at = data_at + len(raw)
    sym_at = reloc_at + 10 * len(relocs)
    out = struct.pack('<HHIIIHH', 0x8664, 1, 0, sym_at, 1, 0, 0)
    out += struct.pack('<8sIIIIIIHHI', b'.rsrc', 0, 0, len(raw), data_at, reloc_at, 0, len(relocs), 0, 0x40000040)
    out += raw
    for r in relocs:
        out += struct.pack('<IIH', r, 0, 3)  # IMAGE_REL_AMD64_ADDR32NB against the section symbol
    out += struct.pack('<8sIhHBB', b'.rsrc', 0, 1, 0, 3, 0)
    out += struct.pack('<I', 4)  # empty string table
    return out


if __name__ == '__main__':
    raw, relocs = build(read_ico(sys.argv[1]))
    open(sys.argv[2], 'wb').write(coff(raw, relocs))
    print(f'wrote {sys.argv[2]} ({len(raw)} bytes of resources)')
