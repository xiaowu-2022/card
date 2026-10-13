<script setup lang="ts">
import { usePagePosition } from '../../lib/page-position';
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { onShow, onHide } from '@dcloudio/uni-app';
import { requireUser, session, consumerSessionScope } from '../../lib/session';
import { getPage, explainError } from '../../lib/client';
import { setCurrentPage, ApiError } from '../../lib/api';
import { go } from '../../lib/navigation';
import { t } from '../../lib/i18n';
import type { AssetOverview } from '../../lib/assets';
import AssetSkeleton from '../../components/AssetSkeleton.vue';
import PageShell from '../../components/PageShell.vue';
import AssetCenter from '../../components/AssetCenter.vue';
import RefreshStatus from '../../components/RefreshStatus.vue';
import FormErrors from '../../components/FormErrors.vue';
import StatusBanner from '../../components/StatusBanner.vue';
const data = ref<{
        assetOverview: AssetOverview;
        kycStatus: string;
        wallet: { status: string } | null;
    } | null>(null),
    errors = ref<Record<string, string>>({});
const updating = ref(false);
let generation = 0,
    visible = true;
// #ifdef H5
watch(
    consumerSessionScope,
    () => {
        generation++;
        data.value = null;
        errors.value = {};
    },
    { flush: 'sync' },
);
onHide(() => {
    visible = false;
    generation++;
});
onBeforeUnmount(() => {
    generation++;
});
// #endif
async function load() {
    let run: number | undefined;
    updating.value = true;
    errors.value = {};
    setCurrentPage('/dashboard');
    try {
        if (!(await requireUser())) return;
        // #ifdef H5
        if (!visible) return;
        // #endif
        if (session.value?.restricted) {
            go('/account/restricted', true);
            return;
        }
        run = ++generation;
        const scope = consumerSessionScope();
        const response = await getPage<typeof data.value>('/dashboard');
        // #ifdef H5
        if (run !== generation || scope !== consumerSessionScope() || !visible) return;
        // #endif
        data.value = response.props;
        errors.value = {};
    } catch (e) {
        // #ifdef H5
        if (run !== undefined && run !== generation) return;
        if (e instanceof ApiError && [401, 403, 419].includes(e.status)) data.value = null;
        // #endif
        errors.value = explainError(e);
    } finally {
        if (run === undefined || run === generation) updating.value = false;
    }
}
// #ifdef H5
onShow(() => {
    visible = true;
    void load();
});
// #endif
// #ifndef H5
onShow(load);
// #endif
const next = computed(
    () =>
        ({
            NOT_SUBMITTED: {
                title: 'Complete identity verification',
                description: 'Verify your identity before using financial services.',
                action: { label: t('Verify now'), href: '/kyc' },
                tone: 'neutral',
            },
            PENDING: {
                title: 'Verification under review',
                description:
                    'Your information has been submitted. We will let you know when review is complete.',
                tone: 'pending',
            },
            RESUBMISSION_REQUIRED: {
                title: 'Action required',
                description: 'We need updated identity documents before you can continue.',
                action: { label: t('Review request'), href: '/kyc' },
                tone: 'warning',
            },
            REJECTED: {
                title: 'Verification unavailable',
                description: 'We could not verify your identity. Contact support for assistance.',
                action: { label: t('Customer support'), href: '/support' },
                tone: 'warning',
            },
        })[data.value?.kycStatus ?? ''],
);
usePagePosition(() => '/dashboard', () => !!data.value);
</script>
<template>
    <PageShell active="assets" overview
        ><!-- #ifdef H5 -->
        <RefreshStatus v-if="data" :loading="updating" :failed="!!Object.keys(errors).length" @retry="load" />
        <!-- #endif -->
        <FormErrors :errors="data ? {} : errors" /><button
            v-if="!data && Object.keys(errors).length"
            class="secondary"
            @click="load"
        >
            {{ t('Try again') }}</button
        ><AssetCenter
            v-if="data"
            :overview="data.assetOverview"
            :prerequisite="
                data.kycStatus !== 'APPROVED'
                    ? '/kyc'
                    : !data.wallet
                      ? '/promotion/membership'
                      : data.wallet.status !== 'ACTIVE'
                        ? '/wallet'
                        : undefined
            " /><view v-if="next" class="next-step"
            ><StatusBanner
                :title="t(next.title)"
                :description="t(next.description)"
                :tone="next.tone"
                :action="next.action" /></view
        ><AssetSkeleton v-if="!data && !Object.keys(errors).length"
    /></PageShell>
</template>
<style scoped>
.next-step {
    margin-top: 24px;
}
</style>
