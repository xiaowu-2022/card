import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import type { ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
    'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold transition-colors disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground hover:brightness-95',
                secondary: 'border bg-surface text-foreground hover:bg-muted',
                ghost: 'text-foreground hover:bg-muted',
                destructive: 'bg-danger text-white hover:brightness-95',
            },
            size: { default: 'h-10', sm: 'h-9 px-3', lg: 'h-12 px-5', icon: 'size-10 p-0' },
        },
        defaultVariants: { variant: 'default', size: 'default' },
    },
);

interface ButtonProps
    extends ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {
    asChild?: boolean;
}

export function Button({ className, variant, size, asChild = false, ...props }: ButtonProps) {
    const Component = asChild ? Slot : 'button';
    return <Component className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}
