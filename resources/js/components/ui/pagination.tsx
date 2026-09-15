import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import { Button } from './button';
import { t, useClientTranslation } from '@/i18n';

export function Pagination({ className, ...props }: HTMLAttributes<HTMLElement>) {
    useClientTranslation();
    return <nav aria-label={t('Pagination')} className={className} {...props} />;
}
export function PaginationControls({ page = 1, pages = 1 }: { page?: number; pages?: number }) {
    useClientTranslation();
    return (
        <Pagination className="flex items-center justify-between gap-3">
            <p className="text-sm text-muted-foreground">
                {t('Page {{page}} of {{pages}}', { page, pages })}
            </p>
            <div className="flex gap-2">
                <Button
                    variant="secondary"
                    size="icon"
                    disabled={page <= 1}
                    aria-label={t('Previous page')}
                >
                    <ChevronLeft className="size-4" />
                </Button>
                <Button
                    variant="secondary"
                    size="icon"
                    disabled={page >= pages}
                    aria-label={t('Next page')}
                >
                    <ChevronRight className="size-4" />
                </Button>
            </div>
        </Pagination>
    );
}
