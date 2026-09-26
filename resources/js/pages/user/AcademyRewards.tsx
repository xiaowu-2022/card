import { Head, Link } from '@inertiajs/react';
import { AcademyBenefitsPoster } from '@/components/user/AcademyBenefitsPoster';
import { ArrowDown, ArrowUpRight } from 'lucide-react';
import { t, useClientTranslation } from '@/i18n';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import type { PaidLevel } from '@/components/user/PaidPromotionSummary';
import { promotionLevel } from '@/lib/paid-promotion';
import { exactAmount } from '@/lib/exact-amount';
import { academyRewards } from '@/lib/academy-rewards';
import '../../../css/academy.css';

function LevelBenefits({ levels }: { levels: PaidLevel[] }) {
    const rows = [
        { id: 'ordinary', rank: 0, fee: '0', reward: '20', percent: 0, target: null },
        ...levels.filter((level) => level.enabled).sort((a, b) => a.rank - b.rank),
    ];
    return (
        <section className="academy-benefits" aria-label={t('Current level benefits')}>
            <h3>{t('Current level benefits')}</h3>
            {rows.map((level) => (
                <div className="academy-benefit-row" key={level.id}>
                    <h4>{promotionLevel(level.rank)}</h4>
                    <dl>
                        <div>
                            <dt>{t('Annual fee · USDT')}</dt>
                            <dd>{exactAmount(level.fee)}</dd>
                        </div>
                        <div>
                            <dt>{t('Direct activation · USDT')}</dt>
                            <dd>{exactAmount(level.reward)}</dd>
                        </div>
                        <div>
                            <dt>{t('Direct annual-fee rate')}</dt>
                            <dd>{level.percent}%</dd>
                        </div>
                        <div>
                            <dt>{t('Weighted return target')}</dt>
                            <dd>{level.target ?? t('No annual-fee return target')}</dd>
                        </div>
                    </dl>
                </div>
            ))}
        </section>
    );
}

export default function AcademyRewards({ levels }: { levels: PaidLevel[] }) {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Reward rules guide')} />
            <div className="academy-reader academy-rewards">
                <UserPageHeader title={t('Reward rules guide')} backHref="/promotion/rules" />
                <article>
                    <AcademyBenefitsPoster />
                    <header className="academy-reader-hero">
                        <p className="academy-reader-eyebrow">{t('U Card Academy')} / 03</p>
                        <h2>{t('Understand your rewards, one rule at a time')}</h2>
                        <p>
                            {t(
                                'A guide to membership benefits, activation commissions, annual-fee rewards and return progress.',
                            )}
                        </p>
                    </header>
                    <nav className="academy-reader-toc" aria-label={t('In this guide')}>
                        <p>{t('In this guide')}</p>
                        {academyRewards.map((section, index) => (
                            <a key={section.id} href={`#${section.id}`}>
                                <span>0{index + 1}</span>
                                {t(section.title)}
                                <ArrowDown size={14} aria-hidden="true" />
                            </a>
                        ))}
                    </nav>
                    {academyRewards.map((section, index) => (
                        <section
                            id={section.id}
                            key={section.id}
                            className="academy-reader-section"
                        >
                            <header>
                                <span className="academy-reader-index" aria-hidden="true">
                                    0{index + 1}
                                </span>
                                <div>
                                    <h2>{t(section.title)}</h2>
                                    <p>{t(section.intro)}</p>
                                </div>
                            </header>
                            {section.items.map((item, itemIndex) => (
                                <section
                                    key={item.title}
                                    className={`academy-reader-topic${item.title.startsWith('Example:') || item.title === 'Direct examples at a 60% rate' ? ' academy-reward-example' : ''}`}
                                >
                                    <h3>
                                        <span aria-hidden="true">
                                            {index + 1}.{itemIndex + 1}
                                        </span>
                                        {t(item.title)}
                                    </h3>
                                    <p>{t(item.body)}</p>
                                </section>
                            ))}
                            {section.id === 'levels' && <LevelBenefits levels={levels} />}
                        </section>
                    ))}
                    <Link className="academy-progress-link" href="/promotion">
                        {t('View my promotion progress')}
                        <ArrowUpRight size={16} aria-hidden="true" />
                    </Link>
                </article>
            </div>
        </UserLayout>
    );
}
