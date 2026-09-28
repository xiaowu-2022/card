<script setup lang="ts">
import { useSensitiveScreen } from '../lib/sensitive';
import { computed, reactive, ref, onBeforeUnmount, watch } from 'vue';
import PageShell from '../components/PageShell.vue';
import FormField from '../components/FormField.vue';
import FormErrors from '../components/FormErrors.vue';
import ConfirmCheck from '../components/ConfirmCheck.vue';
import UiIcon from '../components/UiIcon.vue';
import { t } from '../lib/i18n';
import { session, bootstrap } from '../lib/session';
import { useAction, requestId } from '../lib/client';
import { go } from '../lib/navigation';
type Challenge = {
    id: string;
    channel: string;
    destination: string;
    expiresAt: string;
    resendAt?: string;
    deliveryUncertain: boolean;
};
const props = defineProps<{
    page: {
        kycStatus: string;
        information: { canEdit: boolean; email: string | null; challenge: Challenge | null };
    };
}>();
const emit = defineEmits<{ reload: [] }>();
const action = useAction(),
    expanded = ref(props.page.information.challenge ? 'EMAIL' : ''),
    editing = ref(false),
    now = ref(Date.now());
const form = reactive({
    display_name: session.value?.user?.displayName ?? '',
    new_contact: '',
    current_password: '',
    password: '',
    password_confirmation: '',
    code: '',
    confirmed: false,
    request_id: requestId(),
});
const timer = setInterval(() => (now.value = Date.now()), 1000);
onBeforeUnmount(() => {
    clearInterval(timer);
    form.current_password = '';
    form.password = '';
    form.password_confirmation = '';
    form.code = '';
});
const challenge = computed(() => props.page.information.challenge);
watch(
    () => challenge.value?.id,
    () => {
        editing.value = false;
        form.code = '';
        form.confirmed = false;
    },
);
const remaining = computed(() =>
    Math.max(0, Math.ceil((Date.parse(challenge.value?.expiresAt ?? '') - now.value) / 1000)),
);
const resendRemaining = computed(() =>
    Math.max(
        0,
        Math.ceil(
            (Date.parse(challenge.value?.resendAt ?? challenge.value?.expiresAt ?? '') -
                now.value) /
                1000,
        ),
    ),
);
const remainingLabel = computed(() =>
    remaining.value > 0
        ? t('Code expires in {{seconds}}s', { seconds: remaining.value })
        : t('Verification code expired. Request a new code.'),
);
const resendLabel = computed(() =>
    resendRemaining.value > 0
        ? t('Request a new code in {{seconds}}s', { seconds: resendRemaining.value })
        : t('Request a new code'),
);
useSensitiveScreen(() => {
    form.current_password = '';
    form.password = '';
    form.password_confirmation = '';
    form.code = '';
    form.confirmed = false;
    expanded.value = '';
});
const rows = computed(() => [
    {
        id: 'name',
        title: 'Display name',
        value: session.value?.user?.displayName || t('Account user'),
        icon: 'account',
    },
    {
        id: 'EMAIL',
        title: 'Email address',
        value: props.page.information.email || t('Not linked'),
        icon: 'mail',
    },
    { id: 'password', title: 'Change password', value: '••••••••', icon: 'lock-keyhole' },
    {
        id: 'sessions',
        title: 'Sign out other devices',
        value: t('Keep this device signed in'),
        icon: 'monitor-x',
    },
]);
const verification = computed(() =>
    t(
        (
            {
                APPROVED: 'Verified',
                PENDING: 'Under review',
                RESUBMISSION_REQUIRED: 'Action required',
                REJECTED: 'Verification could not be approved',
            } as Record<string, string>
        )[props.page.kycStatus] ?? 'Not verified',
    ),
);
function expand(id: string) {
    if (action.pending.value) return;
    expanded.value = expanded.value === id ? '' : id;
    action.errors.value = {};
    form.current_password = '';
    form.password = '';
    form.password_confirmation = '';
    form.confirmed = false;
}
function newContact() {
    editing.value = true;
    form.request_id = requestId();
    action.errors.value = {};
    form.code = '';
    form.confirmed = false;
}
async function submit() {
    let path = '',
        data: Record<string, unknown> = {};
    if (expanded.value === 'name') {
        path = '/account/information/name';
        data = { display_name: form.display_name };
    } else if (expanded.value === 'EMAIL') {
        if (challenge.value && !editing.value) {
            path = '/account/information/contacts/' + challenge.value.id + '/verify';
            data = { code: form.code, confirmed: form.confirmed };
        } else {
            path = '/account/information/contacts';
            data = {
                channel: 'EMAIL',
                new_contact: form.new_contact,
                current_password: form.current_password,
                request_id: form.request_id,
            };
        }
    } else if (expanded.value === 'password') {
        path = '/account/security/password';
        data = {
            current_password: form.current_password,
            password: form.password,
            password_confirmation: form.password_confirmation,
        };
    } else {
        path = '/account/security/sessions/revoke';
        data = { current_password: form.current_password, confirmed: form.confirmed };
    }
    await action.submit(path, data, {
        navigate: false,
        success: async () => {
            form.current_password = '';
            form.password = '';
            form.password_confirmation = '';
            form.code = '';
            form.confirmed = false;
            editing.value = false;
            await bootstrap();
            emit('reload');
        },
    });
}
</script>
<template>
    <PageShell :title="t('Account and security')" back="/account" active="account"
        ><view class="security-content"
            ><view class="verification-row" @click="go('/kyc?from=account-security')"
                ><UiIcon name="scan-face" :size="20" /><view class="grow"
                    ><text class="block">{{ t('Identity verification') }}</text
                    ><text class="muted small">{{ verification }}</text></view
                ><text class="green small">{{
                    t(
                        ['NOT_SUBMITTED', 'RESUBMISSION_REQUIRED'].includes(page.kycStatus)
                            ? 'Go to verification'
                            : 'View details',
                    )
                }}</text
                ><UiIcon name="chevron-right" :size="16" /></view
            ><view class="security-card"
                ><view v-for="item in rows" :key="item.id" class="security-section"
                    ><button
                        class="security-toggle"
                        :disabled="
                            !['password', 'sessions'].includes(item.id) && !page.information.canEdit
                        "
                        :aria-expanded="expanded === item.id"
                        @click="expand(item.id)"
                    >
                        <UiIcon :name="item.icon" :size="20" /><view class="grow"
                            ><text class="row-label">{{ t(item.title) }}</text
                            ><text class="row-value">{{ item.value }}</text></view
                        ><UiIcon name="chevron-down" :size="16" />
                    </button>
                    <form v-if="expanded === item.id" class="security-form" @submit="submit">
                        <FormErrors :errors="action.errors.value" /><template
                            v-if="item.id === 'name'"
                            ><FormField
                                v-model="form.display_name"
                                :label="t('Display name')"
                                :maxlength="80"
                            /><button
                                class="primary wide"
                                form-type="submit"
                                :disabled="action.pending.value"
                            >
                                {{ t('Save name') }}
                            </button></template
                        ><template v-else-if="item.id === 'EMAIL'"
                            ><template v-if="challenge && !editing"
                                ><text class="copy"
                                    >{{ t('New login contact') }}: {{ challenge.destination }}</text
                                ><text class="copy muted">{{
                                    t(
                                        challenge.deliveryUncertain
                                            ? 'Delivery is not confirmed. If a code arrives, use it here; wait until it expires before resending.'
                                            : 'Enter the verification code sent to your new email address.',
                                    )
                                }}</text
                                ><text class="copy muted">{{ remainingLabel }}</text
                                ><FormField
                                    v-model="form.code"
                                    :label="t('Email verification code')"
                                    type="number"
                                    :maxlength="6"
                                /><ConfirmCheck
                                    v-model="form.confirmed"
                                    :label="
                                        t(
                                            'I confirm replacing this login contact. The old contact will no longer work for login.',
                                        )
                                    "
                                /><button
                                    class="primary wide"
                                    form-type="submit"
                                    :disabled="
                                        action.pending.value || !form.confirmed || remaining === 0
                                    "
                                >
                                    {{ t('Confirm contact change') }}</button
                                ><button
                                    class="text-button"
                                    :disabled="action.pending.value || resendRemaining > 0"
                                    @click="newContact"
                                >
                                    {{ resendLabel }}</button
                                ><button
                                    class="text-button"
                                    :disabled="action.pending.value"
                                    @click="newContact"
                                >
                                    {{ t('Change contact details') }}
                                </button></template
                            ><template v-else
                                ><text class="copy muted">{{
                                    t(
                                        'Verify your current password, then enter the code sent to your new email address to save the change.',
                                    )
                                }}</text
                                ><FormField
                                    v-model="form.new_contact"
                                    :label="t('New email address')"
                                    :disabled="action.pending.value"
                                    @update:model-value="form.request_id = requestId()"
                                /><FormField
                                    v-model="form.current_password"
                                    :label="t('Current password')"
                                    password
                                    :maxlength="1024"
                                /><button
                                    class="primary wide"
                                    form-type="submit"
                                    :disabled="action.pending.value"
                                >
                                    {{
                                        t(
                                            action.pending.value
                                                ? 'Sending…'
                                                : 'Send email verification code',
                                        )
                                    }}
                                </button></template
                            ></template
                        ><template v-else
                            ><text class="copy muted">{{
                                t(
                                    item.id === 'password'
                                        ? 'Changing your password requires other devices to sign in again. This device stays signed in.'
                                        : 'Other devices will be blocked on their next request. Your password and administrator login are unchanged.',
                                )
                            }}</text
                            ><FormField
                                v-model="form.current_password"
                                :label="t('Current password')"
                                password
                                :maxlength="1024"
                            /><template v-if="item.id === 'password'"
                                ><FormField
                                    v-model="form.password"
                                    :label="t('New password')"
                                    :description="t('At least 6 characters.')"
                                    password
                                    :maxlength="1024" /><FormField
                                    v-model="form.password_confirmation"
                                    :label="t('Confirm new password')"
                                    password
                                    :maxlength="1024" /></template
                            ><ConfirmCheck
                                v-else
                                v-model="form.confirmed"
                                :label="
                                    t('I confirm signing out all other devices for this account.')
                                "
                            /><button
                                class="primary wide"
                                form-type="submit"
                                :disabled="
                                    action.pending.value ||
                                    (item.id === 'sessions' && !form.confirmed)
                                "
                            >
                                {{
                                    t(
                                        item.id === 'password'
                                            ? 'Change password'
                                            : 'Sign out other devices',
                                    )
                                }}
                            </button></template
                        >
                    </form></view
                ></view
            ></view
        ></PageShell
    >
</template>
<style scoped>
.security-content {
    max-width: 672px;
    margin: auto;
}
.verification-row {
    display: flex;
    gap: 12px;
    align-items: center;
    min-height: 60px;
    padding: 12px 16px;
    border: 1px solid #e2e7e4;
    border-radius: 14px;
    background: white;
    font-size: 14px;
    margin-bottom: 24px;
}
.grow {
    min-width: 0;
    flex: 1;
}
.block {
    display: block;
}
.small {
    font-size: 12px;
}
.security-card {
    overflow: hidden;
    border: 1px solid #e2e7e4;
    border-radius: 24px;
    background: white;
}
.security-section {
    border-bottom: 1px solid #e2e7e4;
}
.security-section:last-child {
    border: 0;
}
.security-toggle {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 96px;
    padding: 20px;
    width: 100%;
    text-align: left;
    background: none;
    border-radius: 0;
    line-height: 24px;
}
.security-toggle[disabled] {
    opacity: 0.5;
}
.row-label {
    display: block;
    font-size: 14px;
    color: #68736e;
}
.row-value {
    display: block;
    margin-top: 4px;
    font-size: 16px;
    font-weight: 500;
    overflow-wrap: anywhere;
}
.security-form {
    padding: 0 20px 24px;
}
.copy {
    display: block;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 16px;
}
.wide {
    width: 100%;
    border-radius: 999px;
    padding: 10px 20px;
    min-height: 44px;
    font-size: 14px;
    line-height: 24px;
}
.text-button {
    width: 100%;
    background: none;
    font-size: 14px;
    padding: 10px;
    line-height: 24px;
    margin-top: 8px;
}
.text-button[disabled] {
    opacity: 0.5;
}
</style>
