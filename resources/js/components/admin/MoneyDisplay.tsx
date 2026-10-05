import type { ComponentProps } from 'react';
import { MoneyDisplay as SharedMoneyDisplay } from '@/components/shared/MoneyDisplay';

export function MoneyDisplay(props: ComponentProps<typeof SharedMoneyDisplay>) {
    return <SharedMoneyDisplay {...props} exact />;
}
