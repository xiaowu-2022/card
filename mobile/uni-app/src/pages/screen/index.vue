<script setup lang="ts">
import { ref, nextTick, onErrorCaptured, type Component } from 'vue';
// #ifdef H5
import { lazyScreen } from '../../lib/lazy-screen';
// #endif
import { onLoad, onShow, onHide, onPageScroll } from '@dcloudio/uni-app';
import { getPage, ensureBootstrap, explainError, type ClientPage } from '../../lib/client';
import { clearSession } from '../../lib/session';
import { ApiError, setCurrentPage } from '../../lib/api';
import { t } from '../../lib/i18n';
import { go } from '../../lib/navigation';
// #ifndef H5
import NativeLanding from '../../screens/Landing.vue';
import NativeRegistration from '../../screens/Registration.vue';
import NativePasswordRecovery from '../../screens/PasswordRecovery.vue';
import NativeSettings from '../../screens/Settings.vue';
import NativeAbout from '../../screens/About.vue';
import NativeRestricted from '../../screens/Restricted.vue';
import NativeTransfer from '../../screens/Transfer.vue';
import NativeSecurityDeposit from '../../screens/SecurityDeposit.vue';
import NativeSecurityDepositHistory from '../../screens/SecurityDepositHistory.vue';
import NativeSecurityDepositSuccess from '../../screens/SecurityDepositSuccess.vue';
import NativeAssetFlow from '../../screens/AssetFlow.vue';
import NativeAssetHistory from '../../screens/AssetHistory.vue';
import NativeTopup from '../../screens/Topup.vue';
import NativeTopupStatus from '../../screens/TopupStatus.vue';
import NativeKyc from '../../screens/Kyc.vue';
import NativeSecurity from '../../screens/Security.vue';
import NativeAcademy from '../../screens/Academy.vue';
import NativeWealth from '../../screens/Wealth.vue';
import NativeWealthOverview from '../../screens/WealthOverview.vue';
import NativeWealthOrder from '../../screens/WealthOrder.vue';
import NativeWithdraw from '../../screens/Withdraw.vue';
import NativeWithdrawalStatus from '../../screens/WithdrawalStatus.vue';
import NativeWithdrawalHistory from '../../screens/WithdrawalHistory.vue';
import NativePaidPromotion from '../../screens/PaidPromotion.vue';
import NativePromotionHub from '../../screens/PromotionHub.vue';
import NativePromotionReport from '../../screens/PromotionReport.vue';
import NativePromotion from '../../screens/Promotion.vue';
import NativePromotionRewardDetails from '../../screens/PromotionRewardDetails.vue';
import NativeWallet from '../../screens/Wallet.vue';
import NativePartnerChildren from '../../screens/PartnerChildren.vue';
import NativePartnerStock from '../../screens/PartnerStock.vue';
// #endif
let Landing: Component,
    Registration: Component,
    PasswordRecovery: Component,
    Settings: Component,
    About: Component,
    Restricted: Component,
    Transfer: Component,
    SecurityDeposit: Component,
    SecurityDepositHistory: Component,
    SecurityDepositSuccess: Component,
    AssetFlow: Component,
    AssetHistory: Component,
    Topup: Component,
    TopupStatus: Component,
    Kyc: Component,
    Security: Component,
    Academy: Component,
    Wealth: Component,
    WealthOverview: Component,
    WealthOrder: Component,
    Withdraw: Component,
    WithdrawalStatus: Component,
    WithdrawalHistory: Component,
    PaidPromotion: Component,
    PromotionHub: Component,
    PromotionReport: Component,
    Promotion: Component,
    PromotionRewardDetails: Component,
    Wallet: Component,
    PartnerChildren: Component,
    PartnerStock: Component;
// #ifdef H5
Landing = lazyScreen(() => import('../../screens/Landing.vue'));
Registration = lazyScreen(() => import('../../screens/Registration.vue'));
PasswordRecovery = lazyScreen(() => import('../../screens/PasswordRecovery.vue'));
Settings = lazyScreen(() => import('../../screens/Settings.vue'));
About = lazyScreen(() => import('../../screens/About.vue'));
Restricted = lazyScreen(() => import('../../screens/Restricted.vue'));
Transfer = lazyScreen(() => import('../../screens/Transfer.vue'));
SecurityDeposit = lazyScreen(() => import('../../screens/SecurityDeposit.vue'));
SecurityDepositHistory = lazyScreen(() => import('../../screens/SecurityDepositHistory.vue'));
SecurityDepositSuccess = lazyScreen(() => import('../../screens/SecurityDepositSuccess.vue'));
AssetFlow = lazyScreen(() => import('../../screens/AssetFlow.vue'));
AssetHistory = lazyScreen(() => import('../../screens/AssetHistory.vue'));
Topup = lazyScreen(() => import('../../screens/Topup.vue'));
TopupStatus = lazyScreen(() => import('../../screens/TopupStatus.vue'));
Kyc = lazyScreen(() => import('../../screens/Kyc.vue'));
Security = lazyScreen(() => import('../../screens/Security.vue'));
Academy = lazyScreen(() => import('../../screens/Academy.vue'));
Wealth = lazyScreen(() => import('../../screens/Wealth.vue'));
WealthOverview = lazyScreen(() => import('../../screens/WealthOverview.vue'));
WealthOrder = lazyScreen(() => import('../../screens/WealthOrder.vue'));
Withdraw = lazyScreen(() => import('../../screens/Withdraw.vue'));
WithdrawalStatus = lazyScreen(() => import('../../screens/WithdrawalStatus.vue'));
WithdrawalHistory = lazyScreen(() => import('../../screens/WithdrawalHistory.vue'));
PaidPromotion = lazyScreen(() => import('../../screens/PaidPromotion.vue'));
PromotionHub = lazyScreen(() => import('../../screens/PromotionHub.vue'));
PromotionReport = lazyScreen(() => import('../../screens/PromotionReport.vue'));
Promotion = lazyScreen(() => import('../../screens/Promotion.vue'));
PromotionRewardDetails = lazyScreen(() => import('../../screens/PromotionRewardDetails.vue'));
Wallet = lazyScreen(() => import('../../screens/Wallet.vue'));
PartnerChildren = lazyScreen(() => import('../../screens/PartnerChildren.vue'));
PartnerStock = lazyScreen(() => import('../../screens/PartnerStock.vue'));
// #endif
// #ifndef H5
Landing = NativeLanding;
Registration = NativeRegistration;
PasswordRecovery = NativePasswordRecovery;
Settings = NativeSettings;
About = NativeAbout;
Restricted = NativeRestricted;
Transfer = NativeTransfer;
SecurityDeposit = NativeSecurityDeposit;
SecurityDepositHistory = NativeSecurityDepositHistory;
SecurityDepositSuccess = NativeSecurityDepositSuccess;
AssetFlow = NativeAssetFlow;
AssetHistory = NativeAssetHistory;
Topup = NativeTopup;
TopupStatus = NativeTopupStatus;
Kyc = NativeKyc;
Security = NativeSecurity;
Academy = NativeAcademy;
Wealth = NativeWealth;
WealthOverview = NativeWealthOverview;
WealthOrder = NativeWealthOrder;
Withdraw = NativeWithdraw;
WithdrawalStatus = NativeWithdrawalStatus;
WithdrawalHistory = NativeWithdrawalHistory;
PaidPromotion = NativePaidPromotion;
PromotionHub = NativePromotionHub;
PromotionReport = NativePromotionReport;
Promotion = NativePromotion;
PromotionRewardDetails = NativePromotionRewardDetails;
Wallet = NativeWallet;
PartnerChildren = NativePartnerChildren;
PartnerStock = NativePartnerStock;
// #endif
import FormErrors from '../../components/FormErrors.vue';
import PageSkeleton from '../../components/PageSkeleton.vue';
const path = ref(''),
    data = ref<ClientPage<any> | null>(null),
    errors = ref<Record<string, string>>({}),
    loading = ref(true);
let renderFailed = false;
// Child setup/render errors must not leave an empty native WebView.
onErrorCaptured(() => {
    renderFailed = true;
    data.value = null;
    loading.value = false;
    errors.value = { form: t('Unable to load. Please try again.') };
    return false;
});
let generation = 0;
let returningToKycDraft = false;
const anchor = ref('');
let stockScroll = 0;
onPageScroll((event) => {
    stockScroll = event.scrollTop;
});
onLoad((options) => {
    // H5 may already decode the outer route parameter. Keep query values encoded.
    const routePath = options?.path || '/';
    const target = routePath.startsWith('/') ? routePath : decodeURIComponent(routePath);
    const parts = target.split('#');
    path.value = parts[0];
    anchor.value = parts[1] ?? '';
});
onShow(() => {
    if (returningToKycDraft) {
        returningToKycDraft = false;
        // Keep the mounted form and any in-flight submit when returning from the photo picker.
        if (data.value) return;
    }
    if (path.value) {
        const restore = stockScroll;
        void load().then(async () => {
            if (path.value.startsWith('/promotion/stock')) {
                await nextTick();
                uni.pageScrollTo({ scrollTop: restore, duration: 0 });
            }
        });
    }
});
onHide(() => {
    // #ifdef H5
    if (document.hidden && path.value.split('?')[0] === '/kyc') {
        returningToKycDraft = true;
        return;
    }
    // #endif
    returningToKycDraft = false;
    generation++;
});
function retryLoad() {
    // Browsers retain failed module imports for this document. An explicit retry
    // needs a fresh document after a chunk failure (including a release swap).
    // #ifdef H5
    if (renderFailed) {
        const recovery = new URL(window.location.href);
        recovery.searchParams.set('_screen_retry', String(Date.now()));
        window.location.replace(recovery.href);
        return;
    }
    // #endif
    renderFailed = false;
    void load();
}
async function load() {
    const run = ++generation;
    loading.value = true;
    if (path.value.startsWith('/promotion/stock')) data.value = null;
    errors.value = {};
    try {
        await ensureBootstrap();
        setCurrentPage(path.value);
        const response = await getPage<any>(path.value);
        if (run !== generation) return;
        if ('redirect' in response) {
            go(String(response.redirect), true);
            return;
        }
        data.value = response;
        await nextTick();
        if (/^[a-z][a-z0-9-]*$/i.test(anchor.value))
            uni.pageScrollTo({ selector: '#' + anchor.value, duration: 0 });
    } catch (e) {
        if (run === generation) {
            data.value = null;
            errors.value = explainError(e);
            if (e instanceof ApiError && e.status === 401) {
                clearSession();
                go('/login', true);
            }
        }
    } finally {
        if (run === generation) loading.value = false;
    }
}
</script>
<template>
    <view v-if="!data"
        ><PageSkeleton v-if="loading" full /><view v-else class="screen-loading"
            ><FormErrors :errors="errors" /><button @click="retryLoad">{{ t('Try again') }}</button
            ><button @click="go('/account', true)">{{ t('Back') }}</button></view></view
    ><Landing v-else-if="data.component === 'public/Landing'" /><Registration
        v-else-if="['user/Register', 'user/VerifyRegistration'].includes(data.component)"
        :key="data.component"
        :page="data.props"
        @reload="load"
    /><PasswordRecovery
        v-else-if="['user/ForgotPassword', 'user/ResetPassword'].includes(data.component)"
        :key="data.component"
        :page="data.props"
    /><Settings v-else-if="data.component === 'user/AccountSettings'" /><About
        v-else-if="['user/About', 'user/AboutArticle'].includes(data.component)"
        :page="data.props"
    /><Restricted v-else-if="data.component === 'user/Restricted'" :page="data.props" /><Academy
        v-else-if="data.component === 'user/PromotionHub' && data.props.section === 'rules'"
        kind="hub"
        :page="data.props"
    /><Academy
        v-else-if="
            ['user/AcademyRegistration', 'user/AcademyFeatures', 'user/AcademyRewards'].includes(
                data.component,
            )
        "
        :kind="
            data.component === 'user/AcademyRegistration'
                ? 'registration'
                : data.component === 'user/AcademyFeatures'
                  ? 'features'
                  : 'rewards'
        "
        :page="data.props"
    /><Kyc v-else-if="data.component === 'user/Kyc'" :page="data.props" @reload="load" /><Security
        v-else-if="data.component === 'user/Security'"
        :page="data.props"
        @reload="load"
    /><AssetFlow
        v-else-if="data.component === 'user/AssetFlow'"
        :page="data.props"
        @reload="load"
    /><AssetHistory v-else-if="data.component === 'user/AssetHistory'" :page="data.props" /><Topup
        v-else-if="data.component === 'user/Topup'"
        :page="data.props"
    /><TopupStatus
        v-else-if="data.component === 'user/TopupStatus'"
        :page="data.props"
        @reload="load"
    /><Transfer
        v-else-if="data.component === 'user/Transfer'"
        :page="data.props"
        @reload="load"
    /><SecurityDeposit
        v-else-if="data.component === 'user/SecurityDeposit'"
        :page="data.props"
        @reload="load"
    /><SecurityDepositHistory
        v-else-if="data.component === 'user/SecurityDepositHistory'"
        :page="data.props"
        @reload="load"
    /><SecurityDepositSuccess
        v-else-if="data.component === 'user/SecurityDepositSuccess'"
        :page="data.props"
        @reload="load"
    /><Wealth
        v-else-if="data.component === 'user/Wealth'"
        :page="data.props"
        @reload="load"
    /><WealthOverview
        v-else-if="data.component === 'user/WealthOverview'"
        :page="data.props"
        @reload="load"
    /><WealthOrder
        v-else-if="data.component === 'user/WealthOrder'"
        :page="data.props"
        @reload="load"
    /><Withdraw
        v-else-if="data.component === 'user/Withdraw'"
        :page="data.props"
        @reload="load"
    /><WithdrawalStatus
        v-else-if="data.component === 'user/WithdrawalStatus'"
        :page="data.props"
        @reload="load"
    /><WithdrawalHistory
        v-else-if="data.component === 'user/WithdrawalHistory'"
        :page="data.props"
        @reload="load"
    /><PaidPromotion
        v-else-if="data.component === 'user/PaidPromotion'"
        :page="data.props"
        @reload="load"
    /><PromotionHub
        v-else-if="data.component === 'user/PromotionHub'"
        :page="data.props"
    /><PromotionReport
        v-else-if="['user/PromotionReport', 'user/PromotionCommissions'].includes(data.component)"
        :key="data.component"
        :page="data.props"
    /><Promotion
        v-else-if="data.component === 'user/Promotion'"
        :page="data.props"
        @reload="load"
    /><PromotionRewardDetails
        v-else-if="data.component === 'user/PromotionRewardDetails'"
        :page="data.props"
        @reload="load"
    /><Wallet
        v-else-if="data.component === 'user/Wallet'"
        :page="data.props"
        @reload="load"
    /><PartnerChildren
        v-else-if="data.component === 'user/PartnerChildren'"
        :page="data.props"
    /><PartnerStock
        v-else-if="data.component === 'user/PartnerStock'"
        :page="data.props"
        @reload="load"
    /><view v-else class="screen-loading"
        ><text>{{ t('Unable to load. Please try again.') }}</text
        ><button @click="go('/account', true)">{{ t('Back') }}</button></view
    >
</template>
<style scoped>
.screen-loading {
    padding: 48px 24px;
    max-width: 750px;
    margin: auto;
    text-align: center;
}
</style>
