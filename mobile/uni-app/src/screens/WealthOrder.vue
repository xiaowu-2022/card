<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useSensitiveScreen } from '../lib/sensitive';
import PageShell from '../components/PageShell.vue';
import Modal from '../components/Modal.vue';
import FormField from '../components/FormField.vue';
import FormErrors from '../components/FormErrors.vue';
import ConfirmCheck from '../components/ConfirmCheck.vue';
import UiIcon from '../components/UiIcon.vue';
import { getPage, useAction, requestId, explainError } from '../lib/client';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
import { exactAmount } from '../generated/exact-amount';
import { wealthState, wealthDate, wealthTerm, type WealthOrder } from '../lib/wealth';
type Order = WealthOrder & {
    schedule: { month: number; dueAt: string; amount: string; settledAt: string | null }[];
};
const props = defineProps<{ page: { order: Order; startWithdrawal?: boolean } }>();
const emit = defineEmits<{ reload: [] }>();
const current = ref<Order | null>(null),
    order = computed(() => current.value ?? props.page.order),
    review = ref(!!props.page.startWithdrawal && (order.value.canCancel || order.value.canRedeem)),
    refreshing = ref(false),
    action = useAction();
const form = reactive({ request_id: requestId(), current_password: '', confirmed: false });
const redeemLabel = computed(() =>
    order.value.canRedeem
        ? t('Redeem to {{asset}} wallet', { asset: order.value.asset })
        : t('Withdraw entire deposit early'),
);
const confirmTitle = computed(() =>
    t(order.value.canRedeem ? 'Confirm maturity redemption' : 'Confirm early withdrawal'),
);
const interestCopy = computed(() =>
    order.value.canRedeem
        ? t(
              'Your full principal returns to the original currency wallet. Paid interest is not recovered.',
          )
        : t('Interest recovered: {{amount}}', {
              amount: exactAmount(order.value.paid) + ' ' + order.value.asset,
          }),
);
const returnCopy = computed(() =>
    t('Amount returned now: {{amount}}', {
        amount: exactAmount(order.value.returnAmount) + ' ' + order.value.asset,
    }),
);
const closedCopy = computed(() =>
    t(
        order.value.displayStatus === 'RENEWED'
            ? 'Principal renewed: {{amount}}'
            : 'Principal returned: {{amount}}',
        { amount: exactAmount(order.value.returnAmount) + ' ' + order.value.asset },
    ),
);
function close() {
    if (action.pending.value) return;
    review.value = false;
    form.current_password = '';
    form.confirmed = false;
}
useSensitiveScreen(() => {
    form.current_password = '';
    form.confirmed = false;
    review.value = false;
});
async function open() {
    if (action.pending.value || refreshing.value) return;
    refreshing.value = true;
    try {
        const data = await getPage<{ order: Order }>('/wealth/orders/' + order.value.id);
        current.value = data.props.order;
        form.current_password = '';
        form.confirmed = false;
        review.value = order.value.canCancel || order.value.canRedeem;
    } catch (e) {
        action.errors.value = explainError(e);
    } finally {
        refreshing.value = false;
    }
}
async function submit() {
    if (!form.confirmed) return;
    await action.submit(
        '/wealth/orders/' + order.value.id + '/' + (order.value.canRedeem ? 'redeem' : 'cancel'),
        { ...form, ...(order.value.canRedeem ? {} : { expected_paid: order.value.paid }) },
    );
    form.current_password = '';
    form.confirmed = false;
    review.value = false;
    if (action.failureStatus.value !== null) emit('reload');
}
</script>
<template>
    <PageShell
        :title="t('Wealth deposit details')"
        :back="
            '/wealth/assets/' +
            order.asset +
            '?view=' +
            (page.startWithdrawal ? 'withdraw' : 'details')
        "
        active="assets"
        ><view class="wealth-order"
            ><view class="hero"
                ><view class="between"
                    ><text>{{ order.asset }}</text
                    ><text class="state">{{ wealthState(order.displayStatus) }}</text></view
                ><text class="muted small">{{ t('Deposit principal') }}</text
                ><text class="principal"
                    >{{ exactAmount(order.principal) }}
                    <text class="currency">{{ order.asset }}</text></text
                ><view class="paid"
                    ><text class="muted small">{{ t('Total interest paid') }}</text
                    ><text class="paid-amount"
                        >{{ exactAmount(order.paid) }}
                        <text class="small">{{ order.asset }}</text></text
                    ></view
                ></view
            ><view class="panel"
                ><text class="heading">{{ t('Wealth deposit information') }}</text
                ><view class="info-row"
                    ><text class="muted">{{ t('Wealth term') }}</text
                    ><text>{{ wealthTerm(order.months, exactAmount(order.rate)) }}</text></view
                ><view class="info-row"
                    ><text class="muted">{{ t('Deposit date') }}</text
                    ><text>{{ wealthDate(order.startedAt, order.timezone) }}</text></view
                ><view class="info-row"
                    ><text class="muted">{{ t('Maturity date') }}</text
                    ><text>{{ wealthDate(order.maturesAt, order.timezone) }}</text></view
                ><view v-if="order.closedAt" class="notice"
                    ><text>{{ closedCopy }}</text
                    ><text class="muted small">{{
                        wealthDate(order.closedAt, order.timezone)
                    }}</text></view
                ></view
            ><text v-if="order.maturityPolicy === 'AUTO_RETURN'" class="muted copy">{{
                t(
                    'Principal returns automatically at maturity. No automatic renewal or compound interest.',
                )
            }}</text
            ><view v-else class="notice copy"
                ><text
                    >{{ t('Redeem before') }}: {{ order.redeemBeforeLocal }} ({{
                        order.timezone
                    }})</text
                ><text>{{
                    t(
                        'Redeem principal on the maturity day before midnight in the order timezone. Otherwise, the same principal renews for the same term and rate. Paid interest stays in your wallet.',
                    )
                }}</text
                ><text>{{
                    t(
                        'No extra interest accrues during the redemption window. Processing delays do not shift the next term.',
                    )
                }}</text
                ><text v-if="order.displayStatus === 'RENEWAL_PENDING'">{{
                    t(
                        'Renewal is pending. Your principal remains in wealth; recovery will use the scheduled start time.',
                    )
                }}</text></view
            ><view class="between"
                ><button
                    v-if="order.previousOrderId"
                    class="text-button"
                    @click="go('/wealth/orders/' + order.previousOrderId)"
                >
                    {{ t('Previous wealth term') }}</button
                ><button
                    v-if="order.nextOrderId"
                    class="text-button"
                    @click="go('/wealth/orders/' + order.nextOrderId)"
                >
                    {{ t('Next wealth term') }}
                </button></view
            ><button
                v-if="order.canCancel || order.canRedeem"
                class="secondary"
                :disabled="action.pending.value || refreshing"
                @click="open"
            >
                {{ redeemLabel }}</button
            ><FormErrors :errors="action.errors.value" /><Modal
                :open="review"
                :title="confirmTitle"
                :description="exactAmount(order.principal) + ' ' + order.asset"
                :busy="action.pending.value"
                @close="close"
                ><form @submit="submit">
                    <text class="review-copy">{{ interestCopy }}</text
                    ><text class="review-copy strong">{{ returnCopy }}</text
                    ><FormField
                        v-model="form.current_password"
                        :label="t('Current password')"
                        password
                        :disabled="action.pending.value"
                    /><ConfirmCheck
                        v-model="form.confirmed"
                        :label="
                            t(
                                order.canRedeem
                                    ? 'I confirm full principal redemption to my original currency wallet.'
                                    : 'I confirm cancellation of the entire deposit and recovery of all paid interest.',
                            )
                        "
                        :disabled="action.pending.value"
                    /><button
                        class="primary wide"
                        form-type="submit"
                        :disabled="
                            action.pending.value || !form.confirmed || !form.current_password
                        "
                    >
                        {{ confirmTitle }}
                    </button>
                </form></Modal
            ><view class="panel"
                ><text class="heading">{{ t('Monthly interest schedule') }}</text
                ><view v-for="row in order.schedule" :key="row.month" class="schedule-row"
                    ><view class="schedule-icon"
                        ><UiIcon
                            :name="
                                row.settledAt
                                    ? 'check'
                                    : order.status === 'CANCELLED'
                                      ? 'minus'
                                      : 'clock3'
                            "
                            :size="16" /></view
                    ><view class="schedule-body"
                        ><view class="between"
                            ><text>{{ wealthDate(row.dueAt, order.timezone) }}</text
                            ><text class="strong"
                                >{{ exactAmount(row.amount) }} {{ order.asset }}</text
                            ></view
                        ><text class="small muted">{{
                            t(
                                row.settledAt
                                    ? 'Interest paid'
                                    : order.status === 'CANCELLED'
                                      ? 'Interest cancelled'
                                      : 'Scheduled interest',
                            )
                        }}</text
                        ><text v-if="row.settledAt" class="small muted">{{
                            wealthDate(row.settledAt, order.timezone)
                        }}</text></view
                    ></view
                ></view
            ></view
        ></PageShell
    >
</template>
<style scoped>
.wealth-order {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.hero {
    padding: 20px;
    border-radius: 24px;
    background: linear-gradient(135deg, #d1fae5, #f7fee7);
}
.between {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}
.hero > .between {
    margin-bottom: 16px;
    font-size: 14px;
    font-weight: 600;
}
.state {
    font-size: 12px;
    background: #ffffffb3;
    border-radius: 999px;
    padding: 4px 12px;
    color: #065f46;
}
.small {
    font-size: 12px;
    line-height: 20px;
}
.hero > .small,
.principal {
    display: block;
}
.principal {
    font-size: 30px;
    line-height: 36px;
    font-weight: 600;
    overflow-wrap: anywhere;
    margin-top: 4px;
}
.currency {
    font-size: 16px;
}
.paid {
    margin-top: 20px;
    border-top: 1px solid #064e3b1a;
    padding-top: 12px;
}
.paid-amount {
    display: block;
    font-size: 18px;
    font-weight: 600;
    margin-top: 4px;
}
.panel {
    padding: 16px;
    background: white;
    border: 1px solid #0000000d;
    border-radius: 16px;
    box-shadow: 0 1px 2px #0000000d;
}
.heading {
    display: block;
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 12px;
}
.info-row {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 4px 16px;
    padding: 12px 0;
    border-bottom: 1px solid #0000000d;
    font-size: 14px;
}
.info-row:last-child {
    border: 0;
    padding-bottom: 0;
}
.notice {
    background: #f0f3f1;
    border-radius: 12px;
    padding: 16px;
}
.notice text {
    display: block;
    margin-top: 8px;
}
.notice text:first-child {
    margin-top: 0;
}
.copy {
    font-size: 14px;
    line-height: 22px;
}
.review-copy {
    display: block;
    font-size: 14px;
    line-height: 24px;
    margin-bottom: 16px;
}
.strong {
    font-weight: 600;
}
.wide {
    width: 100%;
}
.schedule-row {
    display: flex;
    gap: 12px;
    padding: 16px 0;
    border-bottom: 1px solid #0000000d;
    font-size: 14px;
}
.schedule-row:last-child {
    border: 0;
    padding-bottom: 4px;
}
.schedule-icon {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #ecfdf5;
    flex-shrink: 0;
}
.schedule-body {
    min-width: 0;
    flex: 1;
}
.schedule-body > .small {
    display: block;
    margin-top: 4px;
}
.text-button {
    padding: 0;
    background: none;
    font-size: 14px;
    text-decoration: underline;
}
</style>
