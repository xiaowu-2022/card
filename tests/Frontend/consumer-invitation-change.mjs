import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

function registrationWithError(code) {
    const exports = {};
    const source = readFileSync('mobile/uni-app/src/screens/Registration.vue', 'utf8').split('<script setup lang="ts">')[1].split('</script>')[0];
    const js = ts.transpileModule(source + '\nexport { submit, invitationReleased, form };', {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const calls = [];
    runInNewContext(js, {
        exports,
        defineProps: () => ({ page: { registration: { invitationCode: '523612', invitationLocked: true } } }),
        defineEmits: () => () => {},
        require: id => {
            if (id === 'vue') return { ref: value => ({ value }), reactive: value => value, computed: getter => ({ get value() { return getter(); } }) };
            if (id.endsWith('/sensitive')) return { useSensitiveScreen() {} };
            if (id.endsWith('/client')) return { useAction: () => ({ failureCode: { value: code }, submit: async (...args) => calls.push(args) }) };
            return {};
        },
    });
    return { ...exports, calls };
}

test('an expired invitation releases the open registration form without losing email input', async () => {
    const screen = registrationWithError('INVITATION_INVALID');
    screen.form.destination = 'new@example.test';
    await screen.submit();
    assert.equal(screen.invitationReleased.value, true);
    assert.equal(screen.form.destination, 'new@example.test');
    screen.form.invitation_code = '523700';
    await screen.submit();
    assert.equal(screen.calls[1][1].invitation_code, '523700');
});

test('other registration errors do not release a valid locked invitation', async () => {
    for (const code of [null, 'INVITATION_IMMUTABLE', 'REGISTRATION_SEND_COOLDOWN']) {
        const screen = registrationWithError(code);
        await screen.submit();
        assert.equal(screen.invitationReleased.value, false);
    }
});
