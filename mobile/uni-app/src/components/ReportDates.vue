<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from './Modal.vue';
import UiIcon from './UiIcon.vue';
import { t } from '../lib/i18n';
import type { ReportPeriod } from '../lib/promotion-report';
const props = defineProps<{ period: ReportPeriod; allowAll?: boolean }>(),
    emit = defineEmits<{ change: [from: string | null, to: string | null] }>();
const open = ref(false),
    custom = ref(false),
    from = ref(props.period.dateFrom ?? props.period.today),
    to = ref(props.period.dateTo ?? props.period.today);
watch(
    () => props.period,
    (p) => {
        from.value = p.dateFrom ?? p.today;
        to.value = p.dateTo ?? p.today;
    },
);
function apply(a: string | null, b: string | null) {
    emit('change', a, b);
    open.value = false;
    custom.value = false;
}
</script>
<template>
    <button class="date-button" @click="open = true">
        <UiIcon name="calendar-days" :size="16" /><text>{{
            period.dateFrom
                ? period.dateFrom === period.dateTo
                    ? period.dateFrom
                    : period.dateFrom + ' — ' + period.dateTo
                : t('All dates')
        }}</text></button
    ><Modal :open="open" :title="t('Date range')" @close="open = false"
        ><view class="presets"
            ><button
                v-if="allowAll"
                :class="period.dateFrom ? 'secondary' : 'primary'"
                @click="apply(null, null)"
            >
                {{ t('All dates') }}</button
            ><button
                v-for="preset in [
                    ['1', 'Today'],
                    ['7', 'Last 7 days'],
                    ['30', 'Last 30 days'],
                ]"
                :key="preset[0]"
                :class="
                    period.dateFrom === period.presets[preset[0]] && period.dateTo === period.today
                        ? 'primary'
                        : 'secondary'
                "
                @click="apply(period.presets[preset[0]], period.today)"
            >
                {{ t(preset[1]) }}</button
            ><button class="text-button" @click="custom = !custom">
                {{ t('Custom dates') }}
            </button></view
        ><view v-if="custom" class="date-fields"
            ><view
                ><text>{{ t('Start date') }}</text
                ><picker mode="date" :value="from" :end="to" @change="from = $event.detail.value"
                    ><view class="picker-value">{{ from }}</view></picker
                ></view
            ><view
                ><text>{{ t('End date') }}</text
                ><picker mode="date" :value="to" :start="from" @change="to = $event.detail.value"
                    ><view class="picker-value">{{ to }}</view></picker
                ></view
            ><button class="primary" :disabled="!from || !to || from > to" @click="apply(from, to)">
                {{ t('Apply dates') }}
            </button></view
        ><text class="period muted"
            >{{ t('Reporting period') }}:
            {{ period.dateFrom ? period.dateFrom + ' — ' + period.dateTo : t('All dates') }}</text
        ></Modal
    >
</template>
<style scoped>
.date-button {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    background: white;
    border: 1px solid #dfe5df;
    border-radius: 10px;
    min-height: 44px;
    padding: 8px 12px;
    font-size: 12px;
    line-height: 20px;
    text-align: left;
}
.presets {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.presets button {
    font-size: 14px;
    min-height: 40px;
    padding: 8px 12px;
    margin: 0;
    line-height: 24px;
}
.text-button {
    background: none;
}
.date-fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-top: 12px;
    font-size: 12px;
}
.picker-value {
    margin-top: 4px;
    padding: 12px;
    border: 1px solid #dfe5df;
    border-radius: 8px;
    font-size: 14px;
}
.date-fields > .primary {
    grid-column: span 2;
    width: 100%;
    margin-top: 4px;
}
.period {
    display: block;
    font-size: 12px;
    line-height: 20px;
    margin-top: 12px;
}
</style>
