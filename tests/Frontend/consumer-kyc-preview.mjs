import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// New submissions upload both originals, then recognize them in the background.
// Keep this former preview regression focused on the maintained asynchronous flow.
function screen(backHref = '/account') {
    const submissions = [], events = [], hooks = {}, exports = {};
    const action = { pending: { value: false }, errors: { value: {} },
        submit: async (...args) => { submissions.push(args); } };
    const source = readFileSync('mobile/uni-app/src/screens/Kyc.vue', 'utf8').split('<script setup lang="ts">')[1].split('</script>')[0];
    const js = ts.transpileModule(source + '\nexport { form, submit, progress, resetFiles, reverify, reverifying };', {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    runInNewContext(js, { exports, Intl,
        defineProps: () => ({ page: { kyc: { status: 'NOT_SUBMITTED' }, backHref, canSubmit: true } }),
        defineEmits: () => event => events.push(event),
        require: id => {
            if (id === 'vue') return { ref: value => ({ value }), reactive: value => value,
                computed: getter => ({ get value() { return getter(); } }) };
            if (id.endsWith('/sensitive')) return { useSensitiveScreen: fn => { hooks.clear = fn; } };
            if (id.endsWith('/i18n')) return { t: key => key, locale: { value: 'en' } };
            if (id.endsWith('/client')) return { useAction: () => action };
            if (id.endsWith('/api')) throw new Error('KYC screen must not call OCR before submitting');
            return {};
        },
    });
    return { ...exports, action, submissions, events, hooks };
}

test('national ID submission needs both photos and uploads them together without client recognition or identity values', async () => {
    const s = screen();
    await s.submit();
    s.form.front = 'front.png';
    await s.submit();
    assert.equal(s.submissions.length, 0);
    s.form.back = 'back.png';
    await s.submit();
    assert.equal(s.submissions.length, 1);
    const [path, data, options] = s.submissions[0];
    assert.equal(path, '/kyc/applications');
    assert.deepEqual(Object.keys(data).sort(), ['document_country', 'document_type', 'reverify']);
    assert.equal(data.document_type, 'NATIONAL_ID');
    assert.equal(data.document_country, 'CN');
    assert.equal(data.reverify, false);
    assert.deepEqual(Array.from(options.files, file => [file.name, file.path]), [['front', 'front.png'], ['back', 'back.png']]);
    assert.equal(options.navigate, false);
    assert.equal(s.progress.value.total, 2);
});

test('pending submission is not repeated and a failed upload retains photos until explicit clearing', async () => {
    const s = screen();
    s.form.front = 'front.png';
    s.form.back = 'back.png';
    s.action.pending.value = true;
    await s.submit();
    assert.equal(s.submissions.length, 0);
    s.action.pending.value = false;
    await s.submit();
    assert.equal(s.form.front, 'front.png');
    assert.equal(s.form.back, 'back.png');
    s.hooks.clear();
    assert.equal(s.form.front, '');
    assert.equal(s.form.back, '');
});

test('successful background submission resets reverification and reloads the current status', async () => {
    const s = screen('/account/security');
    s.reverify();
    s.form.front = 'front.png';
    s.form.back = 'back.png';
    await s.submit();
    const [path, data, options] = s.submissions[0];
    assert.equal(path, '/kyc/applications?from=account-security');
    assert.equal(data.reverify, true);
    options.onProgress({ stage: 'submitting', completed: 2, total: 2 });
    assert.equal(s.progress.value.stage, 'submitting');
    s.action.errors.value = { form: 'old error' };
    options.success();
    assert.equal(s.form.front, '');
    assert.equal(s.form.back, '');
    assert.equal(Object.keys(s.action.errors.value).length, 0);
    assert.equal(s.reverifying.value, false);
    assert.deepEqual(s.events, ['reload']);
});

test('passport submission continues to upload only its information page', async () => {
    const s = screen();
    s.form.document_type = 'PASSPORT';
    s.form.document_country = 'MY';
    s.form.front = 'passport.png';
    await s.submit();
    assert.equal(s.submissions.length, 1);
    assert.equal(s.submissions[0][1].document_country, 'MY');
    assert.deepEqual(Array.from(s.submissions[0][2].files, file => file.name), ['front']);
    assert.equal(s.progress.value.total, 1);
});
