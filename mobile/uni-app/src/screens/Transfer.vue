<script setup lang="ts">
import { useSensitiveScreen } from '../lib/sensitive';
import { computed, reactive, ref } from 'vue';
import Modal from '../components/Modal.vue';
import { request } from '../lib/api';
import PageShell from '../components/PageShell.vue';
import FormField from '../components/FormField.vue';
import FormErrors from '../components/FormErrors.vue';
import SelectField from '../components/SelectField.vue';
import ConfirmCheck from '../components/ConfirmCheck.vue';
import UiIcon from '../components/UiIcon.vue';
import { t, dateTime } from '../lib/i18n';
import { session } from '../lib/session';
import { useAction, requestId, explainError } from '../lib/client';
import { go } from '../lib/navigation';
import { exactAmount } from '../generated/exact-amount';
type Draft = { request_id: string; recipient_account_id: string; amount: string; asset: string };
type Receipt = {
    id: string;
    requestId: string;
    amount: string;
    asset: string;
    sent: boolean;
    senderAccountId: string;
    recipientAccountId: string;
    createdAt: string;
};
const props = defineProps<{
    page: {
        accountId: string;
        assets: { asset: string; amount: string; scale: number; available: boolean }[];
        transferAvailable: boolean;
        receipt: Receipt | null;
    };
}>();
const scales: Record<string, number> = { USDT: 8, USDC: 6, ETH: 18, BTC: 8 };
const key = `wallet-transfer:${session.value?.tenant.id}:${props.page.accountId}`;
function validAmount(amount: string, asset: string) {
    return (
        asset in scales &&
        new RegExp('^(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,' + scales[asset] + '})?$').test(amount) &&
        /[1-9]/.test(amount)
    );
}
function readDraft(): Draft | null {
    try {
        const d = uni.getStorageSync(key);
        return d &&
            typeof d === 'object' &&
            typeof d.request_id === 'string' &&
            /^[a-f0-9-]{36}$/.test(d.request_id) &&
            typeof d.recipient_account_id === 'string' &&
            /^\d{12}$/.test(d.recipient_account_id) &&
            typeof d.amount === 'string' &&
            typeof d.asset === 'string' &&
            validAmount(d.amount, d.asset)
            ? d
            : null;
    } catch {
        return null;
    }
}
const draft = readDraft();
if (props.page.receipt && props.page.receipt.requestId === draft?.request_id)
    uni.removeStorageSync(key);
const form = reactive({
    request_id: draft?.request_id ?? requestId(),
    recipient_account_id: draft?.recipient_account_id ?? '',
    amount: draft?.amount ?? '',
    asset: draft?.asset ?? 'USDT',
    current_password: '',
    confirmed: false,
});
const action = useAction(),
    reviewing = ref(false),
    attempted = ref(!!draft);
const recipientEmail = ref('');
const loadingRecipient = ref(false);
const selected = computed(() => props.page.assets.find((a) => a.asset === form.asset));
useSensitiveScreen(() => {
    form.current_password = '';
    form.confirmed = false;
});
function changed() {
    recipientEmail.value = '';
    reviewing.value = false;
    form.request_id = requestId();
    form.confirmed = false;
    action.errors.value = {};
}
function edit() {
    if (action.pending.value || attempted.value) return;
    reviewing.value = false;
    form.current_password = '';
    form.confirmed = false;
    form.request_id = requestId();
}
async function submit() {
    if (action.pending.value || loadingRecipient.value) return;
    if (!reviewing.value) {
        if (!validAmount(form.amount, form.asset)) {
            action.errors.value = { amount: t('Enter a positive transfer amount.') };
            return;
        }
        if (
            !/^\d{12}$/.test(form.recipient_account_id) ||
            form.recipient_account_id === props.page.accountId
        ) {
            action.errors.value = {
                recipient_account_id: t(
                    'The recipient is unavailable. Check the account ID and company.',
                ),
            };
            return;
        }
        action.errors.value = {};
        loadingRecipient.value = true;
        const reviewId = form.request_id;
        try {
            const recipient = await request<{ accountId: string; email: string; asset: string }>(
                '/client/wallet/transfer-recipient?recipient_account_id=' +
                    encodeURIComponent(form.recipient_account_id) +
                    '&asset=' +
                    encodeURIComponent(form.asset),
            );
            if (
                reviewId !== form.request_id ||
                recipient.accountId !== form.recipient_account_id ||
                recipient.asset !== form.asset
            )
                return;
            recipientEmail.value = recipient.email;
            reviewing.value = true;
        } catch (error) {
            action.errors.value = explainError(error);
        } finally {
            loadingRecipient.value = false;
        }
        return;
    }
    if (!recipientEmail.value || !form.confirmed || !form.current_password) return;
    try {
        uni.setStorageSync(key, {
            request_id: form.request_id,
            recipient_account_id: form.recipient_account_id,
            amount: form.amount,
            asset: form.asset,
        });
        if (readDraft()?.request_id !== form.request_id) throw Error();
    } catch {
        action.errors.value = {
            form: t('Transfer retry details could not be saved. Please enable browser storage.'),
        };
        return;
    }
    attempted.value = true;
    await action.submit('/wallet/transfers', { ...form });
    if (action.failureStatus.value === 422) {
        attempted.value = false;
        uni.removeStorageSync(key);
    }
    form.current_password = '';
    form.confirmed = false;
}
</script>
<template>
    <PageShell :title="t('Transfer')" back="/dashboard" active="assets"
        ><view v-if="page.receipt" class="transfer-receipt"
            ><view class="receipt-summary"
                ><view class="receipt-icon"><UiIcon name="circle-check" :size="40" /></view
                ><text class="receipt-title">{{ t('Transfer completed.') }}</text
                ><text class="receipt-amount"
                    >{{ exactAmount(page.receipt.amount) }} {{ page.receipt.asset }}</text
                ></view
            ><view class="receipt-details"
                ><view
                    v-for="item in [
                        { label: 'Sender account ID', value: page.receipt.senderAccountId },
                        { label: 'Recipient account ID', value: page.receipt.recipientAccountId },
                        { label: 'Time', value: dateTime(page.receipt.createdAt) },
                        { label: 'Transfer reference', value: page.receipt.id },
                    ]"
                    :key="item.label"
                    ><text>{{ t(item.label) }}</text
                    ><text selectable>{{ item.value }}</text></view
                ></view
            ><view class="receipt-actions"
                ><button class="primary" @click="go('/wallet/transfer', true)">
                    {{ t('New transfer') }}</button
                ><button class="secondary" @click="go('/dashboard')">
                    {{ t('Back to home') }}
                </button></view
            ></view
        ><text v-else-if="!page.transferAvailable" class="muted">{{
            t('Both accounts need active verified wallets in the same currency.')
        }}</text>
        <form v-else @submit="submit">
            <view class="transfer-balance"
                ><text class="muted">{{ t('Available balance') }}</text
                ><text class="balance-amount"
                    >{{ exactAmount(selected?.amount ?? '0') }} {{ form.asset }}</text
                ></view
            ><FormErrors :errors="action.errors.value" /><template v-if="!reviewing"
                ><SelectField
                    v-model="form.asset"
                    :disabled="loadingRecipient || attempted"
                    :label="t('Currency')"
                    :options="page.assets.map((a) => ({ value: a.asset, label: a.asset }))"
                    @update:model-value="
                        () => {
                            form.amount = '';
                            changed();
                        }
                    "
                /><FormField
                    v-model="form.recipient_account_id"
                    :disabled="loadingRecipient || attempted"
                    :label="t('Recipient account ID')"
                    type="number"
                    :maxlength="12"
                    @update:model-value="changed"
                /><FormField
                    v-model="form.amount"
                    :disabled="loadingRecipient || attempted"
                    :label="t('Transfer quantity')"
                    :description="form.asset"
                    type="digit"
                    @update:model-value="changed"
                /><text v-if="!selected?.available" class="muted">{{
                    t('Both accounts need active verified wallets in the same currency.')
                }}</text
                ><button
                    class="primary"
                    form-type="submit"
                    :disabled="!selected?.available || loadingRecipient"
                >
                    {{ t('Review transfer') }}
                </button></template
            ><Modal
                :open="reviewing"
                :title="t('Review transfer')"
                :busy="action.pending.value"
                @close="
                    () => {
                        reviewing = false;
                        form.current_password = '';
                        form.confirmed = false;
                    }
                "
                ><FormErrors :errors="action.errors.value" /><view class="transfer-review"
                    ><text class="review-title">{{ t('Review transfer') }}</text
                    ><text>{{ t('Recipient account ID') }}: {{ form.recipient_account_id }}</text
                    ><text selectable>{{ t('Recipient email') }}: {{ recipientEmail }}</text
                    ><text
                        >{{ t('Transfer quantity') }}: {{ exactAmount(form.amount) }}
                        {{ form.asset }}</text
                    ><text class="review-note">{{
                        t(
                            'The same amount will be credited to the recipient. Check the account ID carefully; completed transfers cannot be cancelled here.',
                        )
                    }}</text></view
                ><FormField
                    v-model="form.current_password"
                    :label="t('Current password')"
                    password
                    :maxlength="1024"
                    :disabled="action.pending.value"
                /><ConfirmCheck
                    v-model="form.confirmed"
                    :label="
                        t(
                            'I have checked the recipient email and quantity and confirm this transfer.',
                        )
                    "
                    :disabled="action.pending.value"
                /><view class="transfer-buttons"
                    ><button
                        class="primary"
                        form-type="submit"
                        :disabled="
                            action.pending.value || !form.confirmed || !form.current_password
                        "
                    >
                        {{ t('Confirm transfer') }}</button
                    ><button
                        class="secondary"
                        :disabled="action.pending.value || attempted"
                        @click="edit"
                    >
                        {{ t('Edit') }}
                    </button></view
                ><text v-if="attempted" class="retry-copy">{{
                    t(
                        'If the result is unclear, retry this same transfer. Do not start a new request.',
                    )
                }}</text></Modal
            >
        </form></PageShell
    >
</template>
<style scoped>
.transfer-balance {
    margin-bottom: 20px;
}
.balance-amount {
    display: block;
    font-size: 30px;
    line-height: 36px;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.transfer-review {
    display: flex;
    flex-direction: column;
    gap: 12px;
    border: 1px solid #e2e7e4;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 20px;
    overflow-wrap: anywhere;
}
.review-title {
    font-weight: 600;
}
.review-note {
    font-size: 14px;
    line-height: 1.6;
}
.transfer-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}
.primary,
.secondary {
    min-height: 44px;
    border-radius: 999px;
    padding: 10px 24px;
    font-size: 14px;
    line-height: 24px;
}
.retry-copy {
    display: block;
    margin-top: 20px;
    font-size: 14px;
    color: #68736e;
    line-height: 1.6;
}
.transfer-receipt {
    max-width: 480px;
    margin: auto;
}
.receipt-summary {
    text-align: center;
    padding: 32px 12px 28px;
}
.receipt-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 64px;
    height: 64px;
    margin: 0 auto 20px;
    border-radius: 50%;
    background: #e7f7ef;
    color: #1b8a61;
}
.receipt-title {
    display: block;
    font-size: 20px;
    font-weight: 600;
}
.receipt-amount {
    display: block;
    font-size: 32px;
    font-weight: 600;
    line-height: 1.3;
    margin-top: 16px;
    overflow-wrap: anywhere;
}
.receipt-details {
    border-top: 1px solid #e2e7e4;
}
.receipt-details > view {
    display: grid;
    grid-template-columns: 1fr 1.3fr;
    gap: 20px;
    padding: 18px 0;
    border-bottom: 1px solid #e2e7e4;
    font-size: 14px;
}
.receipt-details > view > text:first-child {
    color: #68736e;
}
.receipt-details > view > text:last-child {
    text-align: right;
    overflow-wrap: anywhere;
}
.receipt-actions {
    display: grid;
    gap: 12px;
    margin-top: 28px;
}
</style>
