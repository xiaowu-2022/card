<script setup lang="ts">
import { useSensitiveScreen } from '../lib/sensitive';
import { reactive } from 'vue';
import AuthLayout from '../components/AuthLayout.vue';
import FormField from '../components/FormField.vue';
import FormErrors from '../components/FormErrors.vue';
import { t, dateTime } from '../lib/i18n';
import { useAction, requestId } from '../lib/client';
import { go } from '../lib/navigation';
const props = defineProps<{ page: { reset?: { id: string; expiresAt: string } } }>();
const action = useAction();
const form = reactive({
    channel: 'EMAIL',
    reset_contact: '',
    request_id: requestId(),
    code: '',
    password: '',
    password_confirmation: '',
    confirmed: false,
});
useSensitiveScreen(() => {
    form.password = '';
    form.password_confirmation = '';
    form.code = '';
    form.confirmed = false;
});
function expiresLabel() {
    return t('Code expires at {{time}}', { time: dateTime(props.page.reset?.expiresAt ?? '') });
}
async function submit() {
    if (props.page.reset)
        await action.submit('/forgot-password/' + props.page.reset.id, {
            code: form.code,
            password: form.password,
            password_confirmation: form.password_confirmation,
            confirmed: form.confirmed,
        });
    else
        await action.submit('/forgot-password', {
            channel: form.channel,
            reset_contact: form.reset_contact,
            request_id: form.request_id,
        });
}
</script>
<template>
    <AuthLayout recovery :title="t(page.reset ? 'Reset password' : 'Recover password')"
        ><text v-if="page.reset" class="recovery-copy">{{ expiresLabel() }}</text
        ><text class="recovery-copy">{{
            t(
                page.reset
                    ? 'If this contact belongs to an eligible account, use the code to reset its password. Receiving a code does not confirm an account exists. If delivery is delayed, wait until the code expires before requesting another.'
                    : 'Use the verified email already linked to your account. This does not create a new account.',
            )
        }}</text
        ><FormErrors :errors="action.errors.value" />
        <form class="recovery-form" @submit="submit">
            <template v-if="page.reset"
                ><FormField
                    v-model="form.code"
                    :label="t('Verification code')"
                    type="number"
                    digits-only
                    :maxlength="6"
                /><FormField
                    v-model="form.password"
                    :label="t('New password')"
                    :description="t('At least 6 characters.')"
                    password
                    :maxlength="1024"
                /><FormField
                    v-model="form.password_confirmation"
                    :label="t('Confirm new password')"
                    password
                    :maxlength="1024"
                /><checkbox-group @change="form.confirmed = $event.detail.value.includes('yes')"
                    ><label class="confirmation"
                        ><checkbox value="yes" :checked="form.confirmed" color="#39ad8d" /><text>{{
                            t(
                                'I confirm resetting my password. All previous sign-ins will expire and I must sign in again.',
                            )
                        }}</text></label
                    ></checkbox-group
                ></template
            ><FormField
                v-else
                v-model="form.reset_contact"
                :label="t('Email address')"
                :disabled="action.pending.value"
                @update:model-value="form.request_id = requestId()"
            /><button
                class="recovery-button"
                form-type="submit"
                :disabled="
                    action.pending.value || (page.reset ? !form.confirmed : !form.reset_contact)
                "
            >
                {{
                    t(
                        page.reset
                            ? 'Reset password'
                            : action.pending.value
                              ? 'Sending…'
                              : 'Send verification code',
                    )
                }}
            </button>
        </form>
        <button v-if="page.reset" class="text-button" @click="go('/forgot-password', true)">
            {{ t('Request a new code') }}
        </button></AuthLayout
    >
</template>
<style scoped>
.recovery-copy {
    display: block;
    color: #727d76;
    font-size: 14px;
    line-height: 24px;
    margin-bottom: 24px;
}
.confirmation {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    border: 1px solid #dedfd8;
    padding: 12px;
    border-radius: 12px;
    font-size: 14px;
    line-height: 24px;
    margin-bottom: 20px;
}
.confirmation checkbox {
    flex-shrink: 0;
}
.recovery-button {
    background: var(--user-primary, #39ad8d);
    color: var(--tenant-primary-foreground, #10231c);
    padding: 10px 16px;
    width: 100%;
    font-size: 16px;
    font-weight: 500;
    line-height: 24px;
    border-radius: 8px;
}
.recovery-button[disabled] {
    background: var(--user-primary, #39ad8d);
    color: var(--tenant-primary-foreground, #10231c);
    opacity: 0.5;
}
.recovery-form :deep(.form-input) {
    height: 44px;
    min-height: 44px;
    border-radius: 8px;
    padding-inline: 12px;
}
.text-button {
    display: block;
    background: transparent;
    font-size: 14px;
    text-decoration: underline;
    text-underline-offset: 4px;
    margin: 24px auto 0;
    padding: 0;
}
</style>
