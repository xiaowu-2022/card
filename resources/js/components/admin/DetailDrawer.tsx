import type { ComponentProps } from 'react';
import { DialogContent } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

// Detail workspaces keep the list mounted behind a viewport-bound right drawer.
export function DetailDrawerContent({ className, ...props }: ComponentProps<typeof DialogContent>) {
    return (
        <DialogContent
            {...props}
            data-admin-detail-drawer="true"
            className={cn(
                'inset-y-0 left-auto right-0 top-0 flex h-dvh max-h-dvh w-[min(92vw,72rem)] max-w-none translate-x-0 translate-y-0 flex-col overflow-hidden rounded-none border-y-0 border-r-0',
                className,
            )}
        />
    );
}
