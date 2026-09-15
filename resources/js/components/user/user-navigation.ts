import { Aperture, CreditCard, UserRound } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export const userNavigation: ReadonlyArray<{ label: string; href: string; icon: LucideIcon }> = [
    { label: 'Assets', href: '/dashboard', icon: Aperture },
    { label: 'Cards', href: '/cards', icon: CreditCard },
    { label: 'Me', href: '/account', icon: UserRound },
];
