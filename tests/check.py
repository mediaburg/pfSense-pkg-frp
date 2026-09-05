#!/usr/bin/env python3
"""Lint package files and verify the installed file inventory."""
from pathlib import Path
import subprocess
import sys
import xml.etree.ElementTree as ET
root = Path(__file__).resolve().parent.parent
files = root / 'files'
entries = []
for path in sorted(files.rglob('*')):
    if not path.is_file() or path.name in ('pkg-install.in', 'pkg-deinstall.in'):
        continue
    relative = path.relative_to(files).as_posix()
    entry = relative.removeprefix('usr/local/') if relative.startswith('usr/local/') else '/' + relative
    entries.append(entry.replace('share/pfSense-pkg-frp', '%%DATADIR%%'))
expected = '\n'.join(entries + ['@dir /etc/inc/priv', '@dir /etc/inc']) + '\n'
if '--write-plist' in sys.argv:
    (root / 'pkg-plist').write_text(expected)
else:
    assert (root / 'pkg-plist').read_text() == expected, 'pkg-plist is stale; run tests/check.py --write-plist'
for path in files.rglob('*'):
    if path.suffix in ('.php', '.inc'):
        subprocess.run(['php', '-l', str(path)], check=True, stdout=subprocess.DEVNULL)
    elif path.suffix == '.xml':
        ET.parse(path)
for relative in ['pkg-install.in', 'pkg-deinstall.in', 'usr/local/etc/rc.d/frpc-pfsense', 'usr/local/libexec/frp-supervisor']:
    subprocess.run(['sh', '-n', str(files / relative)], check=True)
print(f'PASS: PHP / XML / shell syntax and {len(entries)} installed files')
