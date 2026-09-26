import { t } from '@/i18n';
import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';

const topics = [
    {
        key: 'invitations',
        title: 'New user registration guide',
        image: 'invitations.svg',
        href: '/promotion/registration',
    },
    {
        key: 'features',
        title: 'Buttons and features guide',
        image: 'features.svg',
        href: '/promotion/features',
    },
    {
        key: 'rewards',
        title: 'Reward rules guide',
        image: 'rewards.svg',
        href: '/promotion/reward-guide',
    },
] as const;

export function AcademyPosters() {
    return (
        <div className="academy-posters">
            {topics.map((topic, index) => (
                <section className={`academy-poster academy-poster-${topic.key}`} key={topic.key}>
                    <div className="academy-poster-copy">
                        <span className="academy-poster-number" aria-hidden="true">
                            0{index + 1}
                        </span>
                        <h2>{t(topic.title)}</h2>
                        <Link href={topic.href} className="academy-poster-link">
                            {t('Read the guide')} <ArrowUpRight size={14} aria-hidden="true" />
                        </Link>
                    </div>
                    <img src={`/images/academy/${topic.image}`} alt="" width={360} height={250} />
                </section>
            ))}
        </div>
    );
}
