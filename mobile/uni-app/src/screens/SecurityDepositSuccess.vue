<script setup lang="ts">
import PageShell from '../components/PageShell.vue';
import UiIcon from '../components/UiIcon.vue';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
import { displayMoney } from '../generated/exact-amount';
defineProps<{ page: { receipt: { amount: string; asset: string } } }>();
</script>
<template>
    <PageShell :title="t('Security deposit complete')" back="/dashboard" active="assets"
        ><view class="deposit-success"
            ><view class="success-icon"><UiIcon name="circle-check" :size="28" /></view
            ><text>{{ t('Already deposited') }}</text
            ><text>{{ displayMoney(page.receipt.amount) }} {{ page.receipt.asset }}</text
            ><button class="primary" @click="go('/dashboard')">
                {{ t('Back to home') }}
            </button></view
        ></PageShell
    >
</template>
<style scoped>
.deposit-success {
    max-width: 512px;
    margin: auto;
    padding: 40px 0;
    text-align: center;
    display: flex;
    align-items: center;
    flex-direction: column;
    color: #68736e;
}
.success-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    margin-bottom: 12px;
    border-radius: 50%;
    background: #ecfdf5;
}
.primary {
    margin-top: 32px;
    width: 100%;
    border-radius: 999px;
    min-height: 44px;
    padding: 10px 24px;
    line-height: 24px;
    font-size: 14px;
}
@media (min-width: 640px) {
    .deposit-success {
        padding: 64px 0;
    }
    .primary {
        width: auto;
    }
}
</style>
