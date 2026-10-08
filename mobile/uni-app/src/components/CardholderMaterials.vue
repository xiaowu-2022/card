<script setup lang="ts">
import { ref } from 'vue';
import CardholderFields from './CardholderFields.vue';
import FormErrors from './FormErrors.vue';
import { t } from '../lib/i18n';
import { ApiError, request } from '../lib/api';
import { requestId, explainError } from '../lib/client';
import { useSensitiveScreen } from '../lib/sensitive';
import { session } from '../lib/session';
const props = defineProps<{
        productId: string;
        formFactor: string;
        updateRequestId?: string;
        initial?: Record<string, string>;
        birthDateRequired?: boolean;
    }>(),
    emit = defineEmits<{ added: []; busy: [value: boolean] }>();
const fields = ref<Record<string, string>>({
        legal_first_name: '', legal_last_name: '',
        email: session.value?.user?.email ?? '', mobile: '', mobile_country_code: 'CN',
        ...props.initial,
    }),
    busy = ref(false),
    uncertain = ref(false),
    errors = ref<Record<string, string>>({});
let id = requestId();
useSensitiveScreen(() => {
    for (const key of Object.keys(fields.value)) fields.value[key] = '';
}, { retainUntilUnmount: true });
async function submit() {
    if (busy.value) return;
    busy.value = true;
    emit('busy', true);
    errors.value = {};
    try {
        uncertain.value = true;
        await request(
            '/client/cards/cardholder',
            'POST',
            {
                ...fields.value,
                card_product_id: props.productId,
                form_factor: props.formFactor,
                request_id: props.updateRequestId ?? id,
            },
        );
        uncertain.value = false;
        emit('added');
    } catch (error) {
        errors.value = explainError(error);
        if (error instanceof ApiError && error.status === 422) {
            uncertain.value = false;
            const raw = error.payload?.errors?.form;
            const reason = Array.isArray(raw) ? raw[0] : raw;
            if (reason === 'The cardholder could not be added. Check the details and try again.')
                id = requestId();
        }
    } finally {
        busy.value = false;
        emit('busy', false);
    }
}
</script>
<template>
    <form class="cardholder-materials" @submit="submit">
        <CardholderFields :birth-date-required="birthDateRequired" v-model="fields" :disabled="busy || uncertain" :errors="errors"
        /><FormErrors :errors="errors" /><button
            class="primary submit"
            form-type="submit"
            :disabled="busy"
        >
            {{
                t(
                    busy
                        ? 'Submitting…'
                        : updateRequestId
                          ? 'Resubmit details'
                          : 'Submit cardholder materials',
                )
            }}
        </button>
    </form>
</template>
<style scoped>
.hint {
    display: block;
    font-size: 12px;
    line-height: 20px;
}
.submit {
    width: 100%;
    margin-top: 20px;
    font-size: 14px;
}
.documents {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 20px;
}
@media (min-width: 640px) {
    .documents {
        grid-template-columns: 1fr 1fr;
    }
}
</style>
