import importlib.util
import json
from pathlib import Path
import plistlib
import tempfile
import shutil
import subprocess
import sys
import unittest
import zipfile

SCRIPT = Path(__file__).resolve().parents[2] / 'mobile/webview-shell/scripts/check-ipa.py'
spec = importlib.util.spec_from_file_location('check_ipa', SCRIPT)
checker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(checker)


class IpaVersionTests(unittest.TestCase):
    def setUp(self):
        self.source = {'versionName': '2.6.02', 'versionCode': 2602, 'appid': '__UNI__TEST',
                       'app-plus': {'distribute': {'ios': {'appid': 'test.example'}}}}

    def make_ipa(self, path, native='2.6.02', resource='2.6.02', bundle='test.example'):
        with zipfile.ZipFile(path, 'w') as archive:
            archive.writestr('Payload/Test.app/Info.plist', plistlib.dumps({
                'CFBundleShortVersionString': native, 'CFBundleVersion': '2602', 'CFBundleIdentifier': bundle}, fmt=plistlib.FMT_BINARY))
            archive.writestr('Payload/Test.app/Pandora/apps/__UNI__TEST/www/manifest.json', json.dumps({
                'id': '__UNI__TEST', 'version': {'name': resource, 'code': 2602}}))

    def test_matching_package_passes_without_modification(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'matching.ipa'
            self.make_ipa(path)
            before = path.read_bytes()
            checks = checker.inspect_ipa(path, self.source)
            self.assertTrue(all(actual == expected for actual, expected in checks.values()))
            self.assertEqual(before, path.read_bytes())

    def test_old_native_new_resource_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'mixed.ipa'
            self.make_ipa(path, native='2.6.01')
            checks = checker.inspect_ipa(path, self.source)
            self.assertNotEqual(*checks['iOS version'])
            self.assertEqual(*checks['Resource version'])

    def test_old_resource_or_wrong_bundle_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'wrong.ipa'
            self.make_ipa(path, resource='2.6.01', bundle='another.example')
            checks = checker.inspect_ipa(path, self.source)
            self.assertNotEqual(*checks['Resource version'])
            self.assertNotEqual(*checks['Bundle ID'])

    def test_cli_checks_complete_package_on_supported_python(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'scripts').mkdir()
            (root / 'src').mkdir()
            (root / 'src/manifest.json').write_text(json.dumps(self.source))
            script = root / 'scripts/check-ipa.py'
            shutil.copyfile(SCRIPT, script)
            path = root / 'matching.ipa'
            self.make_ipa(path)
            result = subprocess.run([sys.executable, str(script), str(path)], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn('PASS SHA256:', result.stdout)
            self.make_ipa(path, native='2.6.01')
            result = subprocess.run([sys.executable, str(script), str(path)], capture_output=True, text=True)
            self.assertEqual(result.returncode, 1)
            self.assertIn('REJECTED', result.stdout)

    def test_missing_resource_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'missing.ipa'
            with zipfile.ZipFile(path, 'w') as archive:
                archive.writestr('Payload/Test.app/Info.plist', plistlib.dumps({}))
            with self.assertRaises(ValueError):
                checker.inspect_ipa(path, self.source)


if __name__ == '__main__':
    unittest.main()
