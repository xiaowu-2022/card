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
        uni: { showToast: value => messages.push(value.title), showActionSheet: value => { sheet = value; } },
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

test('repeated clicks share one active refresh and desktop selection reads fresh iOS configuration', async () => {
    let finish, reads = 0;
    const action = downloadAction(session => {
        reads++;
        return new Promise(resolve => { finish = () => {
            session.value.appDownloads.iosDistributionUrl = 'https://new.example/ios';
            resolve();
        }; });
    }, 'Mozilla Windows');
    action.openAppDownload();
    assert.equal(reads, 0);
    const first = action.choose(1);
    await action.choose(1);
    assert.equal(reads, 1);
    finish();
    await first;
    assert.deepEqual(action.navigations, ['https://new.example/ios']);
});
