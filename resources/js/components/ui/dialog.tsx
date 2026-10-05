import { usePlatformUi } from '@/components/admin/platform-ui-context';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { useRef, type ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;
export function DialogContent({
    className,
    children,
    closeLabel = 'Close',
    closeDisabled = false,
    onOpenAutoFocus,
    onCloseAutoFocus,
    ...props
}: ComponentProps<typeof DialogPrimitive.Content> & {
    closeLabel?: string;
    closeDisabled?: boolean;
}) {
    const platform = usePlatformUi();
    const returnFocus = useRef<HTMLElement | null>(null);
    return (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-slate-950/40" />
            <DialogPrimitive.Content
                data-platform-ui={platform || undefined}
                className={cn(
                    'fixed left-1/2 top-1/2 z-50 max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto overscroll-contain -translate-x-1/2 -translate-y-1/2 rounded-xl border bg-surface p-6 shadow-xl',
                    platform && 'p-4',
                    className,
                )}
                {...props}
                onOpenAutoFocus={(event) => {
                    returnFocus.current =
                        document.activeElement instanceof HTMLElement
                            ? document.activeElement
                            : null;
                    onOpenAutoFocus?.(event);
                }}
                onCloseAutoFocus={(event) => {
                    onCloseAutoFocus?.(event);
                    if (!event.defaultPrevented && returnFocus.current?.isConnected) {
                        event.preventDefault();
                        returnFocus.current.focus();
                    }
                }}
            >
                {children}
                <DialogPrimitive.Close
                    className="absolute right-3 top-3 grid size-11 place-items-center rounded-md text-muted-foreground hover:bg-muted disabled:opacity-50"
                    aria-label={closeLabel}
                    disabled={closeDisabled}
                >
                    <X className="size-4" />
                </DialogPrimitive.Close>
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    );
}
export function DialogHeader({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('mb-4 space-y-1.5', className)} {...props} />;
}
export function DialogTitle({ className, ...props }: ComponentProps<typeof DialogPrimitive.Title>) {
    return <DialogPrimitive.Title className={cn('text-lg font-semibold', className)} {...props} />;
}
export function DialogDescription({
    className,
    ...props
}: ComponentProps<typeof DialogPrimitive.Description>) {
    return (
        <DialogPrimitive.Description
            className={cn('text-sm text-muted-foreground', className)}
            {...props}
        />
    );
}
