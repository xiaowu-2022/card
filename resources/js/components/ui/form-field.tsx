import { usePlatformUi } from '@/components/admin/platform-ui-context';
import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

export function FormField({
    id,
    label,
    description,
    error,
    children,
}: {
    id: string;
    label: string;
    description?: string;
    error?: string;
    children: ReactNode;
}) {
    const platform = usePlatformUi();
    return (
        <div className={cn('space-y-1.5', platform && 'min-w-0')}>
            <label
                htmlFor={id}
                className={cn('block text-sm font-semibold', platform && 'font-medium')}
            >
                {label}
            </label>
            {children}
            {description && (
                <p className={cn('text-sm text-muted-foreground', platform && 'text-xs')}>
                    {description}
                </p>
            )}
            {error && (
                <p id={`${id}-error`} className="text-sm font-medium text-danger" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}
