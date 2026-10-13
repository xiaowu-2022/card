import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { OperationFeedback } from './OperationFeedback';
import { errorMessage, t } from '@/i18n/admin';

function firstValidationError(result: unknown): string | undefined {
    if (!result || typeof result !== 'object' || !('errors' in result)) return;
    if (!result.errors || typeof result.errors !== 'object') return;
    for (const group of Object.values(result.errors) as unknown[]) {
        if (Array.isArray(group)) {
            const first: unknown = group[0];
            if (typeof first === 'string') return first;
        }
    }
}

export function UserSnapshotExport({ filters }: { filters: Record<string, string | undefined> }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const download = async () => {
        setBusy(true);
        setError('');
        try {
            const query = new URLSearchParams();
            for (const key of ['company', 'search', 'status', 'support', 'referrer', 'as_of']) {
                const value = filters[key];
                if (value) query.set(key, value);
            }
            const response = await fetch(`/platform/users/export?${query}`, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                const result: unknown = await response.json().catch(() => null);
                setError(
                    errorMessage(firstValidationError(result)) ??
                        t('Unable to export. Please retry.'),
                );
                return;
            }
            if (!response.headers.get('content-type')?.startsWith('text/csv')) throw new Error();
            const objectUrl = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = objectUrl;
            link.download =
                response.headers.get('content-disposition')?.match(/filename="([^"]+)"/)?.[1] ??
                'users.csv';
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
        } catch {
            setError(t('Unable to export. Please retry.'));
        } finally {
            setBusy(false);
        }
    };
    return (
        <div className="flex flex-wrap items-center gap-2">
            <Button
                variant="secondary"
                disabled={busy}
                onClick={() => {
                    void download();
                }}
            >
                {t(busy ? 'Exporting…' : 'Export table')}
            </Button>
            {error && <OperationFeedback role="alert">{error}</OperationFeedback>}
        </div>
    );
}
