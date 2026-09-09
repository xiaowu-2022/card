import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export function Alert({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            role="alert"
            className={cn('rounded-lg border bg-blue-50 p-4 text-sm text-info', className)}
            {...props}
        />
    );
}
export function AlertTitle({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
    return <h5 className={cn('font-semibold', className)} {...props} />;
}
export function AlertDescription({ className, ...props }: HTMLAttributes<HTMLParagraphElement>) {
    return <p className={cn('mt-1 text-current/80', className)} {...props} />;
}
