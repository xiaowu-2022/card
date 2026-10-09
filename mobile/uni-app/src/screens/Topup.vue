<script setup lang="ts">
import { ref, computed } from 'vue';
import PageShell from '../components/PageShell.vue';
import StatusBanner from '../components/StatusBanner.vue';
import FormField from '../components/FormField.vue';
import FormErrors from '../components/FormErrors.vue';
import { t, dateTime } from '../lib/i18n';
import { go } from '../lib/navigation';
import { useAction, requestId } from '../lib/client';
import { exactAmount, meetsTopupMinimum } from '../generated/exact-amount';
const props = defineProps<{
    page: {
        available: { amount: string; asset: string } | null;
        wallet: { id: string; asset: string } | null;
        topupAvailable: boolean;
        minimum: string;
        orders: {
            id: string;
            expectedAmount: string;
            asset: string;
            status: string;
            createdAt: string;
        }[];
    };
}>();
const action = useAction(),
    amount = ref(''),
    intent = ref(requestId());
const valid = computed(() => meetsTopupMinimum(amount.value, props.page.minimum));
const amountError = computed(() => {
    if (!amount.value || valid.value) return '';
    return t(meetsTopupMinimum(amount.value, '0')
        ? 'The amount is below the minimum deposit.'
        : 'Enter a positive amount with at most 2 decimal places.');
});
async function submit() {
    await action.submit('/wallet/top-ups', {
        request_id: intent.value,
        requested_amount: amount.value,
    });
}
</script>
<template>
    <PageShell :title="t('Top up')" back="/dashboard" active="assets"
        ><view v-if="page.available" class="available-hero"
            ><text class="muted">{{ t('Available balance') }}</text
            ><text class="available-value"
                >{{ exactAmount(page.available.amount) }} {{ page.available.asset }}</text
            ></view
        ><FormErrors :errors="action.errors.value" /><StatusBanner
            v-if="!page.topupAvailable || !page.wallet"
            :title="t('Top-up unavailable')"
            :description="t('An active verified USDT wallet is required for TRC20 top-ups.')"
            tone="warning"
        />
        <form v-else class="topup-card" @submit="submit">
            <FormField
                v-model="amount"
                :label="t('Amount') + ' · USDT'"
                placeholder="100.00"
                type="digit"
                :error="amountError"
                :disabled="action.pending.value"
                @update:model-value="intent = requestId()"
            /><text class="hint"
                >{{ t('Minimum deposit') }}: {{ exactAmount(page.minimum) }} USDT ·
                {{ t('TRC20 · No top-up fee') }}</text
            ><button
                class="primary wide"
                form-type="submit"
                :disabled="!valid || action.pending.value"
            >
                {{ t(action.pending.value ? 'Creating instructions…' : 'Continue') }}
            </button>
        </form>
        <text class="section-title">{{ t('Top-up history') }}</text
        ><view v-if="!page.orders.length" class="empty"
            ><text class="block">{{ t('No top-ups yet') }}</text
            ><text>{{ t('Your top-up history will appear here.') }}</text></view
        ><view
            v-for="order in page.orders"
            :key="order.id"
            class="order-row"
            @click="go('/wallet/top-ups/' + order.id + '/return')"
            ><view
                ><text class="block">{{ t('USDT top up') }}</text
                ><text class="muted small">{{ dateTime(order.createdAt) }}</text></view
            ><view class="order-value"
                ><text class="block">{{ exactAmount(order.expectedAmount) }} {{ order.asset }}</text
                ><text class="muted small">{{
                    t(
                        ['CREDITED', 'COMPLETED'].includes(order.status)
                            ? 'Actual receipt'
                            : order.status,
                    )
                }}</text></view
            ></view
        ></PageShell
    >
</template>
<style scoped>
.available-hero {
    padding: 24px 0;
    text-align: center;
}
.available-value {
    display: block;
    margin-top: 12px;
    font-size: 32px;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.topup-card {
    padding: 20px;
    border: 1px solid #e2e7e4;
    background: white;
    border-radius: 24px;
}
.hint {
    display: block;
    font-size: 12px;
    color: #68736e;
    line-height: 20px;
}
.wide {
    width: 100%;
    margin-top: 20px;
    min-height: 44px;
    padding: 10px 20px;
    line-height: 24px;
    border-radius: 999px;
}
.section-title {
    display: block;
    font-size: 18px;
    font-weight: 600;
    margin: 24px 0 16px;
}
.order-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 0;
    border-bottom: 1px solid #e2e7e4;
    font-size: 14px;
}
.order-value {
    text-align: right;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.block {
    display: block;
}
.small {
    font-size: 12px;
}
</style>
