<script setup lang="ts">
import { computed, ref } from 'vue';
import FormField from './FormField.vue';
import FormErrors from './FormErrors.vue';
import { t } from '../lib/i18n';
import { ApiError, request } from '../lib/api';
import { requestId, explainError } from '../lib/client';
import { useSensitiveScreen } from '../lib/sensitive';
const props = defineProps<{
        applicationId: string;
        saved?: { id: string; status: string } | null;
    }>(),
    emit = defineEmits<{ ready: [id: string, summary: string]; busy: [value: boolean] }>();
const labels = [
    ['recipientFirstName', 'Recipient first name', 40],
    ['recipientLastName', 'Recipient last name', 40],
    ['mobilePrefix', 'Phone country code', 8],
    ['mobile', 'Phone number', 11],
    ['country', 'Country code', 2],
    ['state', 'State or province', 50],
    ['city', 'City', 50],
    ['addressLine1', 'Address line 1', 50],
    ['addressLine2', 'Address line 2', 50],
    ['addressLine3', 'Address line 3', 50],
    ['postalCode', 'Postal code', 12],
] as const;
const fields = ref<Record<string, string>>({}),
    state = ref(props.saved?.status === 'FAILED' ? '' : (props.saved?.status ?? '')),
    id = ref(props.saved?.id ?? ''),
    busy = ref(false),
    uncertain = ref(false),
    errors = ref<Record<string, string>>({}),
    key = ref(requestId());
const summary = computed(() =>
        labels
            .map(([name]) => fields.value[name])
            .filter(Boolean)
            .join(', '),
    ),
    locked = computed(() => ['READY', 'UNKNOWN', 'SUBMITTING'].includes(state.value));
let generation = 0;
useSensitiveScreen(() => {
    generation++;
    fields.value = {};
}, { retainUntilUnmount: true });
async function send(inspect = false) {
    if (busy.value) return;
    const run = generation;
    busy.value = true;
    emit('busy', true);
    errors.value = {};
    try {
        if (!inspect) uncertain.value = true;
        const result = await request<{
            id: string;
            status: string;
            fields?: Record<string, string>;
        }>(
            inspect
                ? '/client/cards/recipients/' + id.value + '/inspect'
                : '/client/cards/recipients',
            'POST',
            inspect
                ? {}
                : {
                      ...fields.value,
                      request_id: key.value,
                      cardholder_application_id: props.applicationId,
                  },
        );
        if (run !== generation) return;
        state.value = result.status;
        id.value = result.id;
        uncertain.value = false;
        if (inspect) fields.value = result.fields ?? {};
        if (result.status === 'READY') emit('ready', result.id, summary.value);
        if (result.status === 'FAILED')
            errors.value = {
                form: t('Recipient details were rejected. Close and reopen to correct them.'),
            };
    } catch (e) {
        if (run === generation) {
            errors.value = explainError(e);
            if (e instanceof ApiError && e.status === 422) uncertain.value = false;
        }
    } finally {
        busy.value = false;
        emit('busy', false);
    }
}
function correct() {
    state.value = '';
    key.value = requestId();
    errors.value = {};
}
</script>
<template>
    <view class="recipient"
        ><template v-if="locked"
            ><text>{{
                t(
                    state === 'READY'
                        ? 'Recipient saved for this application.'
                        : 'Recipient creation could not be confirmed. Do not submit again.',
                )
            }}</text
            ><text class="summary">{{ summary }}</text
            ><button class="primary" :disabled="busy" @click="send(true)">
                {{ t('Review recipient and refresh status') }}
            </button></template
        ><template v-else
            ><text class="heading">{{ t('Physical card recipient') }}</text
            ><view class="grid"
                ><FormField
                    v-for="[name, label, max] in labels"
                    :key="name"
                    v-model="fields[name]"
                    :label="t(label)"
                    :maxlength="max"
                    :disabled="busy || uncertain || state === 'FAILED'" /></view
            ><button v-if="state === 'FAILED'" class="secondary" @click="correct">
                {{ t('Correct recipient details') }}</button
            ><button class="primary" :disabled="busy || state === 'FAILED'" @click="send()">
                {{ t('Save recipient') }}
            </button></template
        ><FormErrors :errors="errors"
    /></view>
</template>
<style scoped>
.recipient {
    padding: 16px;
    border: 1px solid #e2e7e4;
    border-radius: 12px;
    font-size: 14px;
    line-height: 22px;
}
.heading {
    display: block;
    font-weight: 600;
    margin-bottom: 12px;
}
.grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0 12px;
}
.summary {
    display: block;
    margin: 8px 0;
    overflow-wrap: anywhere;
}
.recipient button {
    font-size: 14px;
    line-height: 24px;
    min-height: 44px;
    padding: 10px 16px;
    margin-top: 12px;
}
@media (min-width: 640px) {
    .grid {
        grid-template-columns: 1fr 1fr;
    }
}
</style>
