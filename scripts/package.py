#!/usr/bin/env python3
"""Build a stable WordPress directory, excluding tests, Git and credentials."""
import hashlib
import pathlib
import re
import zipfile
root = pathlib.Path(__file__).resolve().parents[1]
main = (root / 'kmpanel-connector.php').read_text()
header = re.search(r' \* Version: (\d+\.\d+\.\d+)', main).group(1)
constant = re.search(r"KMPANEL_CONNECTOR_VERSION', '(.*?)'", main).group(1)
assert header == constant, 'Plugin header and runtime version differ'
assert f'Stable tag: {header}' in (root / 'readme.txt').read_text(), 'readme version differs'
out = root / 'dist'
out.mkdir(exist_ok=True)
archive = out / 'kmpanel-connector.zip'
files = [root / 'kmpanel-connector.php', root / 'uninstall.php', root / 'readme.txt', root / 'LICENSE'] + sorted((root / 'includes').glob('*.php'))
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as package:
    for path in files:
        package.write(path, pathlib.Path('kmpanel-connector') / path.relative_to(root))
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(out / 'kmpanel-connector.zip.sha256').write_text(digest + '  kmpanel-connector.zip\n')
print(f'Built version {header}: {archive}')
