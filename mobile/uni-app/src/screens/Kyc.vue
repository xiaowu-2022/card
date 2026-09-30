<script setup lang="ts">
import { useSensitiveScreen } from '../lib/sensitive';
import { computed, reactive, ref } from 'vue';
import { t, locale, dateTime } from '../lib/i18n';
import { useAction } from '../lib/client';
import { photoUrl } from '../lib/api';
import { go } from '../lib/navigation';
import ProcessingOverlay from '../components/ProcessingOverlay.vue';
import type { UploadProgress } from '../lib/api';
import PageShell from '../components/PageShell.vue';
import StatusBanner from '../components/StatusBanner.vue';
import FormErrors from '../components/FormErrors.vue';
import SelectField from '../components/SelectField.vue';
import UploadField from '../components/UploadField.vue';
import countries from '../generated/countries.json';
const props = defineProps<{
    page: {
        kyc: {
            status: string;
            reverificationPending?: boolean;
            reviewMessage: string | null;
            documentType?: string | null;
            documentCountry?: string | null;
            maskedIdentityNumber?: string | null;
            submittedAt?: string | null;
            verifiedAt?: string | null;
            frontUrl?: string | null;
            backUrl?: string | null;
        };
        canSubmit: boolean;
        canReverify?: boolean;
        maxDocumentMb: number;
        backHref: string;
    };
}>();
const emit = defineEmits<{ reload: [] }>();
const action = useAction();
const progress = ref<UploadProgress>({ stage: 'uploading', completed: 0, total: 2 });
const processingMessage = computed(() => progress.value.stage === 'submitting'
    ? t('Recognizing and submitting identity verification…')
    : t('Uploading documents ({{completed}}/{{total}})', { completed: progress.value.completed, total: progress.value.total }));
const reverifying = ref(false);
function reverify() {
    resetFiles();
    form.document_type = props.page.kyc.documentType ?? 'NATIONAL_ID';
    form.document_country = props.page.kyc.documentCountry ?? 'CN';
    reverifying.value = true;
}
const form = reactive({
    document_type: 'NATIONAL_ID',
    document_country: 'CN',
    front: '',
    back: '',
});
const states: Record<string, { title: string; description: string; tone: string }> = {
    NOT_SUBMITTED: {
        title: 'Identity verification',
        description: 'Verify your identity to continue.',
        tone: 'neutral',
    },
    PENDING: {
        title: 'Verification under review',
        description: 'Your information has been submitted.',
        tone: 'pending',
    },
    APPROVED: {
        title: 'Identity verified',
        description: 'Your identity verification is complete.',
        tone: 'success',
    },
    REJECTED: {
        title: 'Verification could not be approved',
        description: 'Please contact support for assistance.',
        tone: 'warning',
    },
    RESUBMISSION_REQUIRED: {
        title: 'Action required',
        description: 'Please submit a new set of identity documents.',
        tone: 'warning',
    },
};
const state = computed(() => states[props.page.kyc.status] ?? states.NOT_SUBMITTED);
const countryOptions = computed(() => {
    const names = new Intl.DisplayNames([locale.value], { type: 'region' });
    const priority = ['CN', 'HK', 'MO', 'TW'];
    return countries
        .filter((c) => form.document_type === 'PASSPORT' || c.code === 'CN')
        .map((c) => ({
            value: c.code,
            label: names.of(c.code) ?? c.name,
            keywords: [c.name, c.code],
        }))
        .sort((a, b) => {
            const rank = (code: string) => (priority.includes(code) ? priority.indexOf(code) : 4);
            return rank(a.value) - rank(b.value) || a.label.localeCompare(b.label, locale.value);
        });
});
function resetFiles() {
    form.document_country = 'CN';
    form.front = '';
    form.back = '';
}
useSensitiveScreen(
    () => {
        form.front = '';
        form.back = '';
    },
    { retainOnBackground: true },
);
async function submit() {
    if (action.pending.value) return;
    progress.value = { stage: 'uploading', completed: 0, total: form.document_type === 'NATIONAL_ID' ? 2 : 1 };
    const files = [
        { name: 'front', path: form.front },
        ...(form.document_type === 'NATIONAL_ID' ? [{ name: 'back', path: form.back }] : []),
    ].filter((file) => file.path);
    await action.submit(
        '/kyc/applications' +
            (props.page.backHref === '/account/security' ? '?from=account-security' : ''),
        {
            document_type: form.document_type,
            document_country: form.document_country,
            reverify: reverifying.value,
        },
        {
            files,
            onProgress: (value) => { progress.value = value; },
            navigate: false,
            success: () => {
                resetFiles();
                reverifying.value = false;
                emit('reload');
            },
        },
    );
}
</script>
<template>
    <ProcessingOverlay :open="action.pending.value" :message="processingMessage" />
    <PageShell :title="t('Identity verification')" :back="page.backHref" active="account"
        ><StatusBanner
            :title="t(state.title)"
            :description="
                page.kyc.reviewMessage && page.kyc.status === 'RESUBMISSION_REQUIRED'
                    ? page.kyc.reviewMessage
                    : t(state.description)
            "
            :tone="state.tone"
        /><view v-if="page.kyc.status === 'APPROVED' && !reverifying" class="kyc-card">
            <text class="kyc-title">{{ t('Identity verified') }}</text>
            <view class="verified-row"
                ><text>{{ t('Document type') }}</text
                ><text>{{
                    t(
                        page.kyc.documentType === 'PASSPORT'
                            ? 'Passport'
                            : 'Mainland China identity card',
                    )
                }}</text></view
            >
            <view class="verified-row"
                ><text>{{ t('Document country') }}</text
                ><text>{{
                    page.kyc.documentCountry
                        ? new Intl.DisplayNames([locale], { type: 'region' }).of(
                              page.kyc.documentCountry,
                          )
                        : '—'
                }}</text></view
            >
            <view class="verified-row"
                ><text>{{ t('Identity number') }}</text
                ><text>{{ page.kyc.maskedIdentityNumber ?? '—' }}</text></view
            >
            <view class="verified-row"
                ><text>{{ t('Verification time') }}</text
                ><text>{{ page.kyc.verifiedAt ? dateTime(page.kyc.verifiedAt) : '—' }}</text></view
            >
            <view class="document-grid">
                <view
                    ><text class="label">{{
                        t(
                            page.kyc.documentType === 'PASSPORT'
                                ? 'Passport information page'
                                : 'ID front',
                        )
                    }}</text>
                    <image
                        v-if="page.kyc.frontUrl"
                        :src="photoUrl(page.kyc.frontUrl) ?? ''"
                        mode="widthFix"
                        class="verified-photo"
                    />
                    <text v-else class="muted">{{ t('Image unavailable') }}</text>
                </view>
                <view v-if="page.kyc.documentType !== 'PASSPORT'"
                    ><text class="label">{{ t('ID back') }}</text>
                    <image
                        v-if="page.kyc.backUrl"
                        :src="photoUrl(page.kyc.backUrl) ?? ''"
                        mode="widthFix"
                        class="verified-photo"
                    />
                    <text v-else class="muted">{{ t('Image unavailable') }}</text>
                </view>
            </view>
            <button v-if="page.canReverify" class="primary" @click="reverify">{{ t('Verify again') }}</button>
            <text v-if="page.kyc.reverificationPending" class="muted">{{ t('Verification under review') }}</text>
            </view
        ><view v-else-if="page.canSubmit || reverifying" class="kyc-card"
            ><text class="kyc-title">{{
                t(
                    page.kyc.status === 'RESUBMISSION_REQUIRED'
                        ? 'Resubmit documents'
                        : 'Submit identity documents',
                )
            }}</text
            ><view v-if="Object.keys(action.errors.value).length"
                ><FormErrors :errors="action.errors.value" /><view class="error-actions"
                    ><text @click="emit('reload')">{{ t('Refresh status') }}</text
                    ><text @click="go('/support')">{{ t('Online support') }}</text></view
                ></view
            >
            <text v-if="reverifying" class="muted">{{ t('Upload new identity documents. Your current verification remains valid until approval.') }}</text>
            <button v-if="reverifying" class="secondary" :disabled="action.pending.value" @click="reverifying = false; resetFiles()">{{ t('Cancel') }}</button>
            <form @submit="submit">
                <SelectField
                    v-model="form.document_type"
                    :label="t('Document type')"
                    :options="[
                        { value: 'NATIONAL_ID', label: t('Mainland China identity card') },
                        { value: 'PASSPORT', label: t('Passport') },
                    ]"
                    :disabled="action.pending.value"
                    @update:model-value="resetFiles"
                /><SelectField
                    v-model="form.document_country"
                    :label="t('Document country')"
                    :options="countryOptions"
                    :disabled="action.pending.value"
                    searchable
                /><view class="document-grid"
                    ><UploadField
                        v-model="form.front"
                        :label="
                            t(
                                form.document_type === 'PASSPORT'
                                    ? 'Passport information page'
                                    : 'ID front',
                            )
                        "
                        :max-mb="page.maxDocumentMb"
                        :disabled="action.pending.value" /><UploadField
                        v-if="form.document_type === 'NATIONAL_ID'"
                        v-model="form.back"
                        :label="t('ID back')"
                        :max-mb="page.maxDocumentMb"
                        :disabled="action.pending.value" /></view
                ><button
                    class="primary submit-button"
                    form-type="submit"
                    :disabled="
                        action.pending.value ||
                        !form.front ||
                        (form.document_type === 'NATIONAL_ID' && !form.back)
                    "
                >
                    {{ t(action.pending.value ? 'Submitting…' : 'Submit for review') }}
                </button>
            </form></view
        ><text
            v-else-if="['NOT_SUBMITTED', 'RESUBMISSION_REQUIRED'].includes(page.kyc.status)"
            class="muted"
            >{{
                t(
                    'New submissions are currently unavailable. Your account or Tenant may be restricted, or KYC may be disabled.',
                )
            }}</text
        ></PageShell
    >
</template>
<style scoped>
.verified-row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 12px 0;
    font-size: 14px;
}
.verified-row > text:last-child {
    text-align: right;
    overflow-wrap: anywhere;
}
.verified-photo {
    width: 100%;
    display: block;
    border-radius: 12px;
}
.kyc-card {
    border: 1px solid #e2e7e4;
    border-radius: 24px;
    padding: 24px;
    background: white;
}
.kyc-title {
    display: block;
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 24px;
}
.document-grid {
    display: grid;
    gap: 0;
}
.submit-button {
    border-radius: 999px;
    min-height: 44px;
    line-height: 24px;
    padding: 10px 24px;
    width: 100%;
    font-weight: 500;
}
.error-actions {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    font-size: 14px;
    text-decoration: underline;
    margin-bottom: 20px;
}
@media (min-width: 640px) {
    .document-grid {
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }
    .submit-button {
        width: auto;
        display: inline-block;
    }
}
</style>
