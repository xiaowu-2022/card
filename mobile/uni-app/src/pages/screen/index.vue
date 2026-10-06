<script setup lang="ts">
import { ref, nextTick } from 'vue';
import { onLoad, onShow, onHide, onPageScroll } from '@dcloudio/uni-app';
import { getPage, ensureBootstrap, explainError, type ClientPage } from '../../lib/client';
import { clearSession } from '../../lib/session';
import { ApiError, setCurrentPage } from '../../lib/api';
import { t } from '../../lib/i18n';
import { go } from '../../lib/navigation';
import Landing from '../../screens/Landing.vue';
import Registration from '../../screens/Registration.vue';
import PasswordRecovery from '../../screens/PasswordRecovery.vue';
import Settings from '../../screens/Settings.vue';
import About from '../../screens/About.vue';
import Restricted from '../../screens/Restricted.vue';
import Transfer from '../../screens/Transfer.vue';
import SecurityDeposit from '../../screens/SecurityDeposit.vue';
import SecurityDepositHistory from '../../screens/SecurityDepositHistory.vue';
import SecurityDepositSuccess from '../../screens/SecurityDepositSuccess.vue';
import AssetFlow from '../../screens/AssetFlow.vue';
import AssetHistory from '../../screens/AssetHistory.vue';
import Topup from '../../screens/Topup.vue';
import TopupStatus from '../../screens/TopupStatus.vue';
import Kyc from '../../screens/Kyc.vue';
import Security from '../../screens/Security.vue';
import Academy from '../../screens/Academy.vue';
import Wealth from '../../screens/Wealth.vue';
import WealthOverview from '../../screens/WealthOverview.vue';
import WealthOrder from '../../screens/WealthOrder.vue';
import Withdraw from '../../screens/Withdraw.vue';
import WithdrawalStatus from '../../screens/WithdrawalStatus.vue';
import WithdrawalHistory from '../../screens/WithdrawalHistory.vue';
import PaidPromotion from '../../screens/PaidPromotion.vue';
import PromotionHub from '../../screens/PromotionHub.vue';
import PromotionReport from '../../screens/PromotionReport.vue';
import Promotion from '../../screens/Promotion.vue';
import PromotionRewardDetails from '../../screens/PromotionRewardDetails.vue';
import Wallet from '../../screens/Wallet.vue';
import PartnerChildren from '../../screens/PartnerChildren.vue';
import PartnerStock from '../../screens/PartnerStock.vue';
import FormErrors from '../../components/FormErrors.vue';
const path = ref(''),
    data = ref<ClientPage<any> | null>(null),
    errors = ref<Record<string, string>>({}),
    loading = ref(false);
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
async function load() {
    const run = ++generation;
    loading.value = true;
    if (path.value.startsWith('/promotion/stock/partners')) data.value = null;
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
    <view v-if="!data" class="screen-loading"
        ><FormErrors :errors="errors" /><text v-if="loading">{{ t('Loading…') }}</text
        ><button v-else @click="load">{{ t('Try again') }}</button></view
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
