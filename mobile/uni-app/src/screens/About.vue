<script setup lang="ts">
import { computed } from 'vue';
import PageShell from '../components/PageShell.vue';
import UiIcon from '../components/UiIcon.vue';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
const props = defineProps<{
    page: { article?: { key: string; locale: string; body: string | null } };
}>();
const titles: Record<string, string> = {
    terms: 'Terms of service',
    privacy: 'Privacy policy',
    'account-closure': 'Account closure',
};
const title = computed(() =>
    t(props.page.article ? (titles[props.page.article.key] ?? 'About us') : 'About us'),
);
</script>
<template>
    <PageShell :title="title" :back="page.article ? '/about' : '/account/settings'" article
        ><template v-if="page.article"
            ><text v-if="page.article.body" :lang="page.article.locale" class="article-body">{{
                page.article.body
            }}</text
            ><text v-else class="empty">{{
                t('This article is not available in the selected language yet.')
            }}</text></template
        ><view v-else
            ><view
                v-for="key in ['terms', 'privacy']"
                :key="key"
                class="about-link"
                @click="go('/about/' + key)"
                ><text>{{ t(titles[key]) }}</text
                ><UiIcon name="chevron-right" :size="24" /></view></view
    ></PageShell>
</template>
<style scoped>
.article-body {
    display: block;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-size: clamp(16px, 2.4cqw, 18px);
    line-height: 1.9;
    margin-top: clamp(20px, 5.867cqw, 44px);
}
.about-link {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    min-height: clamp(64px, 14.133cqw, 106px);
    border-bottom: 1px solid #e2e7e4;
    padding: 16px 6px;
    color: #68736e;
    font-size: clamp(19px, 4.267cqw, 32px);
}
</style>
