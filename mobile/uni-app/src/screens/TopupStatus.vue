<script setup lang="ts">
import { computed, ref, onBeforeUnmount } from 'vue';
import { onShow, onHide } from '@dcloudio/uni-app';
import PageShell from '../components/PageShell.vue';
import DepositInstructions from '../components/DepositInstructions.vue';
import UiIcon from '../components/UiIcon.vue';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
import { exactAmount } from '../generated/exact-amount';
const props = defineProps<{
    page: {
        depositFlow?: boolean;
        order: {
            id: string;
            expectedAmount: string;
            amount: string;
            asset: string;
            status: string;
            paymentDetected: boolean;
            network: string | null;
            depositAddress: string | null;
            expiresAt: string | null;
        };
    };
}>();
const emit = defineEmits<{ reload: [] }>();
const now = ref(Date.now()),
    visible = ref(true);
onShow(() => (visible.value = true));
onHide(() => (visible.value = false));
const timer = setInterval(() => {
    now.value = Date.now();
    if (
        visible.value &&
        ['WAITING', 'CONFIRMING', 'ADDING_FUNDS', 'PROCESSING'].includes(props.page.order.status)
    )
        emit('reload');
}, 4000);
onBeforeUnmount(() => clearInterval(timer));
const completed = computed(() => props.page.order.status === 'COMPLETED');
const expired = computed(
    () =>
        props.page.order.status === 'EXPIRED' ||
        (!completed.value &&
            props.page.order.expiresAt &&
            Date.parse(props.page.order.expiresAt) <= now.value),
);
const failed = computed(() => ['FAILED', 'CANCELLED'].includes(props.page.order.status));
const back = computed(() => (props.page.depositFlow ? '/security-deposit' : '/dashboard'));
const title = computed(() =>
    t(
        completed.value
            ? 'Funds added'
            : expired.value
              ? 'Top-up expired'
              : failed.value
                ? "Payment wasn't completed"
                : 'Adding funds to wallet',
    ),
);
const description = computed(() =>
    t(
        completed.value
            ? 'The full exact transfer is now available in your wallet.'
            : expired.value
              ? 'Create a new top-up to continue. No new amount was generated automatically.'
              : failed.value
                ? 'No funds were added to your wallet.'
                : 'Your payment is confirmed and the wallet credit is being completed.',
    ),
);
</script>
<template>
    <PageShell :title="t('Top up')" :back="back" active="assets"
        ><view class="topup-status"
            ><DepositInstructions
                v-if="
                    page.order.network === 'TRON' &&
                    page.order.depositAddress &&
                    !completed &&
                    !failed
                "
                :asset="page.order.asset"
                :amount="page.order.expectedAmount"
                :network="page.order.network"
                :address="page.order.depositAddress"
                :state="
                    expired
                        ? 'Top-up expired'
                        : page.order.paymentDetected
                          ? 'Payment detected'
                          : 'Waiting for payment'
                "
                :expires-at="page.order.expiresAt"
                :payable="!expired && !page.order.paymentDetected"
                :new-href="
                    page.depositFlow
                        ? '/security-deposit'
                        : '/assets/operate?asset=USDT&mode=deposit'
                "
            /><view v-else class="status-card"
                ><view class="status-icon"
                    ><UiIcon
                        :name="
                            completed
                                ? 'circle-check'
                                : failed || expired
                                  ? 'circle-alert'
                                  : 'history'
                        "
                        :size="40" /></view
                ><text class="status-title">{{ title }}</text
                ><text class="status-description">{{ description }}</text
                ><text class="status-amount"
                    >{{ exactAmount(page.order.expectedAmount ?? page.order.amount) }}
                    {{ page.order.asset }}</text
                ><button
                    class="primary"
                    @click="
                        go(
                            page.depositFlow
                                ? '/security-deposit'
                                : expired
                                  ? '/wallet/top-up'
                                  : '/dashboard',
                        )
                    "
                >
                    {{
                        t(
                            page.depositFlow
                                ? 'Security deposit'
                                : expired
                                  ? 'Create new top-up'
                                  : 'Back to home',
                        )
                    }}
                </button></view
            ></view
        ></PageShell
    >
</template>
<style scoped>
.topup-status {
    max-width: 512px;
    margin: auto;
}
.status-card {
    text-align: center;
    border: 1px solid #e2e7e4;
    border-radius: 24px;
    background: white;
    padding: 28px;
}
.status-icon {
    margin: auto auto 20px;
    width: 64px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #ecfdf5;
}
.status-title {
    display: block;
    font-size: 24px;
    font-weight: 600;
}
.status-description {
    display: block;
    font-size: 14px;
    color: #68736e;
    line-height: 24px;
    margin-top: 8px;
}
.status-amount {
    display: block;
    margin-top: 20px;
    font-size: 18px;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.primary {
    margin-top: 24px;
    border-radius: 999px;
    min-height: 44px;
    padding: 10px 20px;
    font-size: 14px;
    line-height: 24px;
}
</style>
