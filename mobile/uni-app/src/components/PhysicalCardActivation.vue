<script setup lang="ts">
import { computed, ref } from 'vue';
import FormField from './FormField.vue';
import FormErrors from './FormErrors.vue';
import ConfirmCheck from './ConfirmCheck.vue';
import { t } from '../lib/i18n';
import { ApiError, request } from '../lib/api';
import { requestId, explainError } from '../lib/client';
import { useSensitiveScreen } from '../lib/sensitive';
const props = defineProps<{ cardId: string; status?: string | null }>(),
    emit = defineEmits<{ reload: [] }>();
const open = ref(false),
    awaiting = ref(false),
    key = ref(requestId()),
    expiry = ref(''),
    pin = ref(''),
    confirmation = ref(''),
    password = ref(''),
    confirmed = ref(false),
    busy = ref(false),
    message = ref(''),
    errors = ref<Record<string, string>>({});
const blocked = computed(
    () => awaiting.value || ['PROCESSING', 'UNKNOWN', 'SUCCEEDED'].includes(props.status ?? ''),
);
function clear() {
    pin.value = '';
    confirmation.value = '';
    password.value = '';
    confirmed.value = false;
}
useSensitiveScreen(() => {
    clear();
    open.value = false;
});
async function submit(sync = false) {
    if (busy.value || (!sync && (!confirmed.value || blocked.value))) return;
    busy.value = true;
    message.value = '';
    errors.value = {};
    try {
        if (!sync) awaiting.value = true;
        const result = await request<{ status: string }>(
            '/client/cards/' + props.cardId + (sync ? '/activation/sync' : '/activate'),
            'POST',
            sync
                ? {}
                : {
                      request_id: key.value,
                      expiration_date: expiry.value,
                      pin: pin.value,
                      pin_confirmation: confirmation.value,
                      current_password: password.value,
                      confirmed: confirmed.value,
                  },
        );
        message.value = t(
            result.status === 'SUCCEEDED'
                ? 'Physical card activated.'
                : result.status === 'FAILED'
                  ? 'Activation was rejected.'
                  : 'Activation is awaiting confirmation. Refresh card status.',
        );
        awaiting.value = !['FAILED', 'NONE'].includes(result.status);
        if (result.status === 'FAILED') key.value = requestId();
        open.value = false;
        emit('reload');
    } catch (e) {
        errors.value = explainError(e);
        if (e instanceof ApiError && e.status === 422) awaiting.value = false;
    } finally {
        clear();
        busy.value = false;
    }
}
</script>
<template>
    <view class="activation"
        ><view class="buttons"
            ><button
                class="primary"
                :disabled="busy || blocked"
                @click="
                    clear();
                    open = !open;
                "
            >
                {{ t('Activate physical card') }}</button
            ><button class="primary" :disabled="busy" @click="submit(true)">
                {{ t('Refresh card status') }}
            </button></view
        >
        <form v-if="open" @submit="submit()">
            <FormField
                v-model="expiry"
                :label="t('Card expiry (MM/YY)')"
                :maxlength="5"
                :disabled="busy"
            /><FormField
                v-model="pin"
                :label="t('Card PIN')"
                password
                :maxlength="8"
                :disabled="busy"
            /><FormField
                v-model="confirmation"
                :label="t('Confirm card PIN')"
                password
                :maxlength="8"
                :disabled="busy"
            /><FormField
                v-model="password"
                :label="t('Current password')"
                password
                :disabled="busy"
            /><ConfirmCheck
                v-model="confirmed"
                :label="t('I have received this physical card and want to activate it.')"
                :disabled="busy"
            /><button
                class="primary"
                form-type="submit"
                :disabled="
                    busy || blocked || !confirmed || !password || !pin || pin !== confirmation
                "
            >
                {{ t('Confirm activation') }}
            </button>
        </form>
        <text v-if="message" class="message">{{ message }}</text
        ><FormErrors :errors="errors"
    /></view>
</template>
<style scoped>
.activation {
    padding: 16px;
    font-size: 14px;
    line-height: 22px;
}
.buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 12px;
}
.activation button {
    font-size: 14px;
    line-height: 24px;
    min-height: 44px;
    padding: 10px 16px;
    margin: 0;
}
.message {
    display: block;
    margin-top: 12px;
}
</style>
