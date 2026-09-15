import type { ReactNode } from 'react';

export function FormField({
    id,
    label,
    description,
    error,
    children,
}: {
    id: string;
    label: string;
    description?: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <label htmlFor={id} className="block text-sm font-semibold">
                {label}
            </label>
            {children}
            {description && <p className="text-sm text-muted-foreground">{description}</p>}
            {error && (
                <p id={`${id}-error`} className="text-sm font-medium text-danger" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}
