<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useFeedbackField } from '../lib/form-feedback';
import FormField from './FormField.vue';
import SelectField from './SelectField.vue';
import { countryOptions } from '../lib/card-geography';
import { t, locale } from '../lib/i18n';
const props = defineProps<{
        modelValue: Record<string, string>;
        disabled?: boolean;
        editing?: boolean;
        compact?: boolean;
        fixedCallingCode?: boolean;
        hideTitle?: boolean;
        birthDateRequired?: boolean;
        errors?: Record<string, string>;
    }>(),
    emit = defineEmits<{ 'update:modelValue': [value: Record<string, string>] }>();
watch(() => props.modelValue.mobile_country_code, (value) => {
    if (props.fixedCallingCode && value !== 'CN') set('mobile_country_code', 'CN');
}, { immediate: true });
const birthElement = ref();
useFeedbackField(() => ['date_of_birth'], () => birthElement.value);
const phones = computed(() => countryOptions(locale.value, true));
function changeBirthDate(event: { detail: { value: string } }) {
    set('date_of_birth', event.detail.value);
}
function set(key: string, value: string) {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}
</script>
<template>
    <view class="holder-fields" :class="{ compact }"
        ><text v-if="!hideTitle" class="section-title">{{ t('Card user') }}</text
        ><view class="grid"
            ><FormField
                name="legal_last_name"
                :model-value="modelValue.legal_last_name ?? ''"
                :label="t('Last name')"
                :maxlength="40"
                :disabled="disabled"
                :error="errors?.legal_last_name"
                @update:model-value="(v) => set('legal_last_name', v)"
            /><FormField
                name="legal_first_name"
                :model-value="modelValue.legal_first_name ?? ''"
                :label="t('First name')"
                :maxlength="40"
                :disabled="disabled"
                :error="errors?.legal_first_name"
                @update:model-value="(v) => set('legal_first_name', v)"
            /><view
                v-if="birthDateRequired || errors?.date_of_birth || modelValue.date_of_birth"
                ref="birthElement" class="birth" data-field="date_of_birth"
            >
                <text class="label">{{ t('Date of birth') }}</text>
                <picker
                    mode="date"
                    :value="modelValue.date_of_birth || '1990-01-01'"
                    :disabled="disabled"
                    @change="changeBirthDate"
                >
                    <view class="date">{{ modelValue.date_of_birth || t('Date of birth') }}</view>
                </picker>
                <text v-if="errors?.date_of_birth" class="error">{{
                    errors.date_of_birth
                }}</text> </view
            ><view
                ><FormField
                    name="email"
                :model-value="modelValue.email ?? ''"
                    :label="t('Email')"
                    :maxlength="40"
                    :disabled="disabled"
                    :error="errors?.email"
                    @update:model-value="(v) => set('email', v)"
                /><text v-if="!compact" class="hint verification-notice">{{
                    t(
                        'Your email receives purchase verification codes. Please ensure you can receive these emails.',
                    )
                }}</text></view
            ><view
                ><text class="label">{{ t('Mobile number') }}</text
                ><view class="phone"
                    ><view v-if="fixedCallingCode" class="fixed-code">{{ phones.find((option) => option.value === 'CN')?.label ?? '+86' }}</view
                    ><SelectField
                        v-else
                        :model-value="modelValue.mobile_country_code ?? ''"
                        :label="t('Calling code')"
                        :options="phones"
                        searchable
                        :disabled="disabled"
                        @update:model-value="(v) => set('mobile_country_code', v)" /><FormField
                        name="mobile" :aliases="['mobile_country_code']"
                        :model-value="modelValue.mobile ?? ''"
                        :label="t('Phone number')"
                        :maxlength="24"
                        :disabled="disabled"
                        :error="errors?.mobile || errors?.mobile_country_code"
                        @update:model-value="(v) => set('mobile', v)" /></view
                ><text v-if="!compact" class="hint verification-notice">{{
                    t(
                        'Your mobile number receives purchase verification codes. Please ensure it can receive SMS.',
                    )
                }}</text></view
            ></view
        ></view
    >
</template>
<style scoped>
.section-title {
    display: block;
    font-size: 16px;
    font-weight: 600;
    line-height: 24px;
    margin-bottom: 20px;
}
.grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0 20px;
}
.fixed-code {
    display: flex;
    align-items: center;
    box-sizing: border-box;
    min-height: 54px;
    padding: 14px 12px;
    border: 1px solid #e2e7e4;
    border-radius: 12px;
    background: white;
    white-space: nowrap;
}
.phone {
    display: grid;
    grid-template-columns: 110px minmax(0, 1fr);
    gap: 8px;
}
.phone :deep(.form-label),
.phone :deep(.select-label) {
    display: none;
}
.phone :deep(.select-trigger) {
    min-width: 0;
}
.phone :deep(.select-trigger text) {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.phone :deep(.form-field) {
    margin-bottom: 0;
}
.phone :deep(.select-field) {
    margin-bottom: 0;
}
.label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 8px;
}
.hint {
    display: block;
    font-size: 12px;
    line-height: 20px;
    margin: 8px 0 20px;
}
.verification-notice {
    color: #b42318;
}
.birth {
    margin-bottom: 20px;
}
.date {
    box-sizing: border-box;
    min-height: 54px;
    padding: 14px 12px;
    border: 1px solid #e2e7e4;
    border-radius: 12px;
    background: white;
    font-size: 16px;
    line-height: 24px;
}
.billing {
    border-top: 1px solid #e2e7e4;
    padding-top: 24px;
    margin-top: 24px;
}
.intro {
    display: block;
    font-size: 14px;
    line-height: 22px;
    margin-bottom: 16px;
}
.error {
    font-size: 13px;
    color: #b42318;
}
.retry {
    display: inline;
    padding: 0;
    background: none;
    text-decoration: underline;
    font-size: 13px;
}
@media (min-width: 640px) {
    .grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>

<style scoped>
.compact .section-title {
    font-size: 14px;
    line-height: 20px;
    margin-bottom: 6px;
}
.compact .grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px 12px;
}
.compact .grid > view:not(.form-field) {
    grid-column: 1 / -1;
}
.compact :deep(.form-field),
.compact :deep(.select-field),
.compact .birth {
    margin-bottom: 0;
}
.compact :deep(.form-label),
.compact .label {
    font-size: 13px;
    margin-bottom: 4px;
}
.compact :deep(.form-input),
.compact .date,
.compact .fixed-code,
.compact :deep(.select-trigger) {
    height: 40px;
    min-height: 40px;
    border-radius: 8px;
    padding: 8px 10px;
    box-sizing: border-box;
}
</style>
