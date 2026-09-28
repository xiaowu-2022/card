<script setup lang="ts">
import { computed } from 'vue';
import FormField from './FormField.vue';
import SelectField from './SelectField.vue';
import { countryOptions, placeOptions, useRegions } from '../lib/card-geography';
import { localityMode } from '../lib/card-locality';
import { t, locale } from '../lib/i18n';
const props = defineProps<{
        modelValue: Record<string, string>;
        disabled?: boolean;
        editing?: boolean;
        errors?: Record<string, string>;
    }>(),
    emit = defineEmits<{ 'update:modelValue': [value: Record<string, string>] }>();
const country = computed(() => props.modelValue.residential_country_code ?? ''),
    regions = useRegions(country),
    countries = computed(() => countryOptions(locale.value)),
    phones = computed(() => countryOptions(locale.value, true)),
    states = computed(() => placeOptions(regions.data.value ?? [], locale.value)),
    cities = computed(() =>
        placeOptions(
            regions.data.value?.find((r) => r.value === props.modelValue.residential_state)
                ?.cities ?? [],
            locale.value,
        ),
    );
const stateMode = computed(() =>
        localityMode(
            regions.data.value,
            props.modelValue.residential_state ?? '',
            'residential_state',
        ),
    ),
    cityMode = computed(() =>
        localityMode(
            regions.data.value,
            props.modelValue.residential_state ?? '',
            'residential_city',
        ),
    );
function set(key: string, value: string) {
    const next = { ...props.modelValue, [key]: value };
    if (key === 'residential_country_code') {
        next.residential_state = '';
        next.residential_city = '';
    }
    if (key === 'residential_state') next.residential_city = '';
    emit('update:modelValue', next);
}
defineExpose({ regions });
</script>
<template>
    <view class="holder-fields"
        ><text v-if="editing" class="intro muted">{{
            t(
                'Saved information is shown below. Approved names cannot be changed. This does not replace the cardholder.',
            )
        }}</text
        ><text v-else class="section-title">{{ t('Card user') }}</text
        ><view class="grid"
            ><template v-if="!editing"
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
            ><SelectField
                :model-value="modelValue.nationality_country_code ?? ''"
                :label="t('Nationality')"
                :options="countries"
                searchable
                :disabled="disabled"
                @update:model-value="(v) => set('nationality_country_code', v)"
            /><view class="birth"
                ><text class="label">{{ t('Date of birth') }}</text
                ><picker
                    mode="date"
                    :value="modelValue.date_of_birth"
                    :disabled="disabled"
                    @change="set('date_of_birth', $event.detail.value)"
                    ><view class="date">{{
                        modelValue.date_of_birth || t('Please select')
                    }}</view></picker
                ><text v-if="errors?.date_of_birth" class="error">{{
                    errors.date_of_birth
                }}</text></view
            ></view
        ><slot /><view class="billing"
            ><text class="section-title">{{ t('Billing address') }}</text
            ><SelectField
                :model-value="modelValue.residential_country_code ?? ''"
                :label="t('Country / region')"
                :options="countries"
                searchable
                :disabled="disabled"
                @update:model-value="(v) => set('residential_country_code', v)" /><view class="grid"
                ><FormField
                    v-if="stateMode === 'manual'"
                    :model-value="modelValue.residential_state ?? ''"
                    :label="t('State / province')"
                    :maxlength="50"
                    :placeholder="t('Enter the actual location name')"
                    :disabled="disabled"
                    :error="errors?.residential_state"
                    @update:model-value="(v) => set('residential_state', v)" /><SelectField
                    v-else
                    :model-value="modelValue.residential_state ?? ''"
                    :label="t('State / province')"
                    :options="states"
                    searchable
                    :disabled="disabled || stateMode === 'disabled'"
                    @update:model-value="(v) => set('residential_state', v)" /><FormField
                    v-if="cityMode === 'manual'"
                    :model-value="modelValue.residential_city ?? ''"
                    :label="t('City')"
                    :maxlength="50"
                    :placeholder="t('Enter the actual location name')"
                    :disabled="disabled"
                    :error="errors?.residential_city"
                    @update:model-value="(v) => set('residential_city', v)" /><SelectField
                    v-else
                    :model-value="modelValue.residential_city ?? ''"
                    :label="t('City')"
                    :options="cities"
                    searchable
                    :disabled="disabled || cityMode === 'disabled'"
                    @update:model-value="(v) => set('residential_city', v)" /></view
            ><text
                v-if="country && !regions.data.value && !regions.failed.value"
                class="intro muted"
                >{{ t('Loading regions…') }}</text
            ><text
                v-if="
                    regions.data.value &&
                    (!states.length || (modelValue.residential_state && !cities.length))
                "
                class="intro muted"
                >{{
                    t('Location data is incomplete here. Enter the actual state or city name.')
                }}</text
            ><view v-if="regions.failed.value" class="error"
                ><text>{{ t('Location options could not be loaded.') }}</text
                ><button class="retry" @click="regions.retry">{{ t('Try again') }}</button></view
            ><FormField
                :model-value="modelValue.residential_address ?? ''"
                :label="t('Detailed address')"
                :disabled="disabled"
                :error="errors?.residential_address"
                @update:model-value="(v) => set('residential_address', v)" /><FormField
                :model-value="modelValue.residential_postal_code ?? ''"
                :label="t('Postal code')"
                :disabled="disabled"
                :error="errors?.residential_postal_code"
                @update:model-value="(v) => set('residential_postal_code', v)" /></view
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
