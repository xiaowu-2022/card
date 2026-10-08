<script setup lang="ts">
import { staticAsset } from '../../lib/origin';
import { computed, ref, nextTick } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import PageShell from '../../components/PageShell.vue';
import LoadState from '../../components/LoadState.vue';
import Modal from '../../components/Modal.vue';
import IdentityVerificationDialog from '../../components/IdentityVerificationDialog.vue';
import SelectField from '../../components/SelectField.vue';
import StatusBanner from '../../components/StatusBanner.vue';
import FormErrors from '../../components/FormErrors.vue';
import UiIcon from '../../components/UiIcon.vue';
import CardVisual from '../../components/CardVisual.vue';
import CardApplication from '../../components/CardApplication.vue';
import CardManagement from '../../components/CardManagement.vue';
import CardTransactions from '../../components/CardTransactions.vue';
import PhysicalCardActivation from '../../components/PhysicalCardActivation.vue';
import { getPage, useAction } from '../../lib/client';
import { setCurrentPage } from '../../lib/api';
import { useScreen } from '../../lib/screen';
import { useSensitiveScreen } from '../../lib/sensitive';
import { go } from '../../lib/navigation';
import { t } from '../../lib/i18n';
import { displayMoney } from '../../generated/exact-amount';
import type { CardsPage, Product } from '../../lib/card-types';
const page = ref<CardsPage | null>(null),
    choosing = ref(false),
    selectedForms = ref<Record<string, string>>({}),
    verification = ref(false),
    applicationProduct = ref<string | null>(null),
    action = useAction();
let entryAnchor = '';
onLoad((options) => {
    if (options?._anchor === 'card-setup') entryAnchor = 'card-setup';
});
let generation = 0,
    first = true;
const { loading, failed, refresh } = useScreen(async () => {
    const run = ++generation;
    setCurrentPage('/cards');
    const data = await getPage<CardsPage>('/cards');
    if (run !== generation) return;
    if ('redirect' in data) {
        go(String(data.redirect), true);
        return;
    }
    page.value = data.props;
    if (first) {
        verification.value = !data.props.kycApproved;
        first = false;
    }
    if (entryAnchor) {
        await nextTick();
        uni.pageScrollTo({ selector: '#' + entryAnchor, duration: 0 });
        entryAnchor = '';
    }
}, { refreshOnShow: () => !page.value || (!applicationProduct.value && !choosing.value && !verification.value) });
const unresolved = computed(() =>
        page.value?.issueOrders.find((o) => ['creating', 'unknown'].includes(o.state)),
    ),
    canOpen = computed(
        () =>
            !!page.value?.kycApproved &&
            page.value.providerAvailable &&
            !unresolved.value &&
            !page.value.refundPending,
    ),
    activeApplication = computed(() =>
        page.value?.cardholder.id && page.value.cardholder.state !== 'not_available'
            ? page.value.cardholder
            : undefined,
    );
const cardKey = computed(
    () =>
        page.value?.cards
            .map((c) => c.id + ':' + c.balance + ':' + c.pendingOperationCount)
            .sort()
            .join(',') ?? '',
);
useSensitiveScreen(() => {
    choosing.value = false;
    applicationProduct.value = null;
}, { retainUntilUnmount: true });
async function syncIssue() {
    if (!unresolved.value) return;
    await action.submit(
        '/cards/issues/' + unresolved.value.id + '/sync',
        {},
        { navigate: false, success: refresh },
    );
}
function choose(product: Product) {
    choosing.value = false;
    applicationProduct.value = product.id;
}
function formFactor(product: Product) {
    return activeApplication.value?.productId === product.id
        ? (activeApplication.value.formFactor ?? 'virtual_card')
        : (selectedForms.value[product.id] ?? product.supportedFormFactors?.[0] ?? 'virtual_card');
}
const pendingDescription = computed(() =>
    t(
        "We're confirming the card status. Your reserved funds remain protected; do not open another card.",
    ),
);
function requirements() {
    uni.pageScrollTo({ selector: '#card-setup', duration: 250 });
}
</script>
<template>
    <PageShell :title="t('Cards')" active="cards"
        ><LoadState :loading="loading && !page" :failed="failed" @retry="refresh"
            ><view v-if="page" class="cards-page"
                ><template v-if="!page.cards.length && page.products.length && !canOpen"
                    ><text class="intro-title">{{ t('Apply for a Mastercard U Card') }}</text
                    ><view class="intro-stage"
                        ><view class="intro-card"
                            ><image
                                :src="staticAsset('images/marketing/spec-pay-application-card.png')"
                                mode="widthFix"
                                :aria-label="t('Card design illustration')" /></view></view
                    ><button
                        class="intro-action"
                        @click="!page.kycApproved ? (verification = true) : requirements()"
                    >
                        {{
                            t(
                                !page.kycApproved
                                    ? 'Verify identity'
                                    : 'View application requirements',
                            )
                        }}
                    </button></template
                ><view
                    ><view class="section-header"
                        ><text class="heading">{{ t('Your cards') }}</text
                        ><button
                            v-if="canOpen && page.products.length"
                            class="primary apply"
                            @click="choosing = true"
                        >
                            <UiIcon name="plus" :size="16" color="#ffffff" />{{
                                t('Apply for a card')
                            }}
                        </button></view
                    ><view v-if="page.cards.length" class="card-list"
                        ><view v-for="card in page.cards" :key="card.id" class="card-group"
                            ><CardVisual
                                :name="card.productName"
                                :currency="card.currency"
                                :masked-pan="card.maskedPan"
                                :balance="card.balance"
                                :expiry="card.expiry"
                                :state="card.state" /><text
                                v-if="card.formFactor === 'physical_card'"
                                class="physical-meta"
                                >{{ t('Physical card')
                                }}{{
                                    card.produceStatus
                                        ? ' · ' +
                                          t(
                                              card.produceStatus === 'produced'
                                                  ? 'Card produced'
                                                  : 'Card production pending',
                                          )
                                        : ''
                                }}{{
                                    card.trackingNumber
                                        ? ' · ' + t('Tracking number') + ': ' + card.trackingNumber
                                        : ''
                                }}</text
                            ><PhysicalCardActivation
                                v-if="card.management?.includes('activate')"
                                :card-id="card.id"
                                :status="card.activationStatus"
                                @reload="refresh" /><CardManagement
                                :card="card"
                                :available-balance="page.availableBalance"
                                :wallet-asset="page.walletAsset"
                                @reload="refresh" /></view></view
                    ><view v-else class="empty-card no-cards"
                        ><view class="empty-icon"
                            ><UiIcon name="cards" :size="20" color="#68736e" /></view
                        ><text class="empty-title">{{ t('No cards yet') }}</text
                        ><text class="muted">{{
                            t('Your Mastercard U Cards will appear here.')
                        }}</text></view
                    ></view
                ><CardTransactions :key="cardKey" :card-ids="page.cards.map((c) => c.id)" /><view
                    id="card-setup" /><StatusBanner
                    v-if="!page.providerAvailable"
                    tone="warning"
                    :title="t('Card setup unavailable')"
                    :description="
                        t(
                            'Card service is unavailable. No application or wallet hold has been created.',
                        )
                    " /><view v-if="unresolved" class="unresolved"
                    ><UiIcon name="refresh-cw" :size="28" color="#277660" /><text
                        class="unresolved-title"
                        >{{ t('Creating your card') }}</text
                    ><text class="muted">{{ pendingDescription }}</text
                    ><button class="secondary" :disabled="action.pending.value" @click="syncIssue">
                        <UiIcon name="refresh-cw" :size="16" />{{ t('Refresh status') }}
                    </button></view
                ><FormErrors :errors="action.errors.value" /><IdentityVerificationDialog
                    :open="verification"
                    @close="
                        verification = false;
                        go('/dashboard', true);
                    " /><Modal
                    wide
                    :open="choosing && canOpen"
                    :title="t('Choose a card')"
                    @close="choosing = false"
                    ><text v-if="activeApplication" class="application-note muted">{{
                        t(
                            'Continue your existing application before choosing another card product.',
                        )
                    }}</text
                    ><view v-for="product in page.products" :key="product.id" class="product"
                        ><CardVisual
                            :name="product.name"
                            :currency="product.cardCurrency"
                            :bin="product.bin"
                            preview
                        /><SelectField
                            v-if="(product.supportedFormFactors ?? ['virtual_card']).length > 1"
                            :model-value="formFactor(product)"
                            :label="t('Card type')"
                            :disabled="!!activeApplication"
                            :options="
                                (product.supportedFormFactors ?? ['virtual_card']).map((value) => ({
                                    value,
                                    label: t(
                                        value === 'physical_card'
                                            ? 'Physical card'
                                            : 'Virtual card',
                                    ),
                                }))
                            "
                            @update:model-value="(value) => (selectedForms[product.id] = value)"
                        /><view class="fee"
                            ><text class="muted">{{ t('Opening fee') }}</text
                            ><text>$ {{ displayMoney(product.openingFee) }}</text></view
                        ><view class="fee"
                            ><text class="muted">{{ t('Minimum initial balance') }}</text
                            ><text
                                >{{ displayMoney(product.minimumInitialLoad) }}
                                {{ product.cardCurrency }}</text
                            ></view
                        ><button
                            class="primary wide"
                            :disabled="
                                !product.readyForSetup ||
                                !!(activeApplication && activeApplication.productId !== product.id)
                            "
                            @click="choose(product)"
                        >
                            {{ t('Open this card') }}</button
                        ><text v-if="!product.readyForSetup" class="application-note muted">{{
                            t(product.guidance)
                        }}</text></view
                    ><view v-if="!page.products.length" class="empty-card"
                        ><text>{{ t('No card products available') }}</text
                        ><text class="muted">{{
                            t('Your card program is not currently accepting new requests.')
                        }}</text></view
                    ></Modal
                ><template v-for="product in page.products" :key="product.id"
                    ><CardApplication
                        :birth-date-required="page.cardholderBirthDateRequired"
                        v-if="applicationProduct === product.id"
                        :product="product"
                        :selected-form-factor="formFactor(product)"
                        :available-balance="page.availableBalance"
                        :application="
                            activeApplication?.productId === product.id
                                ? activeApplication
                                : undefined
                        "
                        :open="applicationProduct === product.id"
                        @close="applicationProduct = null"
                        @reload="refresh" /></template></view></LoadState
    ></PageShell>
</template>
<style scoped>
.cards-page {
    display: block;
}
.cards-page > view:not(.intro-stage):not(#card-setup):not(:last-child) {
    margin-bottom: 28px;
}
.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
}
.heading {
    font-size: 18px;
    font-weight: 600;
    line-height: 28px;
}
.apply {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    min-height: 40px;
    padding: 8px 12px;
    line-height: 24px;
    margin: 0;
}
.card-list {
    display: flex;
    flex-direction: column;
    gap: 24px;
}
.card-group {
    width: min(100%, 562px);
    margin: auto;
    border-radius: 24px;
    background: white;
    box-shadow:
        0 0 0 1px #1719150d,
        0 3px 10px #17191508;
}
.physical-meta {
    display: block;
    padding: 12px 16px 0;
    font-size: 14px;
    line-height: 22px;
    overflow-wrap: anywhere;
}
.empty-card {
    padding: 32px 20px;
    background: white;
    border-radius: 16px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    font-size: 16px;
    line-height: 24px;
}
.empty-card > .muted {
    font-size: 14px;
}
.intro-title {
    display: block;
    width: min(75cqw, 562px);
    margin-inline: auto;
    font-size: clamp(24px, 5.333cqw, 40px);
    line-height: 1.5;
    font-weight: 600;
    text-align: center;
    margin-top: 0;
}
.intro-stage {
    padding-block: clamp(12px, 3.2cqw, 24px);
}
.intro-card {
    width: min(100%, 562px);
    margin: auto;
    position: relative;
    aspect-ratio: 520 / 303;
    overflow: hidden;
    border-radius: 4.6% / 7.9%;
}
.intro-card image {
    display: block;
    width: 117.885%;
    max-width: none;
    position: absolute;
    left: -8.269%;
    top: -12.211%;
}
.intro-action {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 52px;
    height: min(13.867cqw, 104px);
    padding: 0;
    border-radius: 999px;
    background: #171915;
    color: white;
    font-size: clamp(16px, 3.733cqw, 28px);
    line-height: 1.5;
    width: min(75cqw, 562px);
    margin: min(6.4cqw, 48px) auto 0;
}
.no-cards {
    padding: 36px 20px;
    background: transparent;
    border-radius: 0;
    gap: 0;
    font-size: 14px;
    line-height: 21px;
}
.empty-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #f0f3f1;
}
.empty-title {
    margin-top: 12px;
    font-weight: 600;
}
.no-cards > .muted {
    margin-top: 4px;
}
.unresolved {
    padding: 20px;
    border: 1px solid #e2e7e4;
    border-radius: 24px;
    background: white;
}
.unresolved-title {
    display: block;
    font-size: 20px;
    line-height: 28px;
    font-weight: 600;
    margin-top: 16px;
}
.unresolved > .muted {
    display: block;
    font-size: 14px;
    line-height: 22px;
    margin-top: 8px;
}
.unresolved button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    min-height: 44px;
    padding: 10px 16px;
    margin: 20px 0 0;
    font-size: 14px;
    line-height: 22px;
    background: white;
    color: #171c19;
    border: 1px solid #e2e7e4;
    border-radius: 6px;
}
@media (min-width: 640px) {
    .cards-page > view:not(.intro-stage):not(#card-setup):not(:last-child) {
        margin-bottom: 36px;
    }
    .unresolved {
        padding: 28px;
    }
    .cards-page .unresolved button {
        width: auto;
        display: inline-flex;
    }
}
.application-note {
    display: block;
    font-size: 14px;
    line-height: 22px;
    margin-bottom: 16px;
}
.product {
    padding: 16px;
    border: 1px solid #e2e7e4;
    border-radius: 16px;
    background: white;
    margin-bottom: 20px;
}
.product > :deep(.select-field) {
    margin-top: 16px;
}
.fee {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 8px;
    padding: 12px 0;
    border-bottom: 1px solid #e2e7e4;
    font-size: 14px;
    line-height: 22px;
}
.fee > text:last-child {
    font-weight: 600;
}
.wide {
    width: 100%;
    margin-top: 16px;
    font-size: 14px;
    line-height: 24px;
    min-height: 44px;
    padding: 10px 16px;
}
</style>
