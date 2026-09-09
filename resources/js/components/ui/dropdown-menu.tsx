import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export const DropdownMenu = DropdownMenuPrimitive.Root;
export const DropdownMenuTrigger = DropdownMenuPrimitive.Trigger;
export function DropdownMenuContent({
    className,
    ...props
}: ComponentProps<typeof DropdownMenuPrimitive.Content>) {
    return (
        <DropdownMenuPrimitive.Portal>
            <DropdownMenuPrimitive.Content
                className={cn(
                    'z-50 min-w-44 rounded-lg border bg-surface p-1 shadow-lg',
                    className,
                )}
                sideOffset={6}
                {...props}
            />
        </DropdownMenuPrimitive.Portal>
    );
}
export function DropdownMenuItem({
    className,
    ...props
}: ComponentProps<typeof DropdownMenuPrimitive.Item>) {
    return (
        <DropdownMenuPrimitive.Item
            className={cn(
                'cursor-default rounded-md px-3 py-2 text-sm outline-none focus:bg-muted',
                className,
            )}
            {...props}
        />
    );
}
