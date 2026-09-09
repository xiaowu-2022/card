import type { TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return (
        <textarea
            className={cn(
                'min-h-24 w-full rounded-lg border bg-surface px-3 py-2 text-sm placeholder:text-muted-foreground disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}
