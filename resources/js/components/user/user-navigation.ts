import { CreditCard, Home, UserRound, WalletCards } from 'lucide-react';

export const userNavigation = [
    { label: 'Home', href: '/dashboard', icon: Home },
    { label: 'Wallet', href: '/wallet', icon: WalletCards },
    { label: 'Cards', href: null, icon: CreditCard },
    { label: 'Me', href: '/account', icon: UserRound },
] as const;
