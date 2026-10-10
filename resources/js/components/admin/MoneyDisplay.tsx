import { adminAssetLabel } from '@/lib/admin-asset-label';
import type { ComponentProps } from 'react';
import { MoneyDisplay as SharedMoneyDisplay } from '@/components/shared/MoneyDisplay';

export function MoneyDisplay(props: ComponentProps<typeof SharedMoneyDisplay>) {
    return (
        <SharedMoneyDisplay
            {...props}
            assetLabel={adminAssetLabel(props.assetLabel ?? props.asset)}
            exact
        />
    );
}
