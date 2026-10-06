<script setup lang="ts">
import { staticAsset } from '../lib/origin';
import { computed } from 'vue';
import { t } from '../lib/i18n';
import { displayMoney } from '../generated/exact-amount';
import { systemMoney } from '../generated/system-money';
const props = defineProps<{
    name: string;
    currency: string;
    bin?: string;
    maskedPan?: string;
    balance?: string | null;
    expiry?: string | null;
    state?: string;
    preview?: boolean;
}>();
const name = computed(() =>
    ['Mille Card', 'U Card'].includes(props.name) ? t('U Card') : props.name,
);
</script>
<template>
    <view :class="{ 'user-card-product-preview-wrap': preview }"
        ><view
            class="user-card-visual"
            :style="{ backgroundImage: `url(${staticAsset('images/cards/spec-pay-gold-background.jpg')})` }"
            :class="{
                'user-card-product-preview': preview,
                'user-card-visual-frozen': state === 'Frozen',
            }"
            ><view class="user-card-face-header"
                ><view class="user-card-brand"
                    ><image
                        class="brand-icon"
                        :src="staticAsset('icons/spec-pay-mark.svg')"
                        mode="aspectFit"
                    /><text>Spec Pay</text></view
                ><text v-if="!preview && state !== 'Normal'" class="user-card-state">{{
                    t(state ?? 'Awaiting confirmation')
                }}</text></view
            ><view class="user-card-chip-row"
                ><image class="user-card-chip" :src="staticAsset('images/cards/gold-chip.svg')" mode="widthFix" /><view
                    ><text class="user-card-name">{{ name }}</text
                    ><text class="user-card-edition">SPEC U CARD</text></view
                ></view
            ><view v-if="preview" class="user-card-preview-footer"
                ><text>{{ currency }}{{ bin ? ' · BIN ' + bin : '' }}</text
                ><image class="user-card-preview-network" :src="staticAsset('images/cards/mastercard.svg')" mode="widthFix" /></view
            ><template v-else
                ><text class="user-card-number">{{ maskedPan }}</text
                ><view class="user-card-face-footer"
                    ><view
                        ><text class="user-card-label">{{ t('Balance') }}</text
                        ><text class="balance">{{
                            balance
                                ? currency === 'USD'
                                    ? systemMoney(balance)
                                    : displayMoney(balance) + ' ' + currency
                                : t('Pending sync')
                        }}</text></view
                    ><view class="user-card-expiry"
                        ><text class="user-card-label">{{ t('Expiry') }}</text
                        ><text class="expiry">{{ expiry ?? '—' }}</text></view
                    ><image
                        class="user-card-network"
                        :src="staticAsset('images/cards/mastercard.svg')"
                        mode="widthFix" /></view></template></view
    ></view>
</template>
<style scoped>
/* Pixel fallbacks keep artwork bounded on WebViews without container-query units. */
.user-card-visual {
    box-sizing: border-box;
    width: 100%;
    max-width: 562px;
    min-height: 228px;
    aspect-ratio: 1.586;
    margin: auto;
    border-radius: 22px;
    background-color: #111312;
    background-position: center;
    background-size: 100% 100%;
    background-repeat: no-repeat;
    color: #ecd5a3;
    box-shadow:
        inset 0 0 0 1px rgb(244 218 160 / 22%),
        inset 0 1px 0 rgb(239 225 196 / 18%),
        0 5px 18px rgb(92 70 25 / 20%);
    padding: 22px;
    padding: clamp(22px, 4.267cqw, 32px);
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.user-card-face-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}
.user-card-brand {
    display: flex;
    align-items: center;
    gap: 9px;
    color: #f5e5be;
    font-size: 22px;
    font-size: clamp(22px, 4.4cqw, 30px);
    font-weight: 650;
    letter-spacing: -0.045em;
    line-height: 1.2;
    text-shadow: 0 1px 2px rgb(0 0 0 / 20%);
}
.user-card-brand .brand-icon {
    width: 23px;
    height: 29px;
    flex-shrink: 0;
}
.user-card-chip-row {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: auto;
}
.user-card-chip {
    max-width: 58px;
    width: 42px;
    width: clamp(42px, 9cqw, 58px);
    height: auto;
    flex-shrink: 0;
}
.user-card-name {
    color: #ead3a0;
    font-size: 15px;
    font-size: clamp(15px, 3cqw, 21px);
    font-weight: 600;
    line-height: 1.3;
}
.user-card-edition {
    margin-top: 4px;
    color: #cab788;
    font-size: 7px;
    letter-spacing: 0.38em;
}
.user-card-state {
    padding: 4px 9px;
    border-radius: 999px;
    background: #292922;
    color: #efdfb6;
    font-size: 11px;
    line-height: 1.5;
    text-align: center;
    max-width: 45%;
}
.user-card-number {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    margin-top: 0;
    font-size: 20px;
    font-size: clamp(20px, 4cqw, 28px);
    font-weight: 600;
    letter-spacing: 0.09em;
    line-height: 1.4;
    color: #ecd5a3;
    text-shadow: 0 1px 1px rgb(0 0 0 / 70%);
    overflow-wrap: anywhere;
}
.user-card-face-footer {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 16px;
    align-items: end;
}
.user-card-label {
    color: #d5c9ad;
    font-size: 11px;
}
.user-card-expiry {
    text-align: left;
}
.user-card-network {
    max-width: 105px;
    flex-shrink: 0;
    width: 70px;
    width: clamp(70px, 15cqw, 105px);
    height: auto;
    align-self: end;
}
.user-card-face-footer > view {
    min-width: 0;
    overflow-wrap: anywhere;
}
.user-card-visual-frozen {
    background-blend-mode: luminosity;
    background-color: #596168;
}
.user-card-product-preview-wrap {
    container-type: inline-size;
    width: 100%;
    max-width: 320px;
    flex-shrink: 0;
    margin-inline: auto;
}
.user-card-visual.user-card-product-preview {
    min-height: 0;
    border-radius: 16px;
    padding: 14px;
    padding: clamp(14px, 6cqw, 20px);
    gap: 12px;
}
.user-card-product-preview .user-card-brand {
    font-size: 18px;
    font-size: clamp(18px, 7cqw, 23px);
    gap: 6px;
}
.user-card-product-preview .user-card-brand .brand-icon {
    width: 18px;
    height: 23px;
}
.user-card-product-preview .user-card-chip-row {
    gap: 10px;
}
.user-card-product-preview .user-card-chip {
    width: 30px;
    width: clamp(30px, 13cqw, 42px);
}
.user-card-product-preview .user-card-chip-row > view {
    min-width: 0;
    overflow-wrap: anywhere;
}
.user-card-product-preview .user-card-name {
    font-size: 13px;
    font-size: clamp(13px, 5cqw, 16px);
}
.user-card-preview-footer {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 8px;
    margin-top: auto;
    font-size: 11px;
    color: #d5c9ad;
}
.user-card-preview-footer text {
    min-width: 0;
    overflow-wrap: anywhere;
}
.user-card-preview-network {
    max-width: 64px;
    flex-shrink: 0;
    width: 48px;
    width: clamp(48px, 20cqw, 64px);
    height: auto;
}
.user-card-name,
.user-card-edition,
.user-card-label {
    display: block;
}
.balance {
    display: block;
    font-size: 18px;
    font-weight: 600;
    margin-top: 4px;
    line-height: 26px;
    overflow-wrap: anywhere;
}
.expiry {
    display: block;
    font-size: 14px;
    margin-top: 4px;
}
.user-card-number {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-family: ui-monospace, monospace;
}
.user-card-visual-frozen .user-card-state {
    background: #eef1f3;
    color: #303b43;
    box-shadow: 0 0 0 1px #87929a;
}
</style>
