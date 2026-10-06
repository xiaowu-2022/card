import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
import { dirname, resolve } from 'node:path';

function stateFor(kyc) {
    const exports = {};
    const source = readFileSync('mobile/uni-app/src/screens/Kyc.vue', 'utf8').split('<script setup lang="ts">')[1].split('</script>')[0];
    const js = ts.transpileModule(source + '\nexport { state };', {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    runInNewContext(js, {
        exports, defineProps: () => ({ page: { kyc } }), defineEmits: () => () => {},
        require: id => {
            if (id === 'vue') return { ref: value => ({ value }), reactive: value => value, computed: getter => ({ get value() { return getter(); } }) };
            if (id.endsWith('/sensitive')) return { useSensitiveScreen() {} };
            if (id.endsWith('/client')) return { useAction: () => ({}) };
            return {};
        },
    });
    return exports.state.value;
}

test('account-limit failures explain the business restriction for initial and repeat verification', () => {
    for (const status of ['PENDING', 'APPROVED']) {
        const state = stateFor({ status, processingStatus: 'FAILED', processingError: 'IDENTITY_ACCOUNT_LIMIT_REACHED' });
        assert.equal(state.title, 'Identity document account limit reached');
        assert.match(state.description, /Uploading it again will not resolve this/);
        for (const lang of ['en', 'zh-Hans', 'es', 'ms']) {
            const locale = JSON.parse(readFileSync(`mobile/uni-app/src/locale/${lang}.json`));
            assert.ok(locale[state.title]);
            assert.ok(locale[state.description]);
        }
    }
});

test('recognition validation and service outages give distinct next steps', () => {
    const state = error => stateFor({ status: 'PENDING', processingStatus: 'FAILED', processingError: error });
    assert.match(state('KYC_OCR_MISMATCH').description, /upload clear photos/);
    assert.match(state('KYC_OCR_UNAVAILABLE').description, /temporarily unavailable/);
    assert.match(state('KYC_DOCUMENT_STORAGE_FAILED').description, /could not be read/);
    assert.match(state('KYC_DOCUMENT_INTEGRITY_FAILED').description, /could not be verified/);
    assert.match(state('KYC_SUBMISSION_UNAVAILABLE').description, /currently unavailable/);
});

test('unknown errors stay generic and stale failures do not override active or approved states', () => {
    const unknown = stateFor({ status: 'PENDING', processingStatus: 'FAILED', processingError: 'internal-sensitive-error' });
    assert.doesNotMatch(unknown.description, /internal-sensitive-error/);
    assert.match(unknown.description, /contact support/);
    assert.equal(stateFor({ status: 'PENDING', processingStatus: 'QUEUED', processingError: 'IDENTITY_ACCOUNT_LIMIT_REACHED' }).title, 'Processing status');
    assert.equal(stateFor({ status: 'APPROVED', processingStatus: null }).title, 'Identity verified');
});

// Exercise the translator used by Kyc.vue, not uni-app's separate locale JSON.
function loadRuntime(path) {
    const exports = {};
    const js = ts.transpileModule(readFileSync(path, 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    runInNewContext(js, {
        exports,
        uni: { setLocale() {} },
        require: id => {
            if (id === 'vue') return { ref: value => ({ value }) };
            if (id === './api') return { setLanguage() {} };
            return loadRuntime(resolve(dirname(path), `${id}.ts`));
        },
    });
    return exports;
}

test('KYC status and failure banners follow the active language in the actual runtime catalog', () => {
    const runtime = loadRuntime('mobile/uni-app/src/lib/i18n.ts');
    const failures = [
        'IDENTITY_ACCOUNT_LIMIT_REACHED', 'KYC_OCR_MISMATCH', 'KYC_OCR_UNAVAILABLE',
        'KYC_DOCUMENT_STORAGE_FAILED', 'KYC_DOCUMENT_INTEGRITY_FAILED',
        'KYC_SUBMISSION_UNAVAILABLE', 'UNKNOWN',
    ].map(processingError => stateFor({ status: 'PENDING', processingStatus: 'FAILED', processingError }));
    const states = [
        ...failures,
        ...['NOT_SUBMITTED', 'PENDING', 'APPROVED', 'REJECTED', 'RESUBMISSION_REQUIRED'].map(status => stateFor({ status })),
        ...['QUEUED', 'PROCESSING', 'WAITING_REVIEW'].map(processingStatus => stateFor({ status: 'PENDING', processingStatus })),
    ];
    const { catalog } = loadRuntime('resources/js/i18n/catalog.ts');
    for (const language of ['zh-CN', 'ms', 'es', 'en', 'zh-CN']) {
        runtime.changeLocale(language);
        const index = ['zh-CN', 'ms', 'es'].indexOf(language);
        for (const state of states) {
            for (const key of [state.title, state.description]) {
                assert.ok(catalog[key], `Missing shared translation: ${key}`);
                const translated = runtime.t(key);
                assert.equal(translated, index < 0 ? key : catalog[key][index]);
                if (index >= 0) assert.notEqual(translated, key, `${language}: ${key}`);
            }
        }
    }
});
