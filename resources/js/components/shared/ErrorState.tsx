import { AlertTriangle } from 'lucide-react';
import type { ReactNode } from 'react';

export function ErrorState({
    title,
    description,
    action,
}: {
    title: string;
    description: string;
    action?: ReactNode;
}) {
    return (
        <div className="rounded-xl border border-red-200 bg-red-50 px-6 py-8">
            <AlertTriangle className="size-6 text-danger" />
            <h3 className="mt-3 font-semibold text-danger">{title}</h3>
            <p className="mt-1 text-sm text-red-800">{description}</p>
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
