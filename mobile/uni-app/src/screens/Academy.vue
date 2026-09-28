<script setup lang="ts">
import { computed } from 'vue';
import PageShell from '../components/PageShell.vue';
import UiIcon from '../components/UiIcon.vue';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
import { staticAsset } from '../lib/origin';
import { academyRegistration } from '../generated/academy-registration';
import { academyGuide } from '../generated/academy-guide';
import { academyRewards } from '../generated/academy-rewards';
import { exactAmount } from '../generated/exact-amount';
const props = defineProps<{
    kind: 'hub' | 'registration' | 'features' | 'rewards';
    page: {
        levels?: {
            id: string;
            enabled: boolean;
            rank: number;
            fee: string;
            reward: string;
            percent: number;
            target: number | null;
        }[];
    };
}>();
const topics = [
    {
        kind: 'registration',
        title: 'New user registration guide',
        image: 'invitations.svg',
        path: '/promotion/registration',
    },
    {
        kind: 'features',
        title: 'Buttons and features guide',
        image: 'features.svg',
        path: '/promotion/features',
    },
    {
        kind: 'rewards',
        title: 'Reward rules guide',
        image: 'rewards.svg',
        path: '/promotion/reward-guide',
    },
];
const info = computed(
    () =>
        ({
            registration: {
                sections: academyRegistration,
                number: '01',
                title: 'New user registration guide',
                heading: 'From your first invitation to an active account',
                intro: 'Follow the steps to register with email, verify your identity and choose how to activate your account.',
            },
            features: {
                sections: academyGuide,
                number: '02',
                title: 'Buttons and features guide',
                heading: 'Your guide to Assets, Cards and Me',
                intro: 'Explore the three main areas and learn how to manage funds, use your card and find your invitation information.',
            },
            rewards: {
                sections: academyRewards,
                number: '03',
                title: 'Reward rules guide',
                heading: 'Understand your rewards, one rule at a time',
                intro: 'A guide to membership benefits, activation commissions, annual-fee rewards and return progress.',
            },
        })[props.kind === 'hub' ? 'registration' : props.kind],
);
const levels = computed(() => [
    { id: 'ordinary', rank: 0, fee: '0', reward: '20', percent: 0, target: null },
    ...(props.page.levels ?? []).filter((l) => l.enabled).sort((a, b) => a.rank - b.rank),
]);
function rankLabel(rank: number) {
    return rank ? t('Mastercard level {{rank}}', { rank }) : t('Ordinary member');
}
const poster = staticAsset('images/promotion/academy-level-benefits.png');
function preview() {
    uni.previewImage({ urls: [poster] });
}
function jump(id: string) {
    const query = uni.createSelectorQuery();
    query.select('#' + id).boundingClientRect();
    query.select('.sticky-header').boundingClientRect();
    query.selectViewport().scrollOffset(() => {});
    query.exec((results) => {
        const [section, header, viewport] = results;
        if (!section || !viewport) return;
        uni.pageScrollTo({
            scrollTop: Math.max(0, viewport.scrollTop + section.top - (header?.bottom ?? 0) - 12),
            duration: 250,
        });
    });
}
</script>
<template>
    <PageShell
        white
        sticky-header
        :title="t(kind === 'hub' ? 'U Card Academy' : info.title)"
        :back="kind === 'hub' ? '/account' : '/promotion/rules'"
        ><view v-if="kind === 'hub'" class="academy-posters"
            ><view
                v-for="(topic, index) in topics"
                :key="topic.kind"
                class="academy-poster"
                :class="'poster-' + topic.kind"
                @click="go(topic.path)"
                ><view class="poster-copy"
                    ><text class="poster-number">0{{ index + 1 }}</text
                    ><text class="poster-title">{{ t(topic.title) }}</text
                    ><view class="poster-link"
                        ><text>{{ t('Read the guide') }}</text
                        ><UiIcon name="arrow-up-right" :size="14" /></view></view
                ><image
                    :src="staticAsset('images/academy/' + topic.image)"
                    mode="aspectFit"
                    class="poster-art" /></view></view
        ><view v-else class="academy-reader"
            ><image
                v-if="kind !== 'features'"
                :src="poster"
                mode="widthFix"
                class="benefits-poster"
                :aria-label="
                    t('Promotion levels and membership benefits. Open the full-size image.')
                "
                @click="preview()" /><view class="reader-hero"
                ><text class="eyebrow">{{ t('U Card Academy') }} / {{ info.number }}</text
                ><text class="hero-heading">{{ t(info.heading) }}</text
                ><text class="intro">{{ t(info.intro) }}</text></view
            ><view class="reader-toc"
                ><text class="toc-title">{{ t('In this guide') }}</text
                ><view
                    v-for="(section, index) in info.sections"
                    :key="section.id"
                    class="toc-link"
                    @click="jump(section.id)"
                    ><text class="number">0{{ index + 1 }}</text
                    ><text class="grow">{{ t(section.title) }}</text
                    ><text>↓</text></view
                ></view
            ><view
                v-for="(section, index) in info.sections"
                :id="section.id"
                :key="section.id"
                class="reader-section"
                ><view class="section-heading"
                    ><text class="section-number">0{{ index + 1 }}</text
                    ><view
                        ><text class="section-title">{{ t(section.title) }}</text
                        ><text class="intro">{{ t(section.intro) }}</text></view
                    ></view
                ><view
                    v-for="(item, itemIndex) in section.items"
                    :key="item.title"
                    class="reader-topic"
                    :class="{
                        'reward-example':
                            kind === 'rewards' &&
                            (item.title.startsWith('Example:') ||
                                item.title === 'Direct examples at a 60% rate'),
                    }"
                    ><view class="topic-heading"
                        ><text class="number">{{ index + 1 }}.{{ itemIndex + 1 }}</text
                        ><text>{{ t(item.title) }}</text></view
                    ><text class="topic-body">{{ t(item.body) }}</text></view
                ><view v-if="kind === 'rewards' && section.id === 'levels'" class="benefits"
                    ><text class="benefits-title">{{ t('Current level benefits') }}</text
                    ><view v-for="level in levels" :key="level.id" class="benefit-row"
                        ><text class="benefit-heading">{{ rankLabel(level.rank) }}</text
                        ><view class="benefit-grid"
                            ><view
                                v-for="item in [
                                    { label: 'Annual fee · USDT', value: exactAmount(level.fee) },
                                    {
                                        label: 'Direct activation · USDT',
                                        value: exactAmount(level.reward),
                                    },
                                    { label: 'Direct annual-fee rate', value: level.percent + '%' },
                                    {
                                        label: 'Weighted return target',
                                        value: level.target ?? t('No annual-fee return target'),
                                    },
                                ]"
                                :key="item.label"
                                ><text class="benefit-label">{{ t(item.label) }}</text
                                ><text class="benefit-value">{{ item.value }}</text></view
                            ></view
                        ></view
                    ></view
                ></view
            ><view
                v-if="kind !== 'features'"
                class="progress-link"
                @click="go(kind === 'rewards' ? '/promotion' : '/promotion/reward-guide')"
                ><text>{{
                    t(
                        kind === 'rewards'
                            ? 'View my promotion progress'
                            : 'View membership fees and benefits',
                    )
                }}</text
                ><UiIcon name="arrow-up-right" :size="16" /></view></view
    ></PageShell>
</template>
<style scoped>
.academy-reader {
    max-width: 720px;
    margin: auto;
    padding-bottom: 32px;
    color: #253b32;
    overflow-wrap: anywhere;
}
.benefits-poster {
    display: block;
    width: 100%;
    border-radius: 8px;
    margin-top: 16px;
}
.reader-hero {
    padding: 28px 0 24px;
}
.eyebrow {
    color: var(--user-primary);
    font-size: 12px;
    letter-spacing: 0.12em;
}
.hero-heading {
    display: block;
    margin: 14px 0;
    font-size: clamp(26px, 5vw, 36px);
    font-weight: 600;
    line-height: 1.45;
}
.intro {
    display: block;
    color: #718078;
    font-size: 14px;
    line-height: 1.85;
}
.reader-toc {
    padding: 20px;
    border-radius: 16px;
    background: #f3f7f3;
}
.toc-title {
    display: block;
    margin-bottom: 10px;
    font-size: 12px;
    color: #718078;
}
.toc-link {
    display: flex;
    align-items: center;
    gap: 14px;
    min-height: 44px;
    font-size: 15px;
}
.number {
    color: var(--user-primary);
    font-size: 12px;
    font-variant-numeric: tabular-nums;
    flex-shrink: 0;
    font-weight: 500;
}
.grow {
    flex: 1;
}
.reader-section {
    padding-top: 36px;
    scroll-margin-top: 24px;
}
.reader-section + .reader-section {
    margin-top: 32px;
    border-top: 1px solid #e4ebe5;
}
.section-heading {
    display: flex;
    gap: 16px;
    align-items: flex-start;
    margin-bottom: 28px;
}
.section-number {
    font-size: 30px;
    font-weight: 300;
    line-height: 1.3;
    color: var(--user-primary);
    flex-shrink: 0;
}
.section-title {
    display: block;
    margin-bottom: 6px;
    font-size: 23px;
    font-weight: 600;
}
.reader-topic + .reader-topic {
    margin-top: 26px;
}
.topic-heading {
    display: flex;
    gap: 12px;
    align-items: baseline;
    margin-bottom: 10px;
    font-size: 17px;
    font-weight: 600;
    line-height: 1.6;
}
.topic-body {
    display: block;
    color: #526159;
    font-size: 15px;
    line-height: 2;
}
.reward-example {
    padding: 18px;
    border-left: 2px solid #c9dace;
    background: #f7f9f5;
    border-radius: 0 12px 12px 0;
}
.progress-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 32px;
    padding: 18px 0;
    border-top: 1px solid #e4ebe5;
    color: var(--user-primary);
    font-size: 15px;
}
.benefits {
    margin-top: 28px;
    border: 1px solid #e4ebe5;
    border-radius: 16px;
    overflow: hidden;
}
.benefits-title {
    display: block;
    padding: 16px 20px;
    background: #f3f7f3;
    font-size: 15px;
    font-weight: 600;
}
.benefit-row {
    padding: 20px;
}
.benefit-row + .benefit-row {
    border-top: 1px solid #e4ebe5;
}
.benefit-heading {
    display: block;
    margin-bottom: 14px;
    font-size: 16px;
    font-weight: 600;
}
.benefit-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
.benefit-label {
    display: block;
    color: #718078;
    font-size: 12px;
    line-height: 1.6;
}
.benefit-value {
    display: block;
    margin-top: 4px;
    font-size: 16px;
    font-weight: 500;
}
.academy-posters {
    display: grid;
    gap: 18px;
}
.academy-poster {
    display: flex;
    align-items: center;
    position: relative;
    overflow: hidden;
    border: 1px solid #dce9dd;
    border-radius: 24px;
    min-height: clamp(148px, 30cqw, 240px);
    background: linear-gradient(110deg, #f7f8ee, #dceee5);
    box-shadow: 0 4px 8px #254b3310;
}
.poster-registration {
    background: linear-gradient(110deg, #f3f8ef, #d9eddd);
}
.poster-rewards {
    background: linear-gradient(110deg, #fbf7ed, #f3e4bc);
    border-color: #eddfbd;
    color: #79581d;
}
.poster-copy {
    position: relative;
    z-index: 1;
    width: 60%;
    padding: 24px 20px;
}
.poster-number {
    display: block;
    font-size: 12px;
    letter-spacing: 0.2em;
    color: #81988a;
    margin-bottom: 12px;
}
.poster-title {
    display: block;
    font-size: clamp(20px, 4cqw, 30px);
    font-weight: 600;
    line-height: 1.35;
    color: #2c5444;
}
.poster-rewards .poster-title {
    color: #79581d;
}
.poster-link {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 16px;
    font-size: 13px;
    color: #476859;
}
.poster-art {
    position: absolute;
    right: 0;
    width: 47%;
    height: 100%;
}
.academy-posters {
    padding: 6px 0 24px;
}
.academy-poster {
    aspect-ratio: 3/1;
    min-height: 118px;
    border: 1px solid rgba(92, 125, 104, 0.1);
    border-radius: 22px;
    isolation: isolate;
    background: linear-gradient(115deg, #faf9f0 0%, #f3f6eb 48%, #dceee3 100%);
    box-shadow:
        0 7px 20px -12px rgba(56, 82, 60, 0.22),
        inset 0 1px 0 rgba(255, 255, 255, 0.9);
}
.poster-registration {
    background: linear-gradient(115deg, #f4f9ef 0%, #e8f2e6 50%, #d4e8d9 100%);
}
.poster-rewards {
    background: linear-gradient(115deg, #fcf9f0 0%, #f8f0df 48%, #efdfb9 100%);
    border-color: rgba(159, 132, 74, 0.12);
}
.poster-copy {
    position: static;
    width: 56%;
    padding: clamp(16px, 4vw, 34px);
    padding-right: 4px;
    color: #294c3c;
}
.poster-link {
    display: inline-flex;
    margin-top: 10px;
    font-size: 12px;
    color: inherit;
}
.poster-number {
    margin-bottom: 8px;
    font-size: clamp(10px, 2.4vw, 13px);
    font-weight: 500;
    letter-spacing: 0.18em;
    opacity: 0.5;
    color: inherit;
}
.poster-title {
    font-size: clamp(19px, 4.7vw, 30px);
    color: inherit;
}
.poster-art {
    right: -2%;
    width: 53%;
    height: 112%;
}
.poster-rewards .poster-copy,
.poster-rewards .poster-title {
    color: #745b2c;
}
</style>
