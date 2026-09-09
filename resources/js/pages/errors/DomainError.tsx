import { Head } from '@inertiajs/react';
import { ErrorState } from '@/components/shared/ErrorState';
import { PublicLayout } from '@/layouts/PublicLayout';

export default function DomainError({
    status,
    message,
    requestId,
}: {
    status: number;
    message: string;
    requestId: string;
}) {
    return (
        <PublicLayout>
            <Head title={`Error ${status}`} />
            <div className="mx-auto max-w-2xl px-4 py-20">
                <ErrorState title={`Request unavailable (${status})`} description={message} />
                <p className="mt-4 text-center text-xs text-muted-foreground">
                    Request ID: {requestId}
                </p>
            </div>
        </PublicLayout>
    );
}
