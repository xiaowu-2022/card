import { CreditCard, Home, UserRound, WalletCards } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export const userNavigation: ReadonlyArray<{ label: string; href: string; icon: LucideIcon }> = [
    { label: 'Home', href: '/dashboard', icon: Home },
    { label: 'Wallet', href: '/wallet', icon: WalletCards },
    { label: 'Cards', href: '/cards', icon: CreditCard },
    { label: 'Me', href: '/account', icon: UserRound },
];
