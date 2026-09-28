<script setup lang="ts">
import { computed, ref } from 'vue';
import CardholderFields from './CardholderFields.vue';
import FormField from './FormField.vue';
import SelectField from './SelectField.vue';
import UploadField from './UploadField.vue';
import FormErrors from './FormErrors.vue';
import { t, errorMessage } from '../lib/i18n';
import { ApiError, request, upload } from '../lib/api';
import { requestId, explainError } from '../lib/client';
import { useSensitiveScreen } from '../lib/sensitive';
import { countries, useRegions } from '../lib/card-geography';
import { cardholderErrors, type CardholderValidationData } from '../lib/cardholder-validation';
const props = defineProps<{
        productId: string;
        formFactor: string;
        updateRequestId?: string;
        initial?: Record<string, string>;
    }>(),
    emit = defineEmits<{ added: []; busy: [value: boolean] }>();
const fields = ref<Record<string, string>>({
        cardholder_name_abbreviation: '',
        legal_first_name: '',
        legal_last_name: '',
        date_of_birth: '',
        email: '',
        mobile: '',
        mobile_country_code: 'CN',
        nationality_country_code: '',
        residential_address: '',
        residential_city: '',
        residential_state: '',
        residential_country_code: '',
        residential_postal_code: '',
        document_type: 'id_card',
        ...props.initial,
    }),
    front = ref(''),
    back = ref(''),
    busy = ref(false),
    uncertain = ref(false),
    errors = ref<Record<string, string>>({});
let id = requestId();
const country = computed(() => fields.value.residential_country_code),
    regions = useRegions(country);
useSensitiveScreen(() => {
    front.value = '';
    back.value = '';
    for (const key of Object.keys(fields.value)) fields.value[key] = '';
});
async function metadata(path: string) {
    if (!path) return null;
    // H5 getImageInfo returns dimensions but no MIME type. Inspect the local
    // selected blob after image decoding; the server still validates the upload.
    // #ifdef H5
    if (!path.startsWith('blob:')) throw new Error('Invalid selected image');
    const blob = await (await fetch(path)).blob();
    await new Promise<UniApp.GetImageInfoSuccessData>((resolve, reject) =>
        uni.getImageInfo({ src: path, success: resolve, fail: reject }),
    );
    const bytes = new Uint8Array(await blob.slice(0, 8).arrayBuffer());
    const png = [137, 80, 78, 71, 13, 10, 26, 10].every((byte, i) => bytes[i] === byte);
    const jpeg = bytes[0] === 255 && bytes[1] === 216 && bytes[2] === 255;
    return { size: blob.size, type: png ? 'image/png' : jpeg ? 'image/jpeg' : 'unsupported' };
    // #endif
    // #ifndef H5
    const [file, info] = await Promise.all([
        new Promise<{ size: number }>((resolve, reject) =>
            uni.getFileInfo({ filePath: path, success: resolve, fail: reject }),
        ),
        new Promise<UniApp.GetImageInfoSuccessData>((resolve, reject) =>
            uni.getImageInfo({ src: path, success: resolve, fail: reject }),
        ),
    ]);
    return {
        size: file.size,
        type:
            info.type === 'jpg' || info.type === 'jpeg'
                ? 'image/jpeg'
                : info.type === 'png'
                  ? 'image/png'
                  : 'unsupported',
    };
    // #endif
}
async function submit() {
    if (busy.value || !regions.data.value) return;
    busy.value = true;
    emit('busy', true);
    errors.value = {};
    try {
        const validation = {
            ...fields.value,
            front: await metadata(front.value),
            back: await metadata(back.value),
        } as CardholderValidationData;
        const invalid = cardholderErrors(validation, { countries, regions: regions.data.value });
        if (Object.keys(invalid).length) {
            errors.value = Object.fromEntries(
                Object.entries(invalid).map(([key, value]) => [key, errorMessage(value)]),
            );
            return;
        }
        uncertain.value = true;
        await upload(
            '/client/cards/cardholder',
            {
                ...fields.value,
                card_product_id: props.productId,
                form_factor: props.formFactor,
                request_id: props.updateRequestId ?? id,
            },
            [
                { name: 'front', path: front.value },
                ...(back.value ? [{ name: 'back', path: back.value }] : []),
            ],
        );
        uncertain.value = false;
        front.value = '';
        back.value = '';
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
        <FormField
            v-if="formFactor === 'physical_card'"
            v-model="fields.cardholder_name_abbreviation"
            :label="t('Name on card (FIRST/LAST)')"
            :maxlength="26"
            :disabled="busy || uncertain"
        /><CardholderFields v-model="fields" :disabled="busy || uncertain" :errors="errors"
            ><SelectField
                v-model="fields.document_type"
                :label="t('Document type')"
                :options="[
                    { value: 'id_card', label: t('National identity card') },
                    { value: 'passport', label: t('Passport') },
                    { value: 'resident_permit', label: t('Residence permit') },
                ]"
                :disabled="busy || uncertain"
            /><view class="documents"
                ><UploadField
                    v-model="front"
                    :label="t('Document front / passport photo page')"
                    :max-mb="6"
                    jpeg-png-only
                    :disabled="busy || uncertain" /><UploadField
                    v-model="back"
                    :label="t('Document back (optional for passports)')"
                    :max-mb="6"
                    jpeg-png-only
                    :disabled="busy || uncertain" /></view
            ><text class="hint muted">{{
                t(
                    'PNG or JPEG, up to 6 MB per document. Only submit documents you are authorized to use.',
                )
            }}</text></CardholderFields
        ><FormErrors :errors="errors" /><button
            class="primary submit"
            form-type="submit"
            :disabled="busy || !regions.data.value || regions.failed.value"
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
