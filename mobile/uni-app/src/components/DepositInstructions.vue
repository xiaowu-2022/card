<script setup lang="ts">
import { ref, computed, onBeforeUnmount } from 'vue';
import { t, dateTime } from '../lib/i18n';
import { go } from '../lib/navigation';
import { exactAmount } from '../generated/exact-amount';
import { assetNetworkLabel } from '../generated/asset-network';
import Modal from './Modal.vue';
import AssetIcon from './AssetIcon.vue';
import QrImage from './QrImage.vue';
const props = defineProps<{
    asset: string;
    amount: string;
    network?: string | null;
    address?: string | null;
    state: string;
    expiresAt?: string | null;
    payable: boolean;
    newHref: string;
}>();
const open = ref(true),
    now = ref(Date.now());
const timer = setInterval(() => (now.value = Date.now()), 1000);
onBeforeUnmount(() => clearInterval(timer));
const seconds = computed(() =>
    props.expiresAt ? Math.max(0, Math.floor((Date.parse(props.expiresAt) - now.value) / 1000)) : 0,
);
const countdown = computed(
    () =>
        String(Math.floor(seconds.value / 60)).padStart(2, '0') +
        ':' +
        String(seconds.value % 60).padStart(2, '0'),
);
const networkLabel = computed(() => assetNetworkLabel(props.network, props.asset));
const description = computed(() =>
    t(
        'Send the exact amount shown, including all decimals, using {{network}} only. The full amount will be credited with no identification fee; other amounts cannot be credited automatically.',
        { network: networkLabel.value ?? '' },
    ),
);
const expires = computed(() =>
    t('Valid until {{time}}', { time: dateTime(props.expiresAt ?? '') }),
);
function copy(value: string, label: string) {
    uni.setClipboardData({
        data: value,
        showToast: false,
        success: () =>
            uni.showToast({ title: t('{{label}} copied', { label: t(label) }), icon: 'none' }),
        fail: () =>
            uni.showToast({
                title: t('Could not copy. Please select and copy the value manually.'),
                icon: 'none',
            }),
    });
}
</script>
<template>
    <view class="deposit-card"
        ><text class="bold">{{ t(state === 'Completed' ? 'Actual receipt' : state) }}</text
        ><text>{{ exactAmount(amount) }} {{ asset }} · {{ networkLabel }}</text
        ><button class="primary" @click="open = true">{{ t('View payment instructions') }}</button
        ><text class="text-link" @click="go(newHref)">{{ t('Start a new request') }}</text></view
    ><Modal
        :open="open"
        :title="t('Top up') + ' · ' + asset"
        :description="description"
        @close="open = false"
        ><view class="deposit-details"
            ><view class="deposit-status"
                ><AssetIcon :asset="asset" /><view
                    ><text class="bold block">{{ t(state === 'Completed' ? 'Actual receipt' : state) }}</text
                    ><text class="muted">{{ asset }} · {{ networkLabel }}</text></view
                ></view
            ><text class="small muted">{{ t('Amount to send') }}</text
            ><text class="deposit-amount"
                >{{ exactAmount(amount) }} <text class="currency">{{ asset }}</text></text
            ><template v-if="payable && seconds > 0 && address"
                ><view class="qr"><QrImage :value="address" /></view
                ><text class="address" selectable>{{ address }}</text
                ><view class="copy-buttons"
                    ><button class="secondary" @click="copy(address, 'Address')">
                        {{ t('Copy address') }}</button
                    ><button class="secondary" @click="copy(exactAmount(amount), 'Amount')">
                        {{ t('Copy amount') }}
                    </button></view
                ><view class="deposit-note"
                    ><text>{{
                        t(
                            'After depositing to the address above 👆, your deposit succeeds after 3 network confirmations!',
                        )
                    }}</text
                    ><text class="text-link" @click="go('/support')">{{
                        t(
                            'If your deposit has not arrived after 5 minutes, please contact customer support.',
                        )
                    }}</text></view
                ></template
            ><view class="deposit-meta"
                ><view
                    ><text>{{ t('Network') }}</text
                    ><text>{{ networkLabel }}</text></view
                ><view
                    ><text>{{ t('Wallet credit amount') }}</text
                    ><text>{{ exactAmount(amount) }} {{ asset }}</text></view
                ><view
                    ><text>{{ t('Time remaining') }}</text
                    ><text>{{ countdown }}</text></view
                ></view
            ><text v-if="expiresAt" class="small muted">{{ expires }}</text
            ><text class="text-link" @click="go(newHref)">{{
                t('Start a new request')
            }}</text></view
        ></Modal
    >
</template>
<style scoped>
.deposit-card {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 20px;
    background: #fff;
    border-radius: 16px;
}
.bold {
    font-weight: 600;
}
.block {
    display: block;
}
.deposit-details {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.deposit-status {
    display: flex;
    align-items: center;
    gap: 12px;
}
.small {
    font-size: 12px;
}
.deposit-amount {
    font-size: 30px;
    font-weight: 600;
    overflow-wrap: anywhere;
    line-height: 36px;
}
.currency {
    font-size: 16px;
}
.qr {
    margin: 0 auto;
    padding: 12px;
    background: white;
    border-radius: 12px;
}
.address {
    display: block;
    overflow-wrap: anywhere;
    background: #f0f3f1;
    border-radius: 12px;
    padding: 12px;
    font-family: monospace;
    font-size: 14px;
}
.copy-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.copy-buttons button {
    line-height: 24px;
    min-height: 44px;
    padding: 10px;
    font-size: 14px;
}
.deposit-note {
    display: flex;
    flex-direction: column;
    gap: 4px;
    background: #f0f3f1;
    border-radius: 12px;
    padding: 12px;
    font-size: 14px;
    line-height: 24px;
}
.text-link {
    display: block;
    text-decoration: underline;
    text-underline-offset: 4px;
    font-size: 14px;
    text-align: center;
}
.deposit-note .text-link {
    text-align: left;
}
.deposit-meta > view {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 0;
    font-size: 14px;
    border-bottom: 1px solid #e2e7e4;
}
.deposit-meta > view:last-child {
    border: 0;
}
.deposit-meta > view > text:last-child {
    text-align: right;
    overflow-wrap: anywhere;
}
</style>
