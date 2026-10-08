<script setup lang="ts">
import { computed, ref, nextTick, watch, onMounted, getCurrentInstance } from 'vue';
import { onResize } from '@dcloudio/uni-app';
import PageShell from '../components/PageShell.vue';
import UiIcon from '../components/UiIcon.vue';
import FormErrors from '../components/FormErrors.vue';
import AnnualRebateProgress from '../components/AnnualRebateProgress.vue';
import InvitationPoster from '../components/InvitationPoster.vue';
import { invitationUrl } from '../lib/origin';
import { useAction, requestId } from '../lib/client';
import { t, dateTime, locale } from '../lib/i18n';
import { go } from '../lib/navigation';
import { promotionLevel, promotionUnits } from '../lib/promotion';
import type { PaidPromotionData } from '../lib/promotion-types';
type Benefits = Pick<
    PaidPromotionData,
    | 'manualLevel'
    | 'levels'
    | 'rank'
    | 'percent'
    | 'reward'
    | 'membershipStatus'
    | 'previousCycle'
    | 'cycle'
    | 'pending'
    | 'progress'
    | 'upgradeEligibility'
> & { hasClaims: boolean };
const props = defineProps<{
    page: {
        home: {
            posterBackground: string | null;
            paid: Benefits;
            invitationCode: string;
            canPurchase: boolean;
        };
    };
}>();
const poster = ref<InstanceType<typeof InvitationPoster> | null>(null);
const home = computed(() => props.page.home),
    p = computed(() => home.value.paid),
    ranks = computed(() =>
        [...new Set([0, p.value.rank, ...p.value.levels.map((l) => l.rank)])].sort((a, b) => a - b),
    ),
    selected = ref(ranks.value.indexOf(p.value.rank)),
    action = useAction(),
    intents = new Map<string, string>(),
    shareState = ref(''),
    sharing = ref(false);
const link = computed(() => invitationUrl(home.value.invitationCode));
const offers = computed(() =>
    ranks.value.map((rank) => {
        const offer = p.value.levels.find((l) => l.rank === rank),
            current = rank === p.value.rank;
        const fee =
            rank === 0
                ? '0'
                : current && !p.value.manualLevel && p.value.cycle
                  ? p.value.cycle.tariff
                  : offer?.fee;
        return {
            rank,
            offer,
            current,
            fee,
            percent: current ? p.value.percent : offer?.percent,
            reward: rank === 0 ? '20' : current ? p.value.reward : offer?.reward,
            canUpgrade:
                !p.value.manualLevel &&
                !!offer &&
                rank > p.value.rank &&
                (!p.value.cycle ||
                    promotionUnits(offer.fee) > promotionUnits(p.value.cycle.tariff)),
        };
    }),
);
function feeLabel(amount: string) {
    const units = promotionUnits(amount),
        rounded = ((units + 50000000n) / 100000000n).toString();
    return (
        (units % 100000000n === 0n ? '' : '≈ ') +
        rounded.replace(/\B(?=(\d{3})+(?!\d))/g, ',') +
        ' USDT'
    );
}
const instance = getCurrentInstance();
const levelHeight = ref(210);
function measureLevels() {
    levelHeight.value = 0;
    nextTick(() =>
        uni
            .createSelectorQuery()
            .in(instance?.proxy)
            .selectAll('.level-card')
            .boundingClientRect((rects: any) => {
                if (Array.isArray(rects) && rects.length)
                    levelHeight.value = Math.ceil(Math.max(...rects.map((r) => r.height))) + 8;
            })
            .exec(),
    );
}
onMounted(measureLevels);
watch([offers, locale], measureLevels);
onResize(measureLevels);
const expiry = computed(() =>
    p.value.cycle
        ? t('Valid until {{time}}', { time: dateTime(p.value.cycle.endsAt) })
        : t('Your current benefits'),
);
async function quote(id: string) {
    if (action.pending.value || p.value.manualLevel || p.value.pending || !home.value.canPurchase)
        return;
    const key = intents.get(id) ?? requestId();
    intents.set(id, key);
    const result = await action.submit('/promotion/quotes', { level_id: id, request_id: key });
    if (result) intents.delete(id);
}
async function copy() {
    try {
        await new Promise<void>((resolve, reject) =>
            uni.setClipboardData({
                data: link.value,
                showToast: false,
                success: () => resolve(),
                fail: reject,
            }),
        );
        shareState.value = 'copied';
    } catch {
        shareState.value = 'failed';
    }
}
async function share() {
    sharing.value = true;
    shareState.value = '';
    try {
        // #ifdef H5
        if (navigator.share) {
            await navigator.share({ title: t('Invitation to join'), url: link.value });
            shareState.value = 'shared';
            return;
        }
        // #endif
        // #ifdef APP-PLUS
        await new Promise<void>((resolve, reject) =>
            uni.shareWithSystem({
                type: 'text',
                summary: t('Invitation to join') + ' ' + link.value,
                href: link.value,
                success: () => resolve(),
                fail: reject,
            }),
        );
        shareState.value = 'shared';
        return;
        // #endif
        await copy();
    } catch (e) {
        if (!(e instanceof Error && e.name === 'AbortError')) await copy();
    } finally {
        sharing.value = false;
    }
}
</script>
<template>
    <PageShell :title="t('Promotion center')" back="/account" active="account" white
        ><view class="hub"
            ><FormErrors :errors="action.errors.value" /><view
                ><swiper
                    class="level-swiper"
                    :style="{ height: Math.max(210, levelHeight) + 'px' }"
                    :current="selected"
                    :previous-margin="'24px'"
                    :next-margin="'24px'"
                    @change="selected = $event.detail.current"
                    ><swiper-item v-for="offer in offers" :key="offer.rank"
                        ><view
                            class="level-card"
                            :class="{ current: offer.current }"
                            :style="{ minHeight: Math.max(0, levelHeight - 8) + 'px' }"
                            ><view class="card-heading"
                                ><view
                                    ><text class="level-name">{{ promotionLevel(offer.rank) }}</text
                                    ><text v-if="offer.current" class="current-label">{{
                                        t('Current level')
                                    }}</text></view
                                ><text class="price"
                                    >{{
                                        offer.rank === 0
                                            ? '300USDT'
                                            : offer.fee === undefined
                                              ? t('Not configured')
                                              : feeLabel(offer.fee)
                                    }}
                                    <text
                                        v-if="offer.rank > 0 && offer.fee !== undefined"
                                        class="small"
                                        >{{ t('per year') }}</text
                                    ></text
                                ></view
                            ><view class="benefits"
                                ><view
                                    ><text class="small">{{ t('Direct activation standard') }}</text
                                    ><text class="benefit"
                                        >{{ offer.reward ?? '—' }}
                                        <text class="small">USDT</text></text
                                    ></view
                                ><view
                                    ><text class="small">{{ t('Annual fee reward rate') }}</text
                                    ><text class="benefit">{{
                                        offer.rank === 0 || offer.percent === undefined
                                            ? '—'
                                            : offer.percent + '%'
                                    }}</text></view
                                ></view
                            ><AnnualRebateProgress
                                v-if="offer.current && p.cycle && p.progress"
                                :progress="p.progress"
                            /><view class="card-footer"
                                ><text v-if="offer.current" class="small">{{ expiry }}</text
                                ><text v-else-if="offer.rank < p.rank" class="small">{{
                                    t('Downgrades are not available.')
                                }}</text
                                ><text
                                    v-else-if="!offer.offer?.enabled || !offer.canUpgrade"
                                    class="small"
                                    >{{ t('Currently unavailable for purchase') }}</text
                                ><button
                                    v-else
                                    class="primary"
                                    :disabled="
                                        action.pending.value ||
                                        p.pending ||
                                        !home.canPurchase ||
                                        !offer.offer.selectable
                                    "
                                    @click="quote(offer.offer.id)"
                                >
                                    {{
                                        t(
                                            p.membershipStatus === 'EXPIRED'
                                                ? 'Renew or reactivate'
                                                : p.rank > 0
                                                  ? 'Upgrade to this level'
                                                  : 'Apply for this level',
                                        )
                                    }}
                                </button></view
                            ><text
                                v-if="offer.offer?.unavailableReason && !offer.current"
                                class="small"
                                >{{ t(offer.offer.unavailableReason) }}</text
                            ></view
                        ></swiper-item
                    ></swiper
                ><view class="switcher"
                    ><button :disabled="selected === 0" @click="selected--">‹</button
                    ><view class="dots"
                        ><button
                            v-for="(rank, i) in ranks"
                            :key="rank"
                            :aria-label="promotionLevel(rank)"
                            @click="selected = i"
                        >
                            <view :class="{ selected: selected === i }" /></button></view
                    ><button :disabled="selected === ranks.length - 1" @click="selected++">
                        ›
                    </button></view
                ><text class="swipe-note muted">{{ t('Swipe to compare levels') }}</text
                ><text v-if="!home.canPurchase" class="note muted">{{
                    t('An active verified USDT wallet is required to purchase a level.')
                }}</text
                ><view v-if="p.pending" class="pending"
                    ><text>{{ t('Annual fee return is processing. Try upgrading shortly.') }}</text
                    ><button class="link" @click="go('/promotion/membership')">
                        {{ t('Fee rebate history') }}
                    </button></view
                ></view
            ><view class="destinations"
                ><button
                    v-for="entry in [
                        { href: '/promotion/invitations', label: 'Invitation data', icon: 'users' },
                        { href: '/promotion/rules', label: 'U Card Academy', icon: 'book-open' },
                    ]"
                    :key="entry.href"
                    @click="go(entry.href)"
                >
                    <UiIcon :name="entry.icon" color="#27866d" :size="20" /><text>{{
                        t(entry.label)
                    }}</text
                    ><UiIcon name="chevron-right" :size="16" /></button></view
            ><view
                ><text class="heading">{{ t('Invitation steps') }}</text
                ><view class="invite-steps"
                    ><view
                        v-for="(step, i) in [
                            'Share your invitation link with a friend.',
                            'Your friend registers through the link and joins your team.',
                            'After a successful security deposit or promotion annual fee payment, rewards follow your valid level and the commission rules.',
                        ]"
                        :key="step"
                        ><text class="step-number">{{ i + 1 }}</text
                        ><text>{{ t(step) }}</text></view
                    ></view
                ></view
            ><view class="invitation-code"
                ><view
                    ><text class="small muted">{{ t('Invitation code') }}</text
                    ><text class="code">{{ home.invitationCode }}</text></view
                ><button class="copy" @click="copy">
                    <UiIcon name="copy" :size="16" />{{ t('Copy invitation link') }}
                </button></view
            ><view class="share-dock"
                ><view class="share-buttons"
                    ><button class="primary" @click="poster?.generate()">
                        <UiIcon name="image" color="#ffffff" :size="16" /><text>{{
                            t('Invitation poster')
                        }}</text></button
                    ><button class="primary" :disabled="sharing" @click="share">
                        <UiIcon name="share2" color="#ffffff" :size="16" /><text>{{
                            t('Share invitation link')
                        }}</text>
                    </button></view
                ><text v-if="shareState" class="small muted">{{
                    t(
                        shareState === 'copied'
                            ? 'Copied'
                            : shareState === 'shared'
                              ? 'Invitation link shared.'
                              : 'Could not copy. Copy the link below manually.',
                    )
                }}</text
                ><text v-if="shareState === 'failed'" class="link-text" selectable>{{
                    link
                }}</text></view
            ></view
        ><InvitationPoster
            ref="poster"
            :link="link"
            :code="home.invitationCode"
            :background="home.posterBackground"
    /></PageShell>
</template>
<style scoped>
.hub {
    display: flex;
    flex-direction: column;
    gap: 20px;
    font-size: 14px;
    padding-bottom: 110px;
}
.level-swiper {
    margin: 0;
}
.level-card {
    box-sizing: border-box;
    margin: 2px 6px 6px;
    display: flex;
    flex-direction: column;
    border-radius: 16px;
    border: 1px solid #e8dec5;
    padding: 16px;
    background: #faf5e7;
    color: #193b31;
}
.level-card.current {
    background: #193b31;
    color: #fff0bb;
    border-color: #c7ac6b;
}
.card-heading {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
    align-items: start;
}
.level-name {
    font-size: 16px;
    line-height: 24px;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.price {
    font-size: 14px;
    font-weight: 600;
    line-height: 24px;
    text-align: right;
    max-width: 145px;
}
.small {
    font-size: 12px;
    font-weight: 400;
    line-height: 20px;
}
.current-label {
    display: block;
    width: fit-content;
    margin-top: 8px;
    border-radius: 999px;
    border: 1px solid #fff0bb;
    padding: 2px 8px;
    font-size: 12px;
    line-height: 16px;
}
.benefits {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid currentColor;
}
.benefits > view > .small {
    opacity: 0.75;
}
.benefit {
    display: block;
    font-size: 18px;
    font-weight: 600;
    margin-top: 4px;
}
.card-footer {
    margin-top: auto;
    padding-top: 12px;
}
.card-footer button {
    width: 100%;
    font-size: 14px;
}
.switcher {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-top: 12px;
}
.switcher button {
    background: none;
    margin: 0;
    padding: 0;
    width: 44px;
    height: 44px;
    line-height: 44px;
    font-size: 24px;
}
.dots {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
}
.dots button {
    width: 24px;
    display: flex;
    justify-content: center;
    align-items: center;
}
.dots view {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: #42a88c33;
}
.dots view.selected {
    width: 20px;
    background: #42a88c;
}
.swipe-note {
    display: block;
    text-align: center;
    font-size: 12px;
    line-height: 20px;
}
.note {
    display: block;
    margin-top: 12px;
    font-size: 12px;
    line-height: 20px;
}
.pending {
    padding: 12px;
    border-radius: 12px;
    background: #f0f3f1;
    margin-top: 12px;
}
.link {
    display: inline;
    background: none;
    padding: 0;
    text-decoration: underline;
    font-size: 14px;
    line-height: 22px;
}
.destinations {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.destinations button {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    border-radius: 16px;
    border: 1px solid #e2e7e4;
    background: white;
    font-size: 14px;
    line-height: 20px;
    text-align: left;
    margin: 0;
}
.destinations text {
    flex: 1;
    overflow-wrap: anywhere;
}
.heading {
    font-weight: 600;
    font-size: 16px;
}
.invite-steps {
    margin: 20px 0;
    display: flex;
    flex-direction: column;
}
.invite-steps > view {
    display: flex;
    gap: 12px;
    align-items: start;
    line-height: 1.8;
    font-size: 13px;
    position: relative;
    padding-bottom: 24px;
}
.invite-steps > view:not(:last-child)::before {
    content: '';
    position: absolute;
    top: 24px;
    bottom: 0;
    left: 11px;
    border-left: 1px dashed #a9c7b9;
}
.step-number {
    width: 24px;
    height: 24px;
    background: #e3f0e9;
    color: #254235;
    border-radius: 50%;
    text-align: center;
    flex-shrink: 0;
    font-size: 12px;
}
.invitation-code {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 16px;
}
.code {
    display: block;
    font-size: 20px;
    letter-spacing: 0.1em;
    font-weight: 600;
    margin-top: 4px;
}
.copy {
    display: flex;
    align-items: center;
    gap: 8px;
    background: none;
    font-size: 14px;
    padding: 0;
    line-height: 22px;
}
.share-dock {
    position: fixed;
    /* Match PageShell tabs; older App WebViews do not support container units. */
    bottom: 80px;
    bottom: calc(var(--shell-tab-height, 80px) + env(safe-area-inset-bottom, 0px) + var(--shell-viewport-bottom, 0px));
    left: 50%;
    transform: translateX(-50%);
    width: 100%;
    max-width: 750px;
    box-sizing: border-box;
    background: white;
    border-top: 1px solid #e2e7e4;
    padding: 8px 16px;
    padding: 8px clamp(20px, 4.267vw, 32px);
    z-index: 30;
}
.share-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.share-buttons > .primary {
    gap: 8px;
    width: 100%;
    border-radius: 999px;
    font-size: 16px;
    font-size: clamp(16px, 3.2vw, 24px);
    line-height: 1.5;
    padding: 4px 24px;
    min-height: 48px;
    min-height: clamp(48px, 10.667vw, 80px);
}
.share-buttons :deep(button text) {
    font-size: inherit;
    line-height: 1.5;
}
.share-dock > .small {
    display: block;
    margin-top: 8px;
}
.link-text {
    display: block;
    font-size: 12px;
    overflow-wrap: anywhere;
    margin-top: 8px;
}
</style>
