<script setup lang="ts">
import { beginTiming } from '../../lib/startup-timing';
import { usePagePosition } from '../../lib/page-position';
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { onShow, onHide } from '@dcloudio/uni-app';
import { requireUser, session, consumerSessionScope } from '../../lib/session';
import { explainError } from '../../lib/client';
import { request, setCurrentPage, ApiError } from '../../lib/api';
import { go } from '../../lib/navigation';
import { t } from '../../lib/i18n';
import type { AssetOverview, FundsMovement } from '../../lib/assets';
import AssetSkeleton from '../../components/AssetSkeleton.vue';
import PageShell from '../../components/PageShell.vue';
import AssetCenter from '../../components/AssetCenter.vue';
import RefreshStatus from '../../components/RefreshStatus.vue';
import FormErrors from '../../components/FormErrors.vue';
import StatusBanner from '../../components/StatusBanner.vue';
type Summary = { assetOverview: AssetOverview; kycStatus: string; wallet: { status: string } | null };
type Activity = FundsMovement & { asset: string };
const data = ref<Summary | null>(null), errors = ref<Record<string, string>>({});
const activity = ref<Activity[] | null>(null), activityFailed = ref(false);
const updating = ref(false), activityLoading = ref(false);
let generation = 0, visible = true, lastSummary = 0, lastActivity = 0;
let summaryPending: Promise<void> | null = null, activityPending: Promise<void> | null = null, authorizing: Promise<void> | null = null;
watch(consumerSessionScope, () => {
    generation++; data.value = null; activity.value = null; errors.value = {};
    activityFailed.value = false; updating.value = false; activityLoading.value = false;
    summaryPending = activityPending = authorizing = null; lastSummary = lastActivity = 0;
}, { flush: 'sync' });
onHide(() => { visible = false; });
onBeforeUnmount(() => { visible = false; generation++; });
function current(run: number, scope: string | null) { return run === generation && scope === consumerSessionScope(); }
function revokeView(error: unknown) {
    generation++; data.value = null; activity.value = null;
    summaryPending = activityPending = null; updating.value = activityLoading.value = false;
    activityFailed.value = false; errors.value = explainError(error);
}
function loadSummary() {
    if (summaryPending) return summaryPending;
    const run = generation, scope = consumerSessionScope(), finishTiming = beginTiming('homeSummary');
    let outcome: 'ok' | 'error' = 'ok';
    updating.value = true; errors.value = {};
    const flight = request<Summary>('/home/summary').then(value => {
        if (!current(run, scope)) return;
        data.value = value; lastSummary = Date.now();
    }).catch(error => {
        outcome = 'error';
        if (!current(run, scope)) return;
        if (error instanceof ApiError && [401, 403, 419].includes(error.status)) { revokeView(error); return; }
        errors.value = explainError(error);
    }).finally(() => {
        finishTiming(outcome);
        if (summaryPending === flight) { summaryPending = null; updating.value = false; }
    });
    summaryPending = flight;
    return flight;
}
function loadActivity() {
    if (activityPending) return activityPending;
    const run = generation, scope = consumerSessionScope(), finishTiming = beginTiming('homeActivity');
    let outcome: 'ok' | 'error' = 'ok';
    activityLoading.value = true; activityFailed.value = false;
    const flight = request<{ activity: Activity[] }>('/home/activity').then(value => {
        if (!current(run, scope)) return;
        activity.value = value.activity; lastActivity = Date.now();
    }).catch(error => {
        outcome = 'error';
        if (!current(run, scope)) return;
        activityFailed.value = true;
        if (error instanceof ApiError && [401, 403, 419].includes(error.status)) revokeView(error);
    }).finally(() => {
        finishTiming(outcome);
        if (activityPending === flight) { activityPending = null; activityLoading.value = false; }
    });
    activityPending = flight;
    return flight;
}
function load(force = true) {
    if (authorizing) return authorizing;
    setCurrentPage('/dashboard');
    const flight = (async () => {
        try {
            if (!(await requireUser({ ensureWallet: false })) || !visible) return;
            if (session.value?.restricted) { go('/account/restricted', true); return; }
            // Each section publishes independently; a slow history request cannot hold the balance UI.
            if (force || !data.value || Date.now() - lastSummary >= 15000) void loadSummary();
            if (force || activity.value === null || Date.now() - lastActivity >= 15000) void loadActivity();
        } catch (error) { errors.value = explainError(error); }
    })().finally(() => { if (authorizing === flight) authorizing = null; });
    authorizing = flight;
    return flight;
}
onShow(() => { visible = true; void load(false); });
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
        <RefreshStatus v-if="data" :loading="updating" :failed="!!Object.keys(errors).length" @retry="load(true)" />
        <!-- #endif -->
        <FormErrors :errors="data ? {} : errors" /><button
            v-if="!data && Object.keys(errors).length"
            class="secondary"
            @click="load(true)"
        >
            {{ t('Try again') }}</button
        ><AssetCenter
            v-if="data"
            :overview="data.assetOverview"
            :activity="activity" :activity-loading="activityLoading" :activity-failed="activityFailed" @retry-activity="loadActivity"
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
