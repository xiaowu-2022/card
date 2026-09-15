import { useClientTranslation } from '@/i18n';
import type { ReactNode } from 'react';

export function UserSection({
    title,
    description,
    action,
    children,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    useClientTranslation();
    return (
        <section
            className="min-w-0"
            aria-labelledby={`user-section-${title.replaceAll(' ', '-').toLowerCase()}`}
        >
            <div className="mb-3 flex items-end justify-between gap-4 px-1">
                <div>
                    <h2
                        id={`user-section-${title.replaceAll(' ', '-').toLowerCase()}`}
                        className="user-section-heading"
                    >
                        {title}
                    </h2>
                    {description ? (
                        <p className="mt-1 text-sm text-muted-foreground">{description}</p>
                    ) : null}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}
