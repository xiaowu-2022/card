<script setup lang="ts">
import { computed } from 'vue';
import FormField from './FormField.vue';
import SelectField from './SelectField.vue';
import { countryOptions } from '../lib/card-geography';
import { t, locale } from '../lib/i18n';
const props = defineProps<{
        modelValue: Record<string, string>;
        disabled?: boolean;
        editing?: boolean;
        errors?: Record<string, string>;
    }>(),
    emit = defineEmits<{ 'update:modelValue': [value: Record<string, string>] }>();
const phones = computed(() => countryOptions(locale.value, true));
function set(key: string, value: string) {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}
</script>
<template>
    <view class="holder-fields"
        ><text class="section-title">{{ t('Card user') }}</text
        ><view class="grid"
            ><template
                ><FormField
                    :model-value="modelValue.legal_last_name ?? ''"
                    :label="t('Last name')"
                    :maxlength="40"
                    :disabled="disabled"
                    :error="errors?.legal_last_name"
                    @update:model-value="(v) => set('legal_last_name', v)" /><FormField
                    :model-value="modelValue.legal_first_name ?? ''"
                    :label="t('First name')"
                    :maxlength="40"
                    :disabled="disabled"
                    :error="errors?.legal_first_name"
                    @update:model-value="(v) => set('legal_first_name', v)" /></template
            ><FormField
                :model-value="modelValue.email ?? ''"
                :label="t('Email')"
                :maxlength="40"
                :disabled="disabled"
                :error="errors?.email"
                @update:model-value="(v) => set('email', v)"
            /><view
                ><text class="label">{{ t('Mobile number') }}</text
                ><view class="phone"
                    ><SelectField
                        :model-value="modelValue.mobile_country_code ?? ''"
                        :label="t('Calling code')"
                        :options="phones"
                        searchable
                        :disabled="disabled"
                        @update:model-value="(v) => set('mobile_country_code', v)" /><FormField
                        :model-value="modelValue.mobile ?? ''"
                        :label="t('Phone number')"
                        :maxlength="24"
                        :disabled="disabled"
                        :error="errors?.mobile || errors?.mobile_country_code"
                        @update:model-value="(v) => set('mobile', v)" /></view
                ><text class="hint muted">{{
                    t(
                        'Your mobile number receives purchase verification codes. Please ensure it can receive SMS.',
                    )
                }}</text></view
            ></view
    ></view>
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
