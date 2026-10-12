import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n/admin';

export function AdminPagination({
    page = 1,
    pages = 1,
    total,
    disabled = false,
    onPage,
    href,
    className = '',
}: {
    page: number;
    pages?: number;
    total?: number;
    disabled?: boolean;
    onPage?: (page: number) => void;
    href?: (page: number) => string;
    className?: string;
}) {
    const [jump, setJump] = useState('');
    const last = Math.max(1, pages);
    const numbers = [
        ...new Set([
            1,
            ...Array.from({ length: 5 }, (_, i) => page + i - 2).filter(
                (value) => value >= 1 && value <= last,
            ),
            ...(pages !== undefined ? [last] : []),
        ]),
    ].sort((a, b) => a - b);
    const control = (target: number, label: string, blocked = false, active = false) =>
        disabled || blocked || active || !href ? (
            <Button
                key={label}
                type="button"
                variant={active ? 'default' : 'secondary'}
                size="sm"
                disabled={disabled || blocked || active}
                aria-current={active ? 'page' : undefined}
                onClick={() => onPage?.(target)}
            >
                {label}
            </Button>
        ) : (
            <Button key={label} asChild variant="secondary" size="sm">
                <Link href={href(target)} preserveState preserveScroll>
                    {label}
                </Link>
            </Button>
        );
    return (
        <nav
            aria-label={t('Pagination')}
            className={`flex flex-wrap items-center gap-2 text-sm ${className}`}
        >
            {total !== undefined && (
                <span className="mr-2 text-muted-foreground">
                    {t('Total records')}: {total}
                </span>
            )}
            {control(page - 1, t('Previous'), page <= 1)}
            {numbers.map((value, i) => (
                <span key={value} className="inline-flex items-center gap-2">
                    {i > 0 && value - numbers[i - 1]! > 1 && (
                        <span aria-hidden="true" className="px-1 text-muted-foreground">
                            …
                        </span>
                    )}
                    {control(value, String(value), false, value === page)}
                </span>
            ))}
            {control(page + 1, t('Next'), page >= last)}
            {pages !== undefined && pages > 1 && (
                <form
                    className="flex items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const target = Number(jump);
                        if (!Number.isInteger(target) || target < 1 || target > last || disabled)
                            return;
                        if (href) {
                            router.get(
                                href(target),
                                {},
                                { preserveState: true, preserveScroll: true },
                            );
                        } else onPage?.(target);
                        setJump('');
                    }}
                >
                    <Input
                        type="number"
                        min={1}
                        max={last}
                        value={jump}
                        disabled={disabled}
                        onChange={(event) => setJump(event.target.value)}
                        aria-label={t('Go to page')}
                        className="h-8 w-20"
                    />
                    <Button
                        type="submit"
                        variant="secondary"
                        size="sm"
                        disabled={disabled || !jump}
                    >
                        {t('Go')}
                    </Button>
                </form>
            )}
        </nav>
    );
}
