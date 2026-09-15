import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export const Sheet = DialogPrimitive.Root;
export const SheetTrigger = DialogPrimitive.Trigger;
export const SheetClose = DialogPrimitive.Close;
export function SheetContent({
    className,
    children,
    closeLabel = 'Close menu',
    ...props
}: ComponentProps<typeof DialogPrimitive.Content> & { closeLabel?: string }) {
    return (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-slate-950/40" />
            <DialogPrimitive.Content
                className={cn(
                    'fixed inset-y-0 left-0 z-50 w-[min(88vw,20rem)] border-r bg-surface p-5 shadow-xl',
                    className,
                )}
                {...props}
            >
                {children}
                <DialogPrimitive.Close
                    className="absolute right-4 top-4 rounded-md p-1"
                    aria-label={closeLabel}
                >
                    <X className="size-5" />
                </DialogPrimitive.Close>
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    );
}
export const SheetTitle = DialogPrimitive.Title;
export const SheetDescription = DialogPrimitive.Description;
