import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

function harness() {
    const cache = new Map(), calls = [];
    const uni = {
        getLocale: () => 'zh-CN',
        getImageInfo: o => o.success({ type: 'jpeg' }),
        request(o) {
            calls.push(o);
            o.success({ statusCode: 200, data: o.url.endsWith('/images/direct')
                ? { id: o.data.field, mode: 'server', fields: {} } : {} });
        },
        uploadFile(o) { calls.push(o); o.success({ statusCode: 204, data: '' }); },
    };
    const hooks = {};
    function load(path) {
        path = resolve(path);
        if (cache.has(path)) return cache.get(path);
        const exports = {}; cache.set(path, exports);
        const source = readFileSync(path, 'utf8').replaceAll('import.meta.env.UNI_PLATFORM', '"h5"');
        runInNewContext(ts.transpileModule(source, {
            compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
        }).outputText, { exports, uni, URLSearchParams, require: id => {
            if (id === 'vue') return { ref: value => ({ value }), onScopeDispose: fn => hooks.dispose = fn };
            if (id === './origin') return { ensureCompanyOrigin: async () => {}, companyOrigin: () => '' };
            if (id.endsWith('company.json')) return {};
            if (id === './session') return { session: { value: {} } };
            if (id === './navigation') return {};
            return load(resolve(dirname(path), id + '.ts'));
        } });
        return exports;
    }
    const api = load('mobile/uni-app/src/lib/api.ts'), i18n = load('mobile/uni-app/src/lib/i18n.ts');
    i18n.locale.value = 'zh-CN';
    return { api, i18n, calls, uni, hooks, load,
        explain: load('mobile/uni-app/src/lib/kyc-errors.ts').explainKycSubmissionError,
        action: load('mobile/uni-app/src/lib/client.ts').useAction(),
    };
}
const files = [{ name: 'front', path: 'private-front.jpg' }, { name: 'back', path: 'private-back.jpg' }];
const submit = c => c.action.submit('/kyc/applications', { document_type: 'NATIONAL_ID' }, {
    files, navigate: false, explainFailure: error => c.explain(error, 'NATIONAL_ID'),
});

test('back upload keeps API validation details and never submits an incomplete application', async () => {
    const c = harness();
    c.uni.uploadFile = o => {
        c.calls.push(o);
        o.success({ statusCode: o.filePath.includes('back') ? 422 : 204,
            data: JSON.stringify({ errors: { file: ['The file field must not be greater than 10240 kilobytes.'] } }) });
    };
    await submit(c);
    assert.match(c.action.errors.value.operation, /背面.*上传失败/);
    assert.match(c.action.errors.value.reason, /10240 KB/);
    assert.equal(c.action.failureStatus.value, 422);
    assert.equal(c.action.pending.value, false);
    assert.ok(c.calls.every(o => !o.url.endsWith('/kyc/applications')));
});

test('read and unsupported-format failures identify the photo without leaking its path', async () => {
    for (const mode of ['read', 'format']) {
        const c = harness();
        c.uni.getImageInfo = o => mode === 'read' ? o.fail() : o.success({ type: 'heic' });
        await submit(c);
        assert.match(c.action.errors.value.operation, /无法读取.*正面/);
        assert.match(c.action.errors.value.reason, mode === 'read' ? /重新选择/ : /JPEG.*PNG.*WEBP/);
        assert.equal(c.calls.length, 0);
        assert.ok(!JSON.stringify(c.action.errors.value).includes('private-front'));
    }
});

test('uncertain submission requests refresh before retry and never automatically replay', async () => {
    const c = harness(), send = c.uni.request;
    c.uni.request = o => {
        if (!o.url.endsWith('/kyc/applications')) return send(o);
        c.calls.push(o); o.fail({ errMsg: 'request:fail timeout' });
    };
    await submit(c);
    assert.match(c.action.errors.value.operation, /身份认证申请/);
    assert.match(c.action.errors.value.reason, /超时/);
    assert.match(c.action.errors.value.next, /可能已被接收.*先刷新状态/);
    assert.equal(c.calls.filter(o => o.url.endsWith('/kyc/applications')).length, 1);
});

test('gateway HTML, outages, expiry and throttling yield distinct safe reasons', async () => {
    for (const [status, expected] of [[413, /上传大小/], [401, /登录/], [419, /过期/], [429, /频繁/], [502, /服务暂时异常/]]) {
        const c = harness();
        c.uni.uploadFile = o => o.success({ statusCode: status, data: '<html>SECRET storage path</html>' });
        await submit(c);
        assert.match(c.action.errors.value.reason, expected);
        assert.match(c.action.errors.value.operation, /正面.*上传失败/);
        assert.ok(!JSON.stringify(c.action.errors.value).includes('SECRET'));
    }
});

test('prepare and upload completion errors keep their own stage', async () => {
    for (const [suffix, expected] of [['/images/direct', /准备证件上传/], ['/complete', /确认.*上传结果/]]) {
        const c = harness(), send = c.uni.request;
        c.uni.request = o => o.url.endsWith(suffix)
            ? o.success({ statusCode: 503, data: {} }) : send(o);
        await submit(c);
        assert.match(c.action.errors.value.operation, expected);
        assert.ok(c.calls.every(o => !o.url.endsWith('/kyc/applications')));
    }
});

test('field validation stays localized and untrusted provider details are not displayed', () => {
    const c = harness();
    const detail = c.explain(new c.api.UploadError('submit', null, new c.api.ApiError(422, {
        errors: { back: ['The back field is required.'], document_country: ['The selected document_country is invalid.'] },
    })), 'NATIONAL_ID');
    assert.match(detail.reason, /背面.*填写/);
    assert.match(detail.reason, /国家/);
    const unsafe = c.explain(new c.api.UploadError('upload', 'front', new c.api.ApiError(422, {
        error: { message: 'provider secret token at https://private.example/image' },
    })), 'PASSPORT');
    assert.match(unsafe.operation, /护照/);
    assert.match(unsafe.reason, /未返回具体原因/);
    assert.ok(!JSON.stringify(unsafe).includes('private.example'));
    for (const locale of ['en', 'zh-CN', 'ms', 'es']) {
        c.i18n.locale.value = locale;
        for (const stage of ['read', 'prepare', 'upload', 'verify', 'submit']) {
            const result = c.explain(new c.api.UploadError(stage, 'front', new c.api.ApiError(0)), 'PASSPORT');
            assert.ok(!JSON.stringify(result).includes('{{'));
            if (locale !== 'en') assert.ok(!result.operation.startsWith('Unable') && !result.operation.startsWith('Identity'));
        }
    }
});
