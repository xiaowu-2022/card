import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
const exports = {};
runInNewContext(ts.transpileModule(readFileSync('mobile/uni-app/src/lib/app-download.ts', 'utf8'), {compilerOptions:{module:ts.ModuleKind.CommonJS}}).outputText, {exports});
test('phone and desktop iPad detection select the matching platform; desktops ask', () => {
    assert.equal(exports.downloadPlatform('Mozilla Android 14'), 'android');
    assert.equal(exports.downloadPlatform('Mozilla iPhone OS 17'), 'ios');
    assert.equal(exports.downloadPlatform('Mozilla iPad'), 'ios');
    assert.equal(exports.downloadPlatform('Mozilla Macintosh', 'MacIntel', 5), 'ios');
    assert.equal(exports.downloadPlatform('Mozilla Macintosh', 'MacIntel', 0), null);
    assert.equal(exports.downloadPlatform('Mozilla Windows', 'Win32', 1), null);
});
test('platform URLs stay separate and missing iOS never opens an APK', () => {
    const downloads={androidDownloadUrl:'https://download.example/app.apk',iosDistributionUrl:'https://install.example/ios'};
    assert.equal(exports.appDownloadUrl(downloads, 'android'),downloads.androidDownloadUrl);
    assert.equal(exports.appDownloadUrl(downloads, 'ios'),downloads.iosDistributionUrl);
    for (const url of [null, '', 'javascript:alert(1)', '//example.com', 'https://user:pass@example.com']) {
        assert.equal(exports.appDownloadUrl({...downloads,iosDistributionUrl:url},'ios'),null);
    }
    assert.equal(exports.appDownloadUrl(undefined,'android'),null);
});
