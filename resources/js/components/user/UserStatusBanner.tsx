import { Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock3, Info } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const tones = {
    neutral: { icon: Info, className: 'border-border bg-surface' },
    success: { icon: CheckCircle2, className: 'border-emerald-200 bg-emerald-50 text-emerald-950' },
    warning: { icon: AlertTriangle, className: 'border-amber-200 bg-amber-50 text-amber-950' },
    pending: { icon: Clock3, className: 'border-sky-200 bg-sky-50 text-sky-950' },
};

export function UserStatusBanner({
    title,
    description,
    tone = 'neutral',
    action,
}: {
    title: string;
    description: string;
    tone?: keyof typeof tones;
    action?: { label: string; href: string };
}) {
    const state = tones[tone];
    const Icon = state.icon;
    return (
        <section className={cn('rounded-[var(--user-radius-md)] border p-5', state.className)}>
            <div className="flex gap-3">
                <Icon className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                <div className="min-w-0 flex-1">
                    <h2 className="font-semibold">{title}</h2>
                    <p className="mt-1 break-words text-sm leading-6 opacity-80">{description}</p>
                    {action ? (
                        <Button asChild size="sm" className="mt-4">
                            <Link href={action.href}>{action.label}</Link>
                        </Button>
                    ) : null}
                </div>
            </div>
        </section>
    );
}
