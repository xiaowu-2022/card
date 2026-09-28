<script setup lang="ts">
import { computed } from 'vue';
import { t } from '../lib/i18n';
import { exactAmount } from '../generated/exact-amount';
import { promotionUnits } from '../lib/promotion';
import type { PaidPromotionData } from '../lib/promotion-types';
const props = defineProps<{
    progress: NonNullable<PaidPromotionData['progress']>;
    preview?: boolean;
}>();
const units = computed(() => props.progress.direct * 2 + props.progress.indirect),
    remaining = computed(() => Math.max(0, props.progress.target * 2 - units.value) / 2);
const copy = computed(() =>
    props.preview && remaining.value === 0
        ? t('After successful payment, {{amount}} USDT will be returned automatically.', {
              amount: exactAmount(props.progress.remaining),
          })
        : props.progress.pending
          ? t('Annual fee return is processing.')
          : promotionUnits(props.progress.remaining) === 0n
            ? t('Annual fee returned: {{amount}} USDT', {
                  amount: exactAmount(props.progress.returned),
              })
            : remaining.value > 0
              ? t(
                    '{{count}} more weighted account activations to automatically return {{amount}} USDT',
                    { count: remaining.value, amount: exactAmount(props.progress.remaining) },
                )
              : t('Annual fee return is processing.'),
);
const percent = computed(() =>
    props.progress.target > 0
        ? Math.min(100, (units.value / (props.progress.target * 2)) * 100)
        : 100,
);
</script>
<template>
    <view class="rebate-progress"
        ><view class="line"
            ><text>{{
                t(
                    preview
                        ? 'Annual fee return progress after payment'
                        : 'Annual fee return progress',
                )
            }}</text
            ><text>{{ units / 2 }} / {{ progress.target }}</text></view
        ><view
            class="bar"
            role="progressbar"
            :aria-valuenow="Math.min(units / 2, progress.target)"
            :aria-valuemax="progress.target"
            ><view :style="{ width: percent + '%' }" /></view
        ><text class="copy">{{ copy }}</text></view
    >
</template>
<style scoped>
.rebate-progress {
    padding-top: 12px;
    border-top: 1px solid #25423533;
    font-size: 12px;
    line-height: 20px;
}
.line {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 8px;
}
.bar {
    height: 4px;
    overflow: hidden;
    border-radius: 999px;
    background: #25423526;
    margin-top: 8px;
}
.bar > view {
    height: 100%;
    border-radius: 999px;
    background: currentColor;
}
.copy {
    display: block;
    font-weight: 500;
    margin-top: 8px;
    overflow-wrap: anywhere;
}
</style>
