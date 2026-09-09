import * as RadioGroupPrimitive from '@radix-ui/react-radio-group';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function RadioGroup({
    className,
    ...props
}: ComponentProps<typeof RadioGroupPrimitive.Root>) {
    return <RadioGroupPrimitive.Root className={cn('grid gap-2', className)} {...props} />;
}
export function RadioGroupItem({
    className,
    ...props
}: ComponentProps<typeof RadioGroupPrimitive.Item>) {
    return (
        <RadioGroupPrimitive.Item
            className={cn(
                'size-5 rounded-full border bg-surface data-[state=checked]:border-[6px] data-[state=checked]:border-primary',
                className,
            )}
            {...props}
        />
    );
}
