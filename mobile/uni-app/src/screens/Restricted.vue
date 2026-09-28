<script setup lang="ts">
import PageShell from '../components/PageShell.vue';
import { go } from '../lib/navigation';
import { t } from '../lib/i18n';
defineProps<{ page: { tenantRestricted: boolean } }>();
</script>
<template>
    <PageShell :title="t('Account')" back="/account/security"
        ><view class="restricted"
            ><text class="restricted-title">{{ t('Your account is currently restricted') }}</text
            ><text>{{
                t(
                    page.tenantRestricted
                        ? 'This service is temporarily restricted for all users of your organization.'
                        : 'You can still review account security and change your password.',
                )
            }}</text
            ><button class="secondary" @click="go('/account/security')">
                {{ t('Review security') }}
            </button></view
        ><text class="muted">{{
            t(
                'Financial and card actions are unavailable while access is restricted. Contact support if you need help.',
            )
        }}</text></PageShell
    >
</template>
<style scoped>
.restricted {
    padding: 24px;
    border: 1px solid #e2ded2;
    border-radius: 24px;
    background: #fffaf0;
    display: flex;
    flex-direction: column;
    gap: 16px;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 24px;
}
.restricted-title {
    font-size: 18px;
    font-weight: 600;
}
</style>
