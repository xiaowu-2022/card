import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { t, useClientTranslation } from '@/i18n';

/** Approved original artwork: preserve its text, composition and portrait ratio. */
export function GrowthCampaign({ variant }: { variant: 'public' | 'account' }) {
    useClientTranslation();
    const account = variant === 'account';
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
            {t(account ? 'Visit promotion center' : 'Explore products')}
            <ArrowUpRight aria-hidden="true" size={18} />
        </span>
    );
    return account ? (
        <Link className="growth-campaign growth-campaign-account" href="/promotion">
            {poster}
            {action}
        </Link>
    ) : (
        <section
            className="growth-campaign growth-campaign-public"
            aria-label={t('Growth strategy announcement')}
        >
            {poster}
            <a href="#products">{action}</a>
        </section>
    );
}
