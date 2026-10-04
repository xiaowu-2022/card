import { Link } from '@inertiajs/react';
import { t } from '@/i18n/admin';
export type ListPagination = { previous: string | null; next: string | null; total: number };
export function AdminPageLinks({ page }: { page?: ListPagination }) {
    if (!page) return null;
    return (
        <nav
            className="flex items-center justify-between gap-4 border-t p-4"
            aria-label={t('Pagination')}
        >
            <span>
                {t('Total')}: {page.total}
            </span>
            <div className="flex gap-4">
                {page.previous && (
                    <Link preserveScroll href={page.previous}>
                        {t('Previous')}
                    </Link>
                )}
                {page.next && (
                    <Link preserveScroll href={page.next}>
                        {t('Next')}
                    </Link>
                )}
            </div>
        </nav>
    );
}
