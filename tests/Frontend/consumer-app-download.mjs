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

function downloadAction(refresh, userAgent = 'Mozilla Android 14') {
    const session = { value: { appDownloads: { androidDownloadUrl: 'https://old.example/specpay.apk' } } };
    const navigations = [], messages = [];
    const action = {};
    let sheet;
    runInNewContext(ts.transpileModule(readFileSync('mobile/uni-app/src/lib/open-app-download.ts', 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS },
    }).outputText, {
        exports: action,
        require: name => ({
            './app-download': exports,
            './session': { session, bootstrap: () => refresh(session) },
            './i18n': { t: text => text },
        })[name],
        navigator: { userAgent, platform: '', maxTouchPoints: 0 },
        window: { location: { assign: url => navigations.push(url) } },
        uni: { navigateTo: value => { navigations.push(value.url); value.complete?.(); }, showToast: value => messages.push(value.title), showActionSheet: value => { sheet = value; } },
    });
    return { ...action, navigations, messages, choose: index => sheet.success({ tapIndex: index }) };
}

test('an already open page downloads from the newly published URL', async () => {
    const action = downloadAction(async session => {
        session.value.appDownloads.androidDownloadUrl = 'https://new.example/new.apk';
    });
    await action.openAppDownload();
    assert.deepEqual(action.navigations, ['https://new.example/new.apk']);
});

test('refresh failure never downloads from the cached URL and permits retry', async () => {
    let fails = true;
    const action = downloadAction(async session => {
        if (fails) throw new Error('Offline');
        session.value.appDownloads.androidDownloadUrl = 'https://new.example/new.apk';
    });
    await action.openAppDownload();
    assert.deepEqual(action.navigations, []);
    assert.deepEqual(action.messages, ['Unable to load. Please try again.']);
    fails = false;
    await action.openAppDownload();
    assert.deepEqual(action.navigations, ['https://new.example/new.apk']);
});

test('iOS opens its compiled page immediately without bootstrap or an external URL', async () => {
    let reads = 0;
    const action = downloadAction(() => { reads++; throw Error('must not bootstrap'); }, 'Mozilla iPhone OS 17');
    await action.openAppDownload();
    assert.equal(reads, 0);
    assert.deepEqual(action.navigations, ['/pages/app-download/index']);
    assert.deepEqual(action.messages, []);
});
test('desktop iOS selection opens the same internal distribution page', async () => {
    let reads = 0;
    const action = downloadAction(() => { reads++; }, 'Mozilla Windows');
    action.openAppDownload();
    await action.choose(1);
    assert.equal(reads, 0);
    assert.deepEqual(action.navigations, ['/pages/app-download/index']);
});
