import { CreditCard } from 'lucide-react';

export function AppMark({ name = 'Aperture' }: { name?: string }) {
    return (
        <div className="flex items-center gap-2.5 font-semibold tracking-tight">
            <span className="grid size-9 place-items-center rounded-lg bg-primary text-white">
                <CreditCard className="size-5" />
            </span>
            <span>{name}</span>
        </div>
    );
}
