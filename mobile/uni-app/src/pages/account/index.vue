<script setup lang="ts">
import { computed, ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { session, unread, requireUser, refreshUnread } from '../../lib/session';
import { request, setCurrentPage } from '../../lib/api';
import { t } from '../../lib/i18n';
import { go } from '../../lib/navigation';
import { explainError } from '../../lib/client';
import PageShell from '../../components/PageShell.vue';
import UiIcon from '../../components/UiIcon.vue';
import FormErrors from '../../components/FormErrors.vue';
const account = ref<{
        supportAgent?: boolean;
        kycStatus: string;
        promotionRank: number;
        accountQualified: boolean;
    } | null>(null),
    errors = ref<Record<string, string>>({});
onShow(async () => {
    setCurrentPage('/account');
    try {
        if (!(await requireUser())) return;
        if (session.value?.restricted) {
            go('/account/restricted', true);
            return;
        }
        account.value = await request('/account');
        await refreshUnread();
    } catch (e) {
        errors.value = explainError(e);
    }
});
const contact = computed(() => {
    const email = session.value?.user?.email;
    if (!email) return '';
    const [local, domain] = email.split('@');
    return `${local.slice(0, 1)}***@${domain}`;
});
const level = computed(() =>
    !account.value?.accountQualified
        ? t('Account inactive')
        : account.value.promotionRank
          ? t('Mastercard level {{rank}}', { rank: account.value.promotionRank })
          : t('Ordinary member'),
);
const status = computed(() =>
    t(
        (
            {
                APPROVED: 'Verified',
                PENDING: 'Under review',
                RESUBMISSION_REQUIRED: 'Action required',
                REJECTED: 'Verification could not be approved',
            } as Record<string, string>
        )[account.value?.kycStatus ?? ''] ?? 'Not verified',
    ),
);
const items = computed(() => [
    ...(account.value?.supportAgent
        ? [{ title: 'Support workspace', icon: 'support', path: '/support-workspace' }]
        : []),
    { title: 'Account and security', icon: 'shield-check', path: '/account/security' },
    { title: 'Promotion center', icon: 'users', path: '/promotion' },
    { title: 'Invitation data', icon: 'chart-no-axes-combined', path: '/promotion/invitations' },
    { title: 'U Card Academy', icon: 'book-open', path: '/promotion/rules' },
    { title: 'Customer support', icon: 'support', path: '/support' },
    { title: 'Messages', icon: 'bell', path: '/messages' },
]);
function count(path: string) {
    return path === '/messages'
        ? unread.messages
        : path === '/support'
          ? unread.support
          : path === '/support-workspace' && account.value?.supportAgent
            ? unread.agentSupport
            : 0;
}
function copy() {
    if (session.value?.user)
        uni.setClipboardData({
            data: session.value.user.accountId,
            showToast: false,
            success: () => uni.showToast({ title: t('Account ID copied.'), icon: 'none' }),
            fail: () =>
                uni.showToast({
                    title: t('Could not copy. Select the account ID and copy it manually.'),
                    icon: 'none',
                }),
        });
}
</script>
<template>
    <PageShell active="account"
        ><FormErrors :errors="errors" /><view v-if="session?.user" class="account-page"
            ><view class="identity"
                ><view class="identity-main"
                    ><text class="profile-name">{{
                        session.user.displayName?.trim() || t('Account user')
                    }}</text
                    ><text class="profile-contact">{{ contact }}</text></view
                ><view class="level"
                    ><text>{{ level }}</text
                    ><button class="upgrade" @click="go('/promotion/membership')">
                        <UiIcon
                            name="arrow-up-right"
                            :size="16"
                            :color="session?.tenant.primaryColor"
                        />{{ t('Upgrade') }}
                    </button></view
                ></view
            ><view class="account-id"
                ><text class="muted">{{ t('Account ID') }}</text
                ><text class="mono" selectable>{{ session.user.accountId }}</text
                ><button class="copy-button" :aria-label="t('Copy account ID')" @click="copy">
                    <UiIcon name="copy" :size="16" /></button></view
            ><view class="verification" @click="go('/kyc')"
                ><UiIcon name="scan-face" :size="20" :color="session?.tenant.primaryColor" /><view
                    class="verification-text"
                    ><text>{{ t('Identity verification') }}</text
                    ><text class="verification-status">{{ status }}</text></view
                ><text class="verification-link">{{
                    t(
                        ['NOT_SUBMITTED', 'RESUBMISSION_REQUIRED'].includes(
                            account?.kycStatus ?? '',
                        )
                            ? 'Go to verification'
                            : 'View details',
                    )
                }}</text
                ><UiIcon name="chevron-right" :size="16" /></view
            ><view class="menu-grid"
                ><button
                    v-for="item in items"
                    :key="item.path"
                    class="menu-item"
                    @click="go(item.path)"
                >
                    <view class="menu-icon"
                        ><UiIcon :name="item.icon" :size="24" /><text
                            v-if="count(item.path)"
                            class="badge"
                            >{{ count(item.path) > 99 ? '99+' : count(item.path) }}</text
                        ></view
                    ><text>{{ t(item.title) }}</text>
                </button></view
            ><view class="settings-row" @click="go('/account/settings')"
                ><UiIcon name="settings" :size="20" :color="session?.tenant.primaryColor" /><text>{{
                    t('Settings')
                }}</text
                ><UiIcon name="chevron-right" :size="16" /></view></view
    ></PageShell>
</template>
<style scoped>
.account-page {
    padding: 8px 8px 0;
}
.identity {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 8px 0 16px;
}
.identity-main {
    min-width: 0;
    flex: 1;
}
.profile-name {
    display: block;
    font-size: clamp(20px, 3.2cqw, 24px);
    font-weight: 600;
    line-height: 1.3;
}
.profile-contact {
    display: block;
    margin-top: 4px;
    font-size: 13px;
    line-height: 20px;
    color: #68736e;
}
.level {
    max-width: 55%;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 14px;
    color: var(--user-primary);
}
.upgrade {
    display: flex;
    align-items: center;
    gap: 4px;
    min-height: 44px;
    background: none;
    font-size: 14px;
    color: var(--user-primary);
    padding: 0 8px;
    white-space: nowrap;
}
.account-id {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 44px;
}
.mono {
    font-family: monospace;
    font-size: 13px;
    flex: 1;
    overflow-wrap: anywhere;
}
.copy-button {
    background: none;
    width: 44px;
    height: 44px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.verification {
    border-top: 1px solid #e2e7e4;
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 8px;
    min-height: 68px;
    padding: 12px 0;
    font-size: 14px;
}
.verification-text {
    flex: 1;
    min-width: 0;
}
.verification-text > text {
    display: block;
}
.verification-status {
    font-size: 12px;
    color: #18724f;
    margin-top: 2px;
}
.verification-link {
    max-width: 40%;
    font-size: 12px;
    color: var(--user-primary);
    text-align: right;
}
.menu-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    padding: 20px 0;
    gap: 8px;
    border-top: 1px solid #e2e7e4;
}
.menu-item {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
    min-height: 88px;
    padding: 8px 4px;
    background: transparent;
    font-size: clamp(13px, 2.133cqw, 16px);
    line-height: 1.4;
    color: #171c19;
}
.menu-icon {
    height: 36px;
    width: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}
.settings-row {
    display: flex;
    align-items: center;
    gap: 12px;
    border-top: 1px solid #e2e7e4;
    min-height: 64px;
    padding: 12px 0;
    font-size: 14px;
}
.settings-row > text {
    flex: 1;
}
.account-id > .muted {
    font-size: 12px;
}
.verification-status {
    color: #68736e;
}
</style>
