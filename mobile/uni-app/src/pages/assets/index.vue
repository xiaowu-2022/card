<script setup lang="ts">
import { computed, ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { requireUser, session } from '../../lib/session';
import { getPage, explainError } from '../../lib/client';
import { setCurrentPage } from '../../lib/api';
import { go } from '../../lib/navigation';
import { t } from '../../lib/i18n';
import type { AssetOverview } from '../../lib/assets';
import AssetSkeleton from '../../components/AssetSkeleton.vue';
import PageShell from '../../components/PageShell.vue';
import AssetCenter from '../../components/AssetCenter.vue';
import FormErrors from '../../components/FormErrors.vue';
import StatusBanner from '../../components/StatusBanner.vue';
const data = ref<{
        assetOverview: AssetOverview;
        kycStatus: string;
        wallet: { status: string } | null;
    } | null>(null),
    errors = ref<Record<string, string>>({});
async function load() {
    setCurrentPage('/dashboard');
    try {
        if (!(await requireUser())) return;
        if (session.value?.restricted) {
            go('/account/restricted', true);
            return;
        }
        data.value = (await getPage<typeof data.value>('/dashboard')).props;
        errors.value = {};
    } catch (e) {
        errors.value = explainError(e);
    }
}
onShow(load);
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
</script>
<template>
    <PageShell active="assets" overview
        ><FormErrors :errors="errors" /><button
            v-if="Object.keys(errors).length"
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
            "
        /><view v-if="next" class="next-step"
            ><StatusBanner
                :title="t(next.title)"
                :description="t(next.description)"
                :tone="next.tone"
                :action="next.action" /></view
        ><AssetSkeleton v-if="!data && !Object.keys(errors).length" /></PageShell
    >
</template>
<style scoped>
.next-step {
    margin-top: 24px;
}
</style>
