import { t } from '@/i18n';

const topics = [
    { key: 'features', title: 'Feature introduction', image: 'features.svg' },
    { key: 'invitations', title: 'Invitation introduction', image: 'invitations.svg' },
    { key: 'rewards', title: 'Reward introduction', image: 'rewards.svg' },
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
                    </div>
                    <img src={`/images/academy/${topic.image}`} alt="" width={360} height={250} />
                </section>
            ))}
        </div>
    );
}
