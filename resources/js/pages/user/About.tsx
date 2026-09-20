import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { t, useClientTranslation } from '@/i18n';
import { UserArticleLayout } from '@/layouts/UserArticleLayout';
import { tenantArticles } from '@/lib/tenant-articles';

export default function About() {
    useClientTranslation();
    return (
        <UserArticleLayout title={t('About us')} backHref="/account/settings">
            <Head title={t('About us')} />
            <nav aria-label={t('About us')} className="user-about-links">
                {tenantArticles
                    .filter((article) => article.key !== 'account-closure')
                    .map((article) => (
                        <Link
                            key={article.key}
                            href={`/about/${article.key}`}
                            className="user-about-link"
                        >
                            <span>{t(article.title)}</span>
                            <ChevronRight aria-hidden="true" />
                        </Link>
                    ))}
            </nav>
        </UserArticleLayout>
    );
}
