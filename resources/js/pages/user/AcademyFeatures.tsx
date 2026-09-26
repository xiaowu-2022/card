import { Head } from '@inertiajs/react';
import { ArrowDown } from 'lucide-react';
import { t, useClientTranslation } from '@/i18n';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { academyGuide } from '@/lib/academy-guide';
import '../../../css/academy.css';

export default function AcademyFeatures() {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Buttons and features guide')} />
            <div className="academy-reader">
                <UserPageHeader title={t('Buttons and features guide')} backHref="/promotion/rules" />
                <article>
                    <header className="academy-reader-hero">
                        <p className="academy-reader-eyebrow">{t('U Card Academy')} / 02</p>
                        <h2>{t('Your guide to Assets, Cards and Me')}</h2>
                        <p>
                            {t(
                                'Explore the three main areas and learn how to manage funds, use your card and find your invitation information.',
                            )}
                        </p>
                    </header>
                    <nav className="academy-reader-toc" aria-label={t('In this guide')}>
                        <p>{t('In this guide')}</p>
                        {academyGuide.map((section, index) => (
                            <a key={section.id} href={`#${section.id}`}>
                                <span>0{index + 1}</span>
                                {t(section.title)}
                                <ArrowDown size={14} aria-hidden="true" />
                            </a>
                        ))}
                    </nav>
                    {academyGuide.map((section, index) => (
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
                </article>
            </div>
        </UserLayout>
    );
}
