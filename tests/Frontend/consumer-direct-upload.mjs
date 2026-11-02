import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
const syntheticPng = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAADklEQVQImWP4DwYMEAoAU7oL9W/sIDEAAAAASUVORK5CYII=', 'base64');
const syntheticJpeg = Buffer.from('/9j/2wBDAAYEBQYFBAYGBQYHBwYIChAKCgkJChQODwwQFxQYGBcUFhYaHSUfGhsjHBYWICwgIyYnKSopGR8tMC0oMCUoKSj/2wBDAQcHBwoIChMKChMoGhYaKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCj/wAARCAACAAIDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAABP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AJ7ANFf/2Q==', 'base64');
const syntheticWebp = Buffer.from('UklGRjAAAABXRUJQVlA4ICQAAABwAQCdASoCAAIAAUAmJYwCdAFAAAD++1XjAkrVm6WHJtcxAAA=', 'base64');
function client(platform = 'app', ossStatus = 204, backupStatus = 204, serverOnly = false, timers = {}, photo = {}) {
    const calls = [];
    let localReads = 0;
    const uni = {
        getLocale: () => 'en', getImageInfo: o => o.success({ type: photo.imageInfoType ?? (platform === 'app' ? 'png' : undefined) }),
        request(o) {
            calls.push(o);
            o.success({ statusCode: o.url.endsWith('/complete') ? 204 : 200, data: o.url.endsWith('/images/direct')
                ? serverOnly ? { id: 'ticket', mode: 'server' } : o.data.purpose === 'kyc' ? { id: 'ticket', mode: 'kyc_url', imageUrl: 'https://bucket.example.org/images/original', url: 'https://bucket.example.org', fields: { policy: 'short-policy', key: 'images/original' } } : { id: 'ticket', url: 'https://bucket.example.org', fields: { policy: 'short-policy', key: 'staging/key' } } : { ok: true } });
        },
        uploadFile(o) {
            calls.push(o);
            if (o.url.endsWith('/backup')) photo.onBackup?.(exports);
            o.success({ statusCode: o.url.endsWith('/backup') ? backupStatus : ossStatus });
        },
    };
    const exports = {};
    const source = readFileSync('mobile/uni-app/src/lib/api.ts', 'utf8').replaceAll('import.meta.env.UNI_PLATFORM', JSON.stringify(platform));
    runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText,
        { exports, uni, plus: { os: { name: 'Android' }, android: {} }, AbortController, Response, Blob, FormData, setTimeout, clearTimeout, ...timers,
            fetch: async (url, options = {}) => {
                assert.equal(options.credentials, 'omit');
                if(url.startsWith('blob:')) {
                    localReads++;
                    photo.onLocalRead?.(exports);
                    return new Response(new Blob([photo.bytes ?? syntheticPng], { type: photo.declaredMime ?? 'image/png' }));
                }
                return new Promise(resolve => {
                    const args={url,method:options.method,header:options.headers,
                        success:r=>resolve(new Response(r.statusCode===204?null:JSON.stringify(r.data),{status:r.statusCode})),
                        data:typeof options.body==='string'?JSON.parse(options.body):undefined,
                        formData:options.body instanceof FormData?Object.fromEntries(options.body):undefined};
                    if(options.body instanceof FormData) uni.uploadFile(args); else uni.request(args);
                });
            },
            require: id => id === './browser-credentials' ? { loadBrowserToken: async () => null, saveBrowserToken() {}, publishBrowserToken: async () => {} } : id === './device-credentials' ? { androidCredentialVault: () => ({ read: () => null, write() {} }) } : id.includes('company.json') ? { default: { appId: 'test', tenantSlug: 'company-a', apiOrigin: 'https://app.example.org' } } : { ensureLatestApp: async () => {}, companyOrigin: () => 'https://app.example.org', ensureCompanyOrigin: async () => {} } });
    exports.setToken('private-token'); return { api: exports, calls, uni, get localReads() { return localReads; } };
}
for (const platform of ['app', 'h5']) {
    test(platform + ' saves same image on server and OSS without leaking API credentials to OSS', async () => {
        const { api, calls } = client(platform);
        await api.upload('/support/messages', { request_id: 'request', support_message: 'private' }, [{ name: 'support_image', path: 'blob:image' }]);
        assert.equal(calls.length, 5);
        assert.ok(calls[1].url.endsWith('/backup'));
        assert.equal(calls[1].filePath, calls[2].filePath);
        assert.equal(calls[2].url, 'https://bucket.example.org');
        assert.deepEqual(Object.keys(calls[2].header), []);
        assert.equal(calls[2].formData.support_message, undefined);
        assert.equal(calls[4].data.support_image_upload_id, 'ticket');
        assert.equal(calls[1].header.Authorization, 'Bearer private-token');
    });
    test(platform + ' KYC uploads straight to OSS and submits exact original URLs without backup or complete', async () => {
        const { api, calls } = client(platform);
        await api.upload('/client/kyc/applications', { identity_number: 'synthetic' }, [{ name: 'front', path: 'blob:front' }, { name: 'back', path: 'blob:back' }]);
        assert.equal(calls.length, 5);
        assert.equal(calls.filter(o => /\/(backup|complete)$/.test(o.url)).length, 0);
        assert.deepEqual(Object.keys(calls[2].header), []);
        assert.equal(calls[4].data.front_upload_id, 'ticket');
        assert.equal(calls[4].data.front_url, 'https://bucket.example.org/images/original');
        assert.equal(calls[4].data.back_url, 'https://bucket.example.org/images/original');
    });
}

for (const photo of [
    { declaredMime: 'image/jpeg' },
    { declaredMime: 'application/octet-stream' },
    { declaredMime: '' },
    { declaredMime: 'image/jpeg', imageInfoType: 'jpeg' },
    { declaredMime: 'image/png', imageInfoType: 'webp' },
]) test('h5 detects PNG original bytes despite inconsistent metadata ' + JSON.stringify(photo), async () => {
    const subject = client('h5', 204, 204, true, {}, photo);
    await subject.api.upload('/client/kyc/applications', {}, [{ name: 'front', path: 'blob:front' }]);
    const ticket = subject.calls.find(call => call.url.endsWith('/images/direct'));
    const backup = subject.calls.find(call => call.url.endsWith('/backup'));
    assert.equal(ticket.data.mime, 'image/png');
    assert.equal(backup.formData.file.type, 'image/png');
    assert.equal(backup.formData.file.name, 'image.png');
    assert.deepEqual(Buffer.from(await backup.formData.file.arrayBuffer()), syntheticPng);
    assert.equal(subject.localReads, 1, 'ticket metadata and multipart must use the same original Blob');
    assert.equal(subject.calls.filter(call => call.url.endsWith('/client/kyc/applications')).length, 1);
});

for (const [bytes, mime, extension] of [
    [syntheticJpeg, 'image/jpeg', 'jpg'],
    [syntheticWebp, 'image/webp', 'webp'],
]) test('h5 detects ' + mime + ' original bytes despite PNG metadata', async () => {
    const subject = client('h5', 204, 204, true, {}, { bytes, declaredMime: 'image/png', imageInfoType: 'png' });
    await subject.api.upload('/client/kyc/applications', {}, [{ name: 'front', path: 'blob:front' }]);
    const ticket = subject.calls.find(call => call.url.endsWith('/images/direct'));
    const file = subject.calls.find(call => call.url.endsWith('/backup')).formData.file;
    assert.equal(ticket.data.mime, mime);
    assert.equal(file.type, mime);
    assert.equal(file.name, 'image.' + extension);
    assert.deepEqual(Buffer.from(await file.arrayBuffer()), bytes);
    assert.equal(subject.localReads, 1);
});

test('h5 sends identical normalized original bytes to both server and OSS without forwarding API credentials', async () => {
    const subject = client('h5', 204, 204, false, {}, { declaredMime: 'image/jpeg', imageInfoType: 'jpeg' });
    await subject.api.upload('/support/messages', { support_message: 'private' }, [{ name: 'support_image', path: 'blob:image' }]);
    const ticket = subject.calls.find(call => call.url.endsWith('/images/direct'));
    const backup = subject.calls.find(call => call.url.endsWith('/backup'));
    const oss = subject.calls.find(call => call.url === 'https://bucket.example.org');
    assert.equal(ticket.data.mime, 'image/png');
    for (const call of [backup, oss]) {
        assert.equal(call.formData.file.type, 'image/png');
        assert.deepEqual(Buffer.from(await call.formData.file.arrayBuffer()), syntheticPng);
    }
    assert.equal(backup.header.Authorization, 'Bearer private-token');
    assert.deepEqual(Object.keys(oss.header), []);
    assert.equal(oss.formData.support_message, undefined);
    assert.equal(subject.localReads, 1);
});

for (const bytes of [
    Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64'),
    Buffer.from('<script>not an image</script>'),
    Buffer.from([0x89, 0x50, 0x4e]),
    Buffer.alloc(0),
]) test('h5 rejects unsupported original headers before requesting a ticket, size=' + bytes.length, async () => {
    const subject = client('h5', 204, 204, true, {}, { bytes, declaredMime: 'image/png', imageInfoType: 'png' });
    await assert.rejects(subject.api.upload('/client/kyc/applications', {}, [{ name: 'back', path: 'blob:back' }]),
        error => error.stage === 'read' && error.field === 'back' && error.payload?.error?.code === 'CLIENT_IMAGE_FORMAT');
    assert.equal(subject.calls.length, 0, 'invalid bytes never reach upload authorization or business submission');
    assert.equal(subject.localReads, 1);
});

test('h5 stops before upload authorization when the session changes during the local Blob read', async () => {
    const subject = client('h5', 204, 204, true, {}, {
        onLocalRead: api => api.setToken('replacement-token'),
    });
    await assert.rejects(subject.api.upload('/client/kyc/applications', {}, [{ name: 'front', path: 'blob:front' }]),
        error => error.status === 409 && error.payload?.error?.code === 'CLIENT_SESSION_CHANGED');
    assert.equal(subject.localReads, 1);
    assert.equal(subject.calls.length, 0, 'the old photo must not authorize or submit under the new session');
});

for (const serverOnly of [false, true]) test('h5 stops after backup when the session changes, serverOnly=' + serverOnly, async () => {
    const subject = client('h5', 204, 204, serverOnly, {}, {
        onBackup: api => api.setToken('replacement-token'),
    });
    await assert.rejects(subject.api.upload('/support/messages', { support_message: 'private' }, [{ name: 'support_image', path: 'blob:image' }]),
        error => error.status === 409 && error.payload?.error?.code === 'CLIENT_SESSION_CHANGED');
    assert.equal(subject.calls.length, 2);
    assert.ok(subject.calls[0].url.endsWith('/images/direct'));
    assert.ok(subject.calls[1].url.endsWith('/backup'));
    assert.equal(subject.calls[1].header.Authorization, 'Bearer private-token');
    assert.ok(subject.calls.every(call => !call.url.includes('bucket.example.org') && !call.url.endsWith('/complete') && !call.url.endsWith('/support/messages')));
});
test('server backup failure stops before OSS or business submission', async () => {
    const { api, calls } = client('app', 204, 500);
    await assert.rejects(api.upload('/support/messages', {}, [{ name: 'support_image', path: 'local' }]));
    assert.equal(calls.length, 2);
});
test('OSS failure retains server copy and lets server completion record repair without replaying business', async () => {
    const { api, calls } = client('app', 403);
    await api.upload('/support/messages', {}, [{ name: 'support_image', path: 'local' }]);
    assert.equal(calls.filter(o => o.url.endsWith('/support/messages')).length, 1);
    assert.ok(calls[3].url.endsWith('/complete'));
});

for (const platform of ['app', 'h5']) {
    test(platform + ' server-only ticket uploads exclusively to the application and submits once', async () => {
        const { api, calls } = client(platform, 204, 204, true);
        await api.upload('/client/kyc/applications', {}, [{ name: 'front', path: 'blob:front' }]);
        assert.equal(calls.length, 4);
        assert.ok(calls[1].url.endsWith('/backup'));
        assert.ok(calls[2].url.endsWith('/complete'));
        assert.equal(calls[3].data.front_upload_id, 'ticket');
        assert.ok(calls.every(c => !c.url.includes('bucket.example.org')));
    });
}

for (const platform of ['app', 'h5']) {
    test(platform + ' failed KYC OSS upload prevents OCR submission and never falls back locally', async () => {
        const { api, calls } = client(platform, 403);
        await assert.rejects(api.upload('/client/kyc/applications', {}, [{ name: 'front', path: 'blob:front' }]));
        assert.equal(calls.length, 2);
        assert.equal(calls[1].url, 'https://bucket.example.org');
    });
}

for (const platform of ['app', 'h5']) {
    for (const fail of [false, true]) test(platform + ' parallel KYC waits for both uploads and preserves fields, failure=' + fail, async () => {
        const { api, calls, uni } = client(platform);
        const pending = [], progress = [];
        const original = uni.request;
        uni.request = o => {
            if (o.url.endsWith('/images/direct')) {
                calls.push(o);
                const side = o.data.field;
                o.success({ statusCode: 200, data: { id: side, mode: 'kyc_url', url: 'https://bucket.example.org', imageUrl: 'https://bucket.example.org/' + side, fields: { key: side } } });
            } else original(o);
        };
        uni.uploadFile = o => { calls.push(o); pending.push(o); };
        let settled = false;
        const promise = api.upload('/client/kyc/applications', { reverify: true }, [{ name: 'front', path: 'blob:front' }, { name: 'back', path: 'blob:back' }], p => progress.push(p));
        const result = promise.then(v => { settled = true; return v; }, e => { settled = true; return e; });
        while (pending.length < 2) await new Promise(resolve => setImmediate(resolve));
        assert.equal(pending.length, 2);
        assert.equal(pending[0].formData.key, 'front');
        assert.equal(pending[1].formData.key, 'back');
        pending[1].success({ statusCode: fail ? 403 : 204 });
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(settled, false);
        pending[0].success({ statusCode: 204 });
        await result;
        const submissions = calls.filter(c => c.url.endsWith('/client/kyc/applications'));
        assert.equal(submissions.length, fail ? 0 : 1);
        if (!fail) {
            assert.equal(submissions[0].data.reverify, true);
            assert.equal(submissions[0].data.front_url, 'https://bucket.example.org/front');
            assert.equal(submissions[0].data.back_upload_id, 'back');
            assert.equal(progress.at(-1).stage, 'submitting');
        }
        const count = progress.length;
        pending[0].success({ statusCode: 204 });
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(progress.length, count);
    });
}

for (const platform of ['app', 'h5']) {
    test(platform + ' hung image metadata times out before requesting upload tickets', async () => {
        const timers = new Map();
        let next = 0;
        const { api, calls, uni } = client(platform, 204, 204, false, {
            setTimeout: (fn, ms) => { const id = ++next; timers.set(id, { fn, ms }); return id; },
            clearTimeout: id => timers.delete(id),
        });
        uni.getImageInfo = () => {};
        const result = api.upload('/client/kyc/applications', {}, [{ name: 'back', path: 'blob:back' }]);
        const rejection = assert.rejects(result, error => error.stage === 'read' && error.field === 'back'
            && error.payload.error.code === 'CLIENT_REQUEST_TIMEOUT');
        await new Promise(resolve => setImmediate(resolve));
        const deadline = [...timers.values()].find(timer => timer.ms === 15000);
        assert.ok(deadline);
        deadline.fn();
        await rejection;
        assert.equal(calls.length, 0);
    });
}
test('native upload watchdog aborts a silent transport and ignores late success', async () => {
    const timers = new Map();
    let next = 0, aborted = false, pending;
    const { api, calls, uni } = client('app', 204, 204, true, {
        setTimeout: (fn, ms) => { const id = ++next; timers.set(id, { fn, ms }); return id; },
        clearTimeout: id => timers.delete(id),
    });
    uni.uploadFile = options => { pending = options; return { abort() { aborted = true; } }; };
    const result = api.upload('/client/kyc/applications', {}, [{ name: 'back', path: 'local' }]);
    const rejection = assert.rejects(result, error => error.stage === 'upload'
        && error.payload.error.code === 'CLIENT_REQUEST_TIMEOUT');
    await new Promise(resolve => setImmediate(resolve));
    [...timers.values()].find(timer => timer.ms === 120000).fn();
    await rejection;
    assert.equal(aborted, true);
    pending.success({ statusCode: 204 });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(calls.length, 1);
});
