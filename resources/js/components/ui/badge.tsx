import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

const badgeVariants = cva(
    'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold',
    {
        variants: {
            tone: {
                neutral: 'bg-muted text-muted-foreground',
                success: 'bg-emerald-50 text-success',
                warning: 'bg-amber-50 text-warning',
                danger: 'bg-red-50 text-danger',
                info: 'bg-blue-50 text-info',
            },
        },
        defaultVariants: { tone: 'neutral' },
    },
);

export function Badge({
    className,
    tone,
    ...props
}: HTMLAttributes<HTMLSpanElement> & VariantProps<typeof badgeVariants>) {
    return <span className={cn(badgeVariants({ tone }), className)} {...props} />;
}
