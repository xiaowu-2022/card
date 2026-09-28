<script setup lang="ts">
import { computed, ref, onBeforeUnmount, watch } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { useSensitiveScreen } from '../lib/sensitive';
import PageShell from '../components/PageShell.vue';
import StatusBanner from '../components/StatusBanner.vue';
import FormField from '../components/FormField.vue';
import FormErrors from '../components/FormErrors.vue';
import FinancialConfirmation from '../components/FinancialConfirmation.vue';
import { t, dateTime } from '../lib/i18n';
import { useAction, requestId } from '../lib/client';
import { go } from '../lib/navigation';
import { displayMoney, exactAmount, meetsTopupMinimum } from '../generated/exact-amount';
type Money = { amount: string; asset: string };
type Refund = {
    pendingId: string | null;
    canRequest: boolean;
    waitDays: number | null;
    eligibleAt: string | null;
    serverNow: string;
    progress: string | null;
    cancelling: boolean;
};
const props = defineProps<{
    page: {
        preview: {
            current: Money;
            required: Money;
            remaining: Money;
            available: Money;
            availableAfter: Money | null;
            canFund: boolean;
            satisfied: boolean;
            agentExempt: boolean;
            topupAvailable: boolean;
            minimumTopup: Money;
            refund: Refund;
        };
    };
}>();
const emit = defineEmits<{ reload: [] }>();
const p = computed(() => props.page.preview),
    refund = computed(() => p.value.refund);
const action = useAction(),
    fundId = ref(requestId()),
    topupId = ref(requestId()),
    refundId = ref(requestId()),
    topupAmount = ref(displayMoney(p.value.minimumTopup.amount)),
    visible = ref(true),
    seconds = ref(0);
let started = Date.now();
watch(
    () => refund.value.serverNow,
    () => (started = Date.now()),
);
const tick = setInterval(() => {
    seconds.value = refund.value.eligibleAt
        ? Math.max(
              0,
              Math.ceil(
                  (Date.parse(refund.value.eligibleAt) -
                      Date.parse(refund.value.serverNow) -
                      (Date.now() - started)) /
                      1000,
              ),
          )
        : 0;
}, 1000);
const poll = setInterval(() => {
    if (visible.value && refund.value.pendingId && !action.pending.value) emit('reload');
}, 15000);
onBeforeUnmount(() => {
    clearInterval(tick);
    clearInterval(poll);
});
onShow(() => (visible.value = true));
useSensitiveScreen(() => (visible.value = false));
const warning = computed(() =>
    [
        'Applying for a deposit refund freezes your cards and locks card actions.',
        'Cards cannot be used during the deposit refund period. Only transaction history is available.',
        'After the waiting period and confirmed card freezing, your deposit returns automatically to your wallet.',
    ].map((s) => t(s)),
);
const need = computed(() =>
    t('You need {{value1}} {{value2}} to complete your security deposit', {
        value1: displayMoney(p.value.remaining.amount),
        value2: p.value.remaining.asset,
    }),
);
const available = computed(() =>
    t('Available balance: {{amount}}', {
        amount: displayMoney(p.value.available.amount) + ' USDT',
    }),
);
const minimum = computed(() =>
    t('Minimum {{amount}} {{asset}}. You can increase this amount.', {
        amount: displayMoney(p.value.minimumTopup.amount),
        asset: p.value.minimumTopup.asset,
    }),
);
const wait = computed(() =>
    t('Deposit refund waiting period: {{days}} days', { days: refund.value.waitDays ?? 0 }),
);
const countdown = computed(() =>
    t('Refund countdown: {{days}}d {{hours}}h {{minutes}}m {{seconds}}s', {
        days: Math.floor(seconds.value / 86400),
        hours: Math.floor((seconds.value % 86400) / 3600),
        minutes: Math.floor((seconds.value % 3600) / 60),
        seconds: seconds.value % 60,
    }),
);
const scheduled = computed(() =>
    t('Scheduled refund time: {{time}}', { time: dateTime(refund.value.eligibleAt ?? '') }),
);
const progress = computed(() =>
    t(
        refund.value.cancelling
            ? 'Restoring cards before cancelling the refund request.'
            : refund.value.progress === 'legacy'
              ? 'This older request has no countdown. Cancel it and submit a new request to use the company waiting period.'
              : refund.value.progress === 'waiting'
                ? 'Cards are frozen. Waiting for the refund period to end.'
                : refund.value.progress === 'blocked'
                  ? 'Card freezing or an earlier operation is still awaiting confirmation. No refund will be made until it is resolved.'
                  : 'Freezing cards. Card actions are locked.',
    ),
);
function money(value: Money | null) {
    return value ? displayMoney(value.amount) + ' ' + value.asset : '—';
}
async function fund() {
    await action.submit('/security-deposit/fund', {
        request_id: fundId.value,
        expected_remaining: p.value.remaining.amount,
    });
}
async function topup() {
    await action.submit('/security-deposit/top-ups', {
        request_id: topupId.value,
        requested_amount: topupAmount.value,
    });
}
function refunded() {
    refundId.value = requestId();
    emit('reload');
}
</script>
<template>
    <PageShell :title="t('Security deposit')" back="/dashboard" active="assets"
        ><button class="history-link" @click="go('/security-deposit/history')">
            {{ t('Security deposit history') }}</button
        ><FormErrors :errors="action.errors.value" /><view
            v-if="p.agentExempt"
            class="deposit-panel exempt"
            ><text class="bold">{{ t('Active agents do not need a security deposit.') }}</text
            ><text class="muted">{{ t('Current deposit') }}: {{ money(p.current) }}</text></view
        ><StatusBanner
            v-else-if="p.satisfied"
            :title="t('Security deposit')"
            :description="money(p.current)"
            tone="success" /><template v-else-if="!p.canFund"
            ><StatusBanner :title="need" :description="available" tone="warning" />
            <form v-if="!refund.pendingId" class="deposit-panel" @submit="topup">
                <FormField
                    v-model="topupAmount"
                    :label="t('Deposit top-up amount') + ' · ' + p.minimumTopup.asset"
                    type="digit"
                    :disabled="action.pending.value"
                    @update:model-value="topupId = requestId()"
                /><text class="muted">{{ minimum }}</text
                ><text class="small muted">{{
                    t(
                        'Only the required deposit is reserved. Any extra stays in your available balance.',
                    )
                }}</text
                ><text v-if="p.topupAvailable" class="small muted">{{
                    t(
                        'A 0.01–0.99 identification amount will be added. Your wallet receives the full exact amount sent; it is not a fee.',
                    )
                }}</text
                ><button
                    class="primary wide"
                    form-type="submit"
                    :disabled="
                        !p.topupAvailable ||
                        !meetsTopupMinimum(topupAmount, p.minimumTopup.amount) ||
                        action.pending.value
                    "
                >
                    {{
                        t(
                            action.pending.value
                                ? 'Creating instructions…'
                                : 'Create payment instructions',
                        )
                    }}
                </button>
            </form></template
        ><view v-else class="deposit-panel"
            ><text class="deposit-title">{{ t('Review deposit') }}</text
            ><text class="muted">{{
                t('Funds will be held separately from your available balance.')
            }}</text
            ><view class="deposit-summary"
                ><view
                    v-for="item in [
                        { label: 'Required', value: p.required },
                        { label: 'Already deposited', value: p.current },
                        { label: 'Deposit now', value: p.remaining },
                        { label: 'Available after', value: p.availableAfter },
                    ]"
                    :key="item.label"
                    ><text>{{ t(item.label) }}</text
                    ><text class="bold">{{ money(item.value) }}</text></view
                ></view
            ><button class="primary" :disabled="action.pending.value" @click="fund">
                {{ t(action.pending.value ? 'Confirming…' : 'Confirm deposit') }}
            </button></view
        ><view
            v-if="exactAmount(p.current.amount) !== '0' || refund.pendingId"
            class="refund-section"
            ><text class="bold">{{ t('Refund security deposit') }}</text
            ><text v-for="line in warning" :key="line" class="refund-warning">• {{ line }}</text
            ><text v-if="refund.waitDays !== null && !refund.pendingId" class="small">{{
                wait
            }}</text
            ><text v-if="refund.waitDays === null && !refund.pendingId" class="muted">{{
                t(
                    'The company has not configured the deposit refund waiting period. Please contact support.',
                )
            }}</text
            ><view v-if="refund.pendingId" class="refund-progress"
                ><text>{{ progress }}</text
                ><template v-if="refund.eligibleAt && !refund.cancelling"
                    ><text>{{ countdown }}</text
                    ><text class="small muted">{{ scheduled }}</text
                    ><text v-if="seconds === 0">{{
                        t(
                            'The waiting period has ended. The system will complete the refund after card freezing is confirmed.',
                        )
                    }}</text></template
                ></view
            ><FinancialConfirmation
                v-if="refund.canRequest"
                :title="t('Request deposit refund')"
                :warning="warning"
                url="/security-deposit/refund"
                :payload="{ action: 'request', request_id: refundId }"
                @completed="refunded" /><FinancialConfirmation
                v-if="refund.pendingId && !refund.cancelling"
                :title="t('Cancel deposit refund request')"
                :warning="
                    t(
                        'Cancelling keeps your deposit and restores only cards frozen by this request. Please wait for restoration; no new commission is earned.',
                    )
                "
                url="/security-deposit/refund"
                :payload="{ action: 'cancel', refund_id: refund.pendingId }"
                @completed="emit('reload')" /></view
    ></PageShell>
</template>
<style scoped>
.history-link {
    display: block;
    margin: 0 0 20px auto;
    padding: 0;
    background: transparent;
    font-size: 14px;
    color: #68736e;
    line-height: 24px;
    min-height: 44px;
}
.deposit-panel {
    display: flex;
    flex-direction: column;
    gap: 16px;
    border: 1px solid #e2e7e4;
    border-radius: 24px;
    background: white;
    padding: 20px;
    margin-bottom: 24px;
}
.exempt {
    background: #f2f6ef;
    border: 0;
    border-radius: 16px;
}
.bold {
    font-weight: 600;
}
.deposit-title {
    font-size: 20px;
    font-weight: 600;
}
.small {
    font-size: 12px;
    line-height: 20px;
}
.deposit-summary {
    border-top: 1px solid #e2e7e4;
}
.deposit-summary > view {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid #e2e7e4;
    font-size: 14px;
}
.primary {
    min-height: 44px;
    border-radius: 999px;
    padding: 10px 24px;
    font-size: 14px;
    line-height: 24px;
}
.wide {
    width: 100%;
}
.refund-section {
    border-top: 1px solid #e2e7e4;
    padding-top: 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.refund-warning {
    font-size: 14px;
    line-height: 24px;
    color: #68736e;
}
.refund-progress {
    display: flex;
    flex-direction: column;
    gap: 8px;
    border-radius: 12px;
    background: #f0f3f1;
    padding: 16px;
    font-size: 14px;
    line-height: 24px;
}
</style>
