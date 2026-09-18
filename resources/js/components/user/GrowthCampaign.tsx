import { Link } from '@inertiajs/react';
import { ArrowUpRight, Pause, Play } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { t, useClientTranslation } from '@/i18n';

/** Public landing retains the approved portrait; Assets uses the compact carousel. */
export function GrowthCampaign({ variant }: { variant: 'public' | 'account' }) {
    useClientTranslation();
    if (variant === 'account') return <AssetCampaignCarousel />;
    const description = [
        'Mastercard',
        t('Mastercard U Card'),
        t('Growth strategy announcement'),
        t('Aim to reach 500 million users worldwide by 2030'),
        t('Long-term operational investment'),
        t('Growth through referrals'),
        t('Ongoing user acquisition'),
    ].join(' · ');
    const poster = (
        <img
            className="growth-campaign-art"
            src="/images/marketing/growth/poster-1114.jpg"
            srcSet="/images/marketing/growth/poster-770.jpg 770w, /images/marketing/growth/poster-1114.jpg 1114w"
            sizes="(max-width: 767px) calc(100vw - 32px), 686px"
            width={1114}
            height={1412}
            alt={description}
            loading="lazy"
            decoding="async"
        />
    );
    const action = (
        <span className="growth-campaign-action">
            {t('Explore products')}
            <ArrowUpRight aria-hidden="true" size={18} />
        </span>
    );
    return (
        <section
            className="growth-campaign growth-campaign-public"
            aria-label={t('Growth strategy announcement')}
        >
            {poster}
            <a href="#products">{action}</a>
        </section>
    );
}

const campaigns = [
    { image: 'invitation', title: 'Invite and earn', subtitle: 'Explore referral rewards', href: '/promotion' },
    { image: 'cards', title: 'Card services', subtitle: 'Manage your cards in one place', href: '/cards' },
    { image: 'wealth', title: 'Earn on savings', subtitle: 'Fixed terms, monthly interest', href: '/wealth' },
];

function AssetCampaignCarousel() {
    useClientTranslation();
    const [active, setActive] = useState(0);
    const [paused, setPaused] = useState(false);
    const [hovered, setHovered] = useState(false);
    const [reducedMotion, setReducedMotion] = useState(false);
    const touch = useRef<{ x: number; y: number } | null>(null);
    const swiped = useRef(false);
    useEffect(() => {
        const media = window.matchMedia('(prefers-reduced-motion: reduce)');
        const update = () => setReducedMotion(media.matches);
        update();
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, []);
    useEffect(() => {
        if (paused || hovered || reducedMotion) return;
        const timer = window.setInterval(() => {
            if (!document.hidden) setActive(value => (value + 1) % campaigns.length);
        }, 6000);
        return () => window.clearInterval(timer);
    }, [paused, hovered, reducedMotion]);
    const select = (index: number) => {
        setPaused(true);
        setActive((index + campaigns.length) % campaigns.length);
    };
    return (
        <section className={`asset-campaign ${active === 0 ? 'asset-campaign-dark' : ''}`} aria-label={t('Featured services')}
            onMouseEnter={() => setHovered(true)} onMouseLeave={() => setHovered(false)}
            onFocusCapture={event => {
                if (!event.target.closest('.asset-campaign-play')) setPaused(true);
            }}
            onKeyDown={event => {
                if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
                    event.preventDefault();
                    select(active + (event.key === 'ArrowRight' ? 1 : -1));
                }
            }}
            onTouchStart={event => {
                const point = event.touches[0];
                if (!point) return;
                touch.current = { x: point.clientX, y: point.clientY };
                swiped.current = false;
            }}
            onTouchEnd={event => {
                const start = touch.current;
                touch.current = null;
                if (!start) return;
                const point = event.changedTouches[0];
                if (!point) return;
                const dx = point.clientX - start.x;
                if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(point.clientY - start.y)) {
                    swiped.current = true;
                    select(active + (dx < 0 ? 1 : -1));
                }
            }}
            onClickCapture={event => {
                if (swiped.current) { event.preventDefault(); event.stopPropagation(); swiped.current = false; }
            }}>
            {campaigns.map((campaign, index) => (
                <Link key={campaign.image} href={campaign.href} className="asset-campaign-slide"
                    hidden={index !== active} aria-label={t(campaign.title)}>
                    <img src={`/images/marketing/growth/${index === 0 ? 'invitation-growth' : campaign.image}-banner.jpg`}
                        width={1200} height={400} alt={index === 0 ? [
                            'Mastercard', t('Mastercard U Card'), t('Growth strategy announcement'),
                            t('Aim to reach 500 million users worldwide by 2030'),
                            t('Long-term operational investment'), t('Growth through referrals'),
                            t('Ongoing user acquisition'), t('Visit promotion center'),
                        ].join(' · ') : ''} decoding="async" />
                    {index !== 0 && <div className="asset-campaign-copy">
                        <strong>{t(campaign.title)}</strong>
                        <span>{t(campaign.subtitle)}</span>
                        <ArrowUpRight size={17} aria-hidden="true" />
                    </div>}
                </Link>
            ))}
            <div className="asset-campaign-controls">
                {campaigns.map((campaign, index) => (
                    <button key={campaign.image} type="button" className="asset-campaign-dot"
                        aria-label={t(campaign.title)} aria-pressed={active === index}
                        onClick={() => select(index)}><span /></button>
                ))}
            </div>
            {!reducedMotion && <button type="button" className="asset-campaign-play"
                aria-label={t(paused ? 'Play banners' : 'Pause banners')}
                onClick={() => setPaused(value => !value)}>
                {paused ? <Play size={12} aria-hidden="true" /> : <Pause size={12} aria-hidden="true" />}
            </button>}
        </section>
    );
}
