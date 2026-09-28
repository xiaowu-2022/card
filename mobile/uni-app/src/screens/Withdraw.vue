<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import PageShell from '../components/PageShell.vue';
import FormField from '../components/FormField.vue';
import FormErrors from '../components/FormErrors.vue';
import WithdrawalAmounts from '../components/WithdrawalAmounts.vue';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
import { useAction, requestId } from '../lib/client';
import {
    displayMoney,
    withdrawalPercentageFee,
    withdrawalRemainder,
    withdrawalReceiveAmount,
} from '../generated/exact-amount';
const props = defineProps<{
    page: {
        available: { amount: string; asset: string };
        network: string;
        feePercent: string | null;
    };
}>();
const form = reactive({ request_id: requestId(), address: '', amount: '' }),
    review = ref(false),
    reviewedFee = ref('0'),
    action = useAction();
const validAddress = computed(() => /^T[1-9A-HJ-NP-Za-km-z]{33}$/.test(form.address.trim())),
    remaining = computed(() => withdrawalRemainder(form.amount, props.page.available.amount)),
    calculatedFee = computed(() =>
        withdrawalPercentageFee(form.amount, props.page.feePercent, 'USDT'),
    ),
    fee = computed(() => (review.value ? reviewedFee.value : (calculatedFee.value ?? '0'))),
    receive = computed(() => withdrawalReceiveAmount(form.amount, fee.value));
const canReview = computed(
    () =>
        calculatedFee.value !== null &&
        validAddress.value &&
        remaining.value !== null &&
        receive.value !== null,
);
const addressError = computed(() =>
    form.address && !validAddress.value ? t('Enter a valid TRON address.') : '',
);
const amountError = computed(() =>
    form.amount && remaining.value === null
        ? withdrawalRemainder(form.amount, '999999999999.99999999') === null
            ? t('Enter a positive amount with at most 2 decimal places.')
            : t('Your available balance is not enough for this withdrawal.')
        : form.amount && receive.value === null
          ? t('Withdrawal amount must be greater than the fee.')
          : '',
);
const uncertain = ref(false);
function changed() {
    if (uncertain.value) return;
    form.request_id = requestId();
}
function openReview() {
    if (canReview.value) {
        reviewedFee.value = calculatedFee.value!;
        review.value = true;
    }
}
async function submit() {
    if (!canReview.value) return;
    uncertain.value = true;
    const result = await action.submit('/wallet/withdrawals', {
        ...form,
        address: form.address.trim(),
        confirmed: true,
        expected_fee: reviewedFee.value,
    });
    if (result) {
        form.address = '';
        form.amount = '';
        uncertain.value = false;
    } else if (action.failureStatus.value === 422) {
        uncertain.value = false;
        review.value = false;
    }
}
</script>
<template>
    <PageShell :title="t('Withdraw')" back="/dashboard" active="assets"
        ><view class="withdraw"
            ><button class="history-link" @click="go('/wallet/withdrawals')">
                {{ t('Withdrawal history') }}</button
            ><FormErrors :errors="action.errors.value" /><template v-if="!review"
                ><view
                    ><text class="muted small">{{ t('Available') }}</text
                    ><text class="balance"
                        >{{ displayMoney(page.available.amount) }} {{ page.available.asset }}</text
                    ></view
                >
                <form @submit="openReview">
                    <FormField
                        v-model="form.address"
                        :label="t('Withdrawal address')"
                        :placeholder="t('T...')"
                        :error="addressError"
                        :disabled="action.pending.value || uncertain"
                        @update:model-value="changed"
                    /><FormField
                        v-model="form.amount"
                        :label="t('Amount')"
                        type="digit"
                        placeholder="0.00"
                        :error="amountError"
                        :disabled="action.pending.value || uncertain"
                        @update:model-value="changed"
                    /><view class="network"
                        ><text class="muted">{{ t('Network') }}</text
                        ><text>USDT ({{ page.network }})</text></view
                    ><text v-if="page.feePercent === null" class="error">{{
                        t('Not configured')
                    }}</text
                    ><WithdrawalAmounts :fee="fee" :receive="receive" /><button
                        class="primary submit"
                        form-type="submit"
                        :disabled="!canReview || action.pending.value"
                    >
                        {{ t('Withdraw') }}
                    </button>
                </form></template
            ><view v-else class="review"
                ><text class="muted small">{{ t('You are withdrawing') }}</text
                ><text class="balance">{{ displayMoney(form.amount) }} USDT</text
                ><view class="details"
                    ><view class="row"
                        ><text class="muted">{{ t('Network') }}</text
                        ><text>{{ page.network }}</text></view
                    ><view class="address"
                        ><text class="muted">{{ t('Address') }}</text
                        ><text>{{ form.address.trim() }}</text></view
                    ><view class="row"
                        ><text class="muted">{{ t('Available after') }}</text
                        ><text
                            >{{ remaining === null ? '—' : displayMoney(remaining) }} USDT</text
                        ></view
                    ></view
                ><WithdrawalAmounts :fee="fee" :receive="receive" /><text class="warning">{{
                    t(
                        'Check the address and amount carefully. Transfers sent to an incorrect address cannot be recovered. Your request will be reviewed before payment.',
                    )
                }}</text
                ><view class="buttons"
                    ><button
                        class="secondary"
                        :disabled="action.pending.value || uncertain"
                        @click="review = false"
                    >
                        {{ t('Back') }}</button
                    ><button
                        class="primary"
                        :disabled="!canReview || action.pending.value"
                        @click="submit"
                    >
                        {{ t(action.pending.value ? 'Submitting…' : 'Confirm withdrawal') }}
                    </button></view
                ></view
            ></view
        ></PageShell
    >
</template>
<style scoped>
.withdraw {
    display: flex;
    flex-direction: column;
    gap: 24px;
}
.history-link {
    background: none;
    font-size: 14px;
    font-weight: 600;
    padding: 0;
    margin: 0 0 0 auto;
    color: #68736e;
}
.small {
    font-size: 14px;
    display: block;
}
.balance {
    display: block;
    font-size: 30px;
    line-height: 36px;
    font-weight: 600;
    margin-top: 4px;
    overflow-wrap: anywhere;
}
.network,
.row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    font-size: 14px;
}
.network {
    padding: 12px 16px;
    border-radius: 8px;
    background: #f0f3f1;
    margin-bottom: 20px;
}
.withdraw form .submit {
    display: flex;
    width: max-content;
    margin: 20px auto 0;
    min-width: 100px;
}
.review {
    background: white;
    border: 1px solid #e2e7e4;
    border-radius: 16px;
    padding: 20px;
}
.details {
    margin: 24px 0 16px;
    border-block: 1px solid #e2e7e4;
}
.row,
.address {
    padding: 16px 0;
    border-bottom: 1px solid #e2e7e4;
}
.row:last-child {
    border: 0;
}
.address {
    font-size: 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    overflow-wrap: anywhere;
}
.warning {
    display: block;
    font-size: 14px;
    line-height: 24px;
    color: #68736e;
    margin-top: 16px;
}
.buttons {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 12px;
    margin-top: 24px;
}
.buttons button {
    margin: 0;
}
.error {
    color: #b42318;
}
</style>
