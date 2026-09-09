import * as SwitchPrimitive from '@radix-ui/react-switch';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function Switch({ className, ...props }: ComponentProps<typeof SwitchPrimitive.Root>) {
    return (
        <SwitchPrimitive.Root
            className={cn(
                'h-6 w-11 rounded-full bg-slate-300 p-0.5 transition-colors data-[state=checked]:bg-primary',
                className,
            )}
            {...props}
        >
            <SwitchPrimitive.Thumb className="block size-5 rounded-full bg-white shadow transition-transform data-[state=checked]:translate-x-5" />
        </SwitchPrimitive.Root>
    );
}
