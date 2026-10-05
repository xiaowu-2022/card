import { usePlatformUi } from '@/components/admin/platform-ui-context';
import type { InputHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            className={cn(
                'h-10 w-full rounded-lg border bg-surface px-3 text-sm placeholder:text-muted-foreground disabled:opacity-50',
                usePlatformUi() && 'h-9',
                className,
            )}
            {...props}
        />
    );
}
