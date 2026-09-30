import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

function screen() {
    const pending = [], submissions = [], hooks = {}, exports = {};
    const action = { pending: { value: false }, errors: { value: {} }, failureStatus: { value: null },
        submit: async (...args) => { submissions.push(args); } };
    const source = readFileSync('mobile/uni-app/src/screens/Kyc.vue', 'utf8').split('<script setup lang="ts">')[1].split('</script>')[0];
    const js = ts.transpileModule(source + '\nexport { form, recognized, recognizing, submit, recognitionErrors };', {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    runInNewContext(js, { exports, Date, Intl,
        defineProps: () => ({ page: { kyc: { status: 'NOT_SUBMITTED' }, backHref: '/account', canSubmit: true } }),
        defineEmits: () => () => {},
        require: id => {
            if (id === 'vue') return { ref: value => ({ value }), reactive: value => value,
                computed: getter => ({ get value() { return getter(); } }), watch: (_, fn) => { hooks.change = fn; } };
            if (id.endsWith('/sensitive')) return { useSensitiveScreen: fn => { hooks.clear = fn; } };
            if (id.endsWith('/i18n')) return { t: key => key, locale: { value: 'en' } };
            if (id.endsWith('/client')) return { useAction: () => action, explainError: () => ({ form: 'Recognition failed' }) };
            if (id.endsWith('/api')) return { sessionGeneration: 1, upload: (...args) => new Promise((resolve, reject) => pending.push({ args, resolve, reject })) };
            return {};
        },
    });
    return { ...exports, action, pending, submissions, hooks };
}
const result = id => ({ identityNumber: '11010519491231002X', frontUploadId: id, expiresAt: new Date(Date.now() + 60000).toISOString() });

test('front selection recognizes immediately and submission sends only its reference plus the back', async () => {
    const s = screen();
    s.form.front = 'front.png';
    const work = s.hooks.change();
    assert.equal(s.recognizing.value, true);
    assert.equal(s.pending[0].args[0], '/client/kyc/recognize-front');
    assert.equal(s.pending[0].args[2].length, 1);
    assert.equal(s.pending[0].args[2][0].name, 'front');
    await s.submit();
    assert.equal(s.submissions.length, 0);
    s.pending[0].resolve(result('upload-front')); await work;
    s.form.back = 'back.png';
    await s.submit();
    assert.equal(s.recognized.value.identityNumber, '11010519491231002X');
    assert.equal(s.pending.length, 1);
    assert.equal(s.submissions[0][1].front_upload_id, 'upload-front');
    assert.equal(Object.hasOwn(s.submissions[0][1], 'identity_number'), false);
    assert.equal(s.submissions[0][2].files.length, 1);
    assert.equal(s.submissions[0][2].files[0].name, 'back');
});

test('replacement and page exit discard late OCR responses and clear the visible number', async () => {
    const s = screen();
    s.form.front = 'old.png'; const old = s.hooks.change();
    s.form.front = 'new.png'; const current = s.hooks.change();
    s.pending[0].resolve(result('old')); await old;
    assert.equal(s.recognized.value, null);
    s.pending[1].resolve(result('new')); await current;
    assert.equal(s.recognized.value.frontUploadId, 'new');
    s.form.front = 'third.png'; const third = s.hooks.change();
    s.hooks.clear();
    s.pending[2].resolve(result('third')); await third;
    assert.equal(s.recognized.value, null);
});

test('recognition failure or expired evidence blocks submission', async () => {
    const s = screen();
    s.form.front = 'failed.png'; const work = s.hooks.change();
    s.pending[0].reject(new Error('offline')); await work;
    await s.submit();
    assert.equal(s.submissions.length, 0);
    assert.equal(s.recognitionErrors.value.form, 'Recognition failed');
    s.recognized.value = { ...result('expired'), expiresAt: '2000-01-01T00:00:00Z' };
    await s.submit();
    assert.equal(s.submissions.length, 0);
    assert.equal(s.recognized.value, null);
});
