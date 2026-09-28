<script setup lang="ts">
import { computed, ref } from 'vue';
import { session } from '../lib/session';
import { t, dateTime } from '../lib/i18n';
import { go } from '../lib/navigation';
import { exactAmount, displayMoney } from '../generated/exact-amount';
import type { AssetOverview } from '../lib/assets';
import UiIcon from './UiIcon.vue';
import AssetIcon from './AssetIcon.vue';
import ActivityList from './ActivityList.vue';
import GrowthCampaign from './GrowthCampaign.vue';
const props = defineProps<{ overview: AssetOverview; prerequisite?: string }>();
const expanded = ref(false);
const key = 'balance-hidden:' + session.value?.user?.id;
const hidden = ref(uni.getStorageSync(key) === '1');
function toggle() {
    hidden.value = !hidden.value;
    uni.setStorageSync(key, hidden.value ? '1' : '0');
}
const usdt = computed(() => props.overview.assets.find((a) => a.asset === 'USDT'));
const tabs = computed(() => [
    ...(usdt.value
        ? [
              {
                  id: 'deposit',
                  label: 'Security deposit',
                  amount: usdt.value.deposit,
                  asset: 'USDT',
                  path: '/security-deposit',
                  icon: 'shield-check',
              },
          ]
        : []),
    ...props.overview.assets.map((a) => ({
        id: a.asset,
        label: a.asset,
        amount: a.available,
        asset: a.asset,
        path: '/funds?asset=' + a.asset,
        icon: '',
    })),
    ...(usdt.value && expanded.value
        ? [
              {
                  id: 'wealth',
                  label: 'Wealth management',
                  amount: null,
                  asset: 'USDT',
                  path: '/wealth',
                  icon: 'coins',
              },
          ]
        : []),
]);
const recent = computed(() =>
    props.overview.assets
        .flatMap((a) => a.activity.map((row) => ({ ...row, asset: a.asset })))
        .sort((a, b) => b.time.localeCompare(a.time) || b.id.localeCompare(a.id))
        .slice(0, 5),
);
const shortcuts = computed(() => [
    {
        label: 'Top up',
        icon: 'arrow-down',
        path:
            props.prerequisite ??
            '/assets/operate?mode=deposit&asset=' +
                (props.overview.assets.find((a) => a.rails.some((r) => r.deposit))?.asset ??
                    'USDT'),
    },
    {
        label: 'Withdraw',
        icon: 'arrow-up',
        path:
            props.prerequisite ??
            '/assets/operate?mode=withdrawal&asset=' +
                (props.overview.assets.find((a) => a.rails.some((r) => r.withdrawal))?.asset ??
                    'USDT'),
    },
    {
        label: 'Exchange',
        icon: 'arrow-left-right',
        path:
            props.prerequisite ??
            '/assets/operate?mode=exchange&asset=' +
                (props.overview.assets.find((a) => a.exchange)?.asset ?? 'USDC'),
    },
    {
        label: 'Transfer',
        icon: 'arrow-left-right',
        path: props.prerequisite ?? (usdt.value?.transfer ? '/wallet/transfer' : '/wallet'),
    },
]);
const priceLabel = computed(() =>
    props.overview.estimate === null
        ? t('Valuation unavailable. Original balances are unchanged.')
        : props.overview.updatedAt
          ? t('Prices updated: {{time}}', { time: dateTime(props.overview.updatedAt) })
          : '',
);
function show(value: string | null) {
    return hidden.value ? '••••••' : value === null ? '—' : exactAmount(value);
}
</script>
<template>
    <view class="asset-center"
        ><view class="balance-hero"
            ><view class="balance-label"
                ><text>{{ t('Estimated total assets') }}</text
                ><button
                    class="visibility"
                    :aria-label="t(hidden ? 'Show balance' : 'Hide balance')"
                    @click="toggle"
                >
                    <UiIcon :name="hidden ? 'eye-off' : 'eye'" :size="18" /></button></view
            ><view class="balance-value"
                ><text>{{
                    overview.estimate === null
                        ? '—'
                        : '≈ ' + (hidden ? '••••••' : displayMoney(overview.estimate))
                }}</text
                ><text class="balance-currency">USDT</text></view
            ><text v-if="priceLabel" class="price-label">{{ priceLabel }}</text></view
        ><view class="asset-actions"
            ><button
                v-for="shortcut in shortcuts"
                :key="shortcut.label"
                class="asset-action"
                @click="go(shortcut.path)"
            >
                <view class="action-icon"><UiIcon :name="shortcut.icon" :size="22" /></view
                ><text>{{ t(shortcut.label) }}</text>
            </button></view
        ><view v-if="!overview.activation.qualified" class="activation-notice"
            ><UiIcon name="shield-check" :size="20" /><view class="grow"
                ><text class="activation-title">{{ t('Account pending activation') }}</text
                ><text class="activation-copy">{{
                    t('Pay a member deposit or choose an agent level to activate.')
                }}</text></view
            ><button @click="go('/promotion/membership')">{{ t('Activate now') }}</button></view
        ><view class="account-panel"
            ><view class="panel-heading"
                ><text>{{ t('Accounts') }}</text
                ><button class="more" @click="expanded = !expanded">
                    {{ t(expanded ? 'Show less' : 'More')
                    }}<UiIcon name="chevron-right" :size="14" /></button></view
            ><view class="account-track" :class="{ expanded }"
                ><button
                    v-for="tab in tabs"
                    :key="tab.id"
                    class="account-tile"
                    :aria-label="t(tab.label)"
                    @click="go(tab.path)"
                >
                    <view v-if="tab.icon" class="managed-symbol"
                        ><UiIcon :name="tab.icon" :size="20" /></view
                    ><AssetIcon v-else :asset="tab.asset" /><view
                        v-if="tab.id !== 'wealth'"
                        class="tile-balance"
                        ><text>{{ show(tab.amount) }}</text
                        ><text v-if="tab.icon" class="tile-currency">USDT</text></view
                    ><text class="tile-label">{{ t(tab.label) }}</text>
                </button></view
            ></view
        ><GrowthCampaign /><view class="activity-panel"
            ><view class="panel-heading"
                ><text class="semibold">{{ t('Latest transactions') }}</text
                ><button class="more" @click="go('/funds')">
                    {{ t('More') }}<UiIcon name="chevron-right" :size="14" /></button></view
            ><ActivityList :items="recent" :hidden="hidden" /></view
    ></view>
</template>
<style scoped>
.asset-center {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.balance-hero {
    padding: 4px 8px;
    text-align: center;
}
.balance-label {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: #68736e;
}
.visibility {
    width: 44px;
    height: 44px;
    padding: 0;
    background: transparent;
    display: flex;
    align-items: center;
    justify-content: center;
}
.balance-value {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    align-items: baseline;
    column-gap: 8px;
    font-size: 30px;
    font-weight: 600;
    letter-spacing: -0.025em;
    line-height: 36px;
    overflow-wrap: anywhere;
}
.balance-currency {
    font-size: 16px;
    font-weight: 400;
    white-space: nowrap;
}
.price-label {
    display: block;
    margin-top: 8px;
    font-size: 11px;
    color: #68736e;
}
.asset-actions {
    display: flex;
    justify-content: space-evenly;
    gap: 8px;
    padding: 0 4px 4px;
}
.asset-action {
    display: flex;
    min-width: 0;
    flex: 1;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    border-radius: 12px;
    font-size: 12px;
    color: #68736e;
    background: transparent;
    padding: 0;
    line-height: 1.5;
}
.action-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    background: #171915;
    border-radius: 50%;
}
.action-icon :deep(image) {
    filter: brightness(0) invert(1);
}
.activation-notice {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #f2f6ef;
    border-radius: 16px;
    padding: 12px 16px;
}
.grow {
    flex: 1;
    min-width: 0;
}
.activation-title {
    display: block;
    font-size: 14px;
    font-weight: 600;
}
.activation-copy {
    display: block;
    margin-top: 4px;
    font-size: 12px;
    line-height: 20px;
    color: #68736e;
}
.activation-notice button {
    flex-shrink: 0;
    background: #193c34;
    color: white;
    border-radius: 999px;
    padding: 10px 12px;
    font-size: 12px;
    line-height: 16px;
}
.account-panel,
.activity-panel {
    border-radius: 16px;
    background: #fff;
    padding: 0 16px 16px;
}
.activity-panel {
    padding-bottom: 8px;
}
.panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    min-height: 56px;
    font-size: 16px;
    font-weight: 500;
}
.more {
    display: flex;
    align-items: center;
    gap: 4px;
    min-height: 44px;
    border-radius: 8px;
    background: transparent;
    font-size: 12px;
    color: #68736e;
    padding: 0;
    line-height: 20px;
}
.account-track {
    display: flex;
    gap: 12px;
    overflow-x: auto;
    padding-bottom: 4px;
}
.account-track.expanded {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
.account-tile {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    min-width: 96px;
    width: calc((100% - 24px) / 3);
    flex-shrink: 0;
    background: #f7f6f1;
    border-radius: 12px;
    padding: 12px 8px;
    line-height: 20px;
}
.expanded .account-tile {
    min-width: 0;
    width: 100%;
}
.managed-symbol {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #d1fae5;
}
.tile-balance {
    margin-top: 8px;
    display: flex;
    justify-content: center;
    align-items: baseline;
    gap: 4px;
    width: 100%;
    min-width: 0;
    font-size: 14px;
    font-weight: 500;
}
.tile-balance > text:first-child {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.tile-currency {
    font-size: 10px;
    font-weight: 400;
    flex-shrink: 0;
}
.tile-label {
    display: block;
    margin-top: 4px;
    width: 100%;
    overflow-wrap: anywhere;
    font-size: 12px;
    color: #68736e;
}
.semibold {
    font-weight: 600;
}
@media (min-width: 640px) {
    .balance-value {
        font-size: 36px;
        line-height: 40px;
    }
    .action-icon {
        width: 56px;
        height: 56px;
    }
    .account-panel,
    .activity-panel {
        padding-left: 20px;
        padding-right: 20px;
    }
    .account-track.expanded {
        grid-template-columns: repeat(6, minmax(0, 1fr));
    }
    .account-tile {
        flex: 1;
    }
}
</style>
