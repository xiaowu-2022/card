import { Head, Link } from '@inertiajs/react';
import { AcademyBenefitsPoster } from '@/components/user/AcademyBenefitsPoster';
import { ArrowDown, ArrowUpRight } from 'lucide-react';
import { t, useClientTranslation } from '@/i18n';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { academyRegistration } from '@/lib/academy-registration';
import '../../../css/academy.css';

export default function AcademyRegistration() {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('New user registration guide')} />
            <div className="academy-reader">
                <UserPageHeader
                    title={t('New user registration guide')}
                    backHref="/promotion/rules"
                />
                <article>
                    <AcademyBenefitsPoster />
                    <header className="academy-reader-hero">
                        <p className="academy-reader-eyebrow">{t('U Card Academy')} / 01</p>
                        <h2>{t('From your first invitation to an active account')}</h2>
                        <p>
                            {t(
                                'Follow the steps to register with email, verify your identity and choose how to activate your account.',
                            )}
                        </p>
                    </header>
                    <nav className="academy-reader-toc" aria-label={t('In this guide')}>
                        <p>{t('In this guide')}</p>
                        {academyRegistration.map((section, index) => (
                            <a key={section.id} href={`#${section.id}`}>
                                <span>0{index + 1}</span>
                                {t(section.title)}
                                <ArrowDown size={14} aria-hidden="true" />
                            </a>
                        ))}
                    </nav>
                    {academyRegistration.map((section, index) => (
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
                                <section key={item.title} className="academy-reader-topic">
                                    <h3>
                                        <span aria-hidden="true">
                                            {index + 1}.{itemIndex + 1}
                                        </span>
                                        {t(item.title)}
                                    </h3>
                                    <p>{t(item.body)}</p>
                                </section>
                            ))}
                        </section>
                    ))}
                    <Link className="academy-progress-link" href="/promotion/reward-guide#levels">
                        {t('View membership fees and benefits')}
                        <ArrowUpRight size={16} aria-hidden="true" />
                    </Link>
                </article>
            </div>
        </UserLayout>
    );
}
