import { Head } from '@inertiajs/react';
import { t, useClientTranslation } from '@/i18n';
import { UserArticleLayout } from '@/layouts/UserArticleLayout';
import { tenantArticles, type TenantArticleKey } from '@/lib/tenant-articles';

export default function AboutArticle({
    article,
}: {
    article: { key: TenantArticleKey; locale: string; body: string | null };
}) {
    useClientTranslation();
    const title = t(tenantArticles.find((item) => item.key === article.key)?.title ?? 'About us');
    return (
        <UserArticleLayout title={title} backHref="/about">
            <Head title={title} />
            {article.body ? (
                <article lang={article.locale} className="user-article-body">
                    {article.body}
                </article>
            ) : (
                <p className="py-10 text-center text-sm text-muted-foreground" role="status">
                    {t('This article is not available in the selected language yet.')}
                </p>
            )}
        </UserArticleLayout>
    );
}
