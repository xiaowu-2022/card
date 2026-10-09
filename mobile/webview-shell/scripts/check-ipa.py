#!/usr/bin/env python3
"""Read-only release gate. Never dump manifests or signing configuration."""
import argparse
import hashlib
import json
from pathlib import Path
import plistlib
import sys
import zipfile


def inspect_ipa(ipa, source):
    expected_version = str(source['versionName'])
    expected_code = str(source['versionCode'])
    appid = source['appid']
    bundle = source['app-plus']['distribute']['ios']['appid']
    with zipfile.ZipFile(ipa) as archive:
        infos = [n for n in archive.namelist() if n.startswith('Payload/') and n.endswith('.app/Info.plist') and n.count('/') == 2]
        if len(infos) != 1:
            raise ValueError('Expected exactly one main application Info.plist.')
        root = infos[0].rsplit('/', 1)[0]
        resources = [n for n in archive.namelist() if n == f'{root}/Pandora/apps/{appid}/www/manifest.json']
        if len(resources) != 1:
            raise ValueError('Expected exactly one resource manifest for the configured AppID.')
        info = plistlib.loads(archive.read(infos[0]))
        manifest = json.loads(archive.read(resources[0]))
    checks = {
        'iOS version': (str(info.get('CFBundleShortVersionString')), expected_version),
        'iOS build': (str(info.get('CFBundleVersion')), expected_code),
        'Resource version': (str(manifest.get('version', {}).get('name')), expected_version),
        'Resource build': (str(manifest.get('version', {}).get('code')), expected_code),
        'Bundle ID': (info.get('CFBundleIdentifier'), bundle),
        'DCloud AppID': (manifest.get('id'), appid),
    }
    return checks


def main():
    parser = argparse.ArgumentParser(description='Verify IPA native/resource versions against src/manifest.json without modifying the IPA.')
    parser.add_argument('ipa', type=Path)
    args = parser.parse_args()
    try:
        source_path = Path(__file__).resolve().parents[1] / 'src/manifest.json'
        source = json.loads(source_path.read_text())
        checks = inspect_ipa(args.ipa, source)
        good = True
        for label, (actual, expected) in checks.items():
            matches = actual == expected
            good &= matches
            print(f'{"PASS" if matches else "FAIL"} {label}: {actual} (expected {expected})')
        if not good:
            print('REJECTED: inconsistent IPA; rebuild a full native package before distribution.')
            return 1
        checksum = hashlib.sha256()
        with args.ipa.open('rb') as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b''):
                checksum.update(chunk)
        digest = checksum.hexdigest()
        print(f'PASS SHA256: {digest}')
        print('Version/identity check passed. This does not validate code signing or device behavior.')
        return 0
    except (OSError, ValueError, KeyError, zipfile.BadZipFile, plistlib.InvalidFileException):
        print('FAIL: unable to read the required IPA/source version fields.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
