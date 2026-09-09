import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import { Button } from './button';

export function Pagination({ className, ...props }: HTMLAttributes<HTMLElement>) {
    return <nav aria-label="Pagination" className={className} {...props} />;
}
export function PaginationControls({ page = 1, pages = 1 }: { page?: number; pages?: number }) {
    return (
        <Pagination className="flex items-center justify-between gap-3">
            <p className="text-sm text-muted-foreground">
                Page {page} of {pages}
            </p>
            <div className="flex gap-2">
                <Button
                    variant="secondary"
                    size="icon"
                    disabled={page <= 1}
                    aria-label="Previous page"
                >
                    <ChevronLeft className="size-4" />
                </Button>
                <Button
                    variant="secondary"
                    size="icon"
                    disabled={page >= pages}
                    aria-label="Next page"
                >
                    <ChevronRight className="size-4" />
                </Button>
            </div>
        </Pagination>
    );
}
