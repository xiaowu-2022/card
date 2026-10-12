import { AdminPagination } from '@/components/admin/AdminPagination';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { useState } from 'react';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { supportRequest } from './supportRequest';
import { t } from '@/i18n/admin';
type Page = {
    data: { id: string; title: string; body: string; tenant_id: string | null }[];
    current_page: number;
    last_page: number;
};
export function QuickReplyPicker({
    url,
    disabled,
    onPick,
}: {
    url: string;
    disabled: boolean;
    onPick: (body: string) => boolean;
}) {
    const [open, setOpen] = useState(false),
        [search, setSearch] = useState(''),
        [data, setData] = useState<Page | null>(null),
        [busy, setBusy] = useState(false),
        [error, setError] = useState('');
    const load = async (page = 1) => {
        setBusy(true);
        setError('');
        try {
            setData(
                await supportRequest<Page>(
                    url + '?' + new URLSearchParams({ search, page: String(page) }).toString(),
                ),
            );
        } catch {
            setError(t('Unable to load. Please try again.'));
        } finally {
            setBusy(false);
        }
    };
    return (
        <>
            <Button
                type="button"
                variant="secondary"
                disabled={disabled}
                onClick={() => {
                    setOpen(true);
                    void load();
                }}
            >
                {t('Quick replies')}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent aria-describedby={undefined}>
                    <DialogTitle>{t('Quick replies')}</DialogTitle>
                    <form
                        className="flex gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void load();
                        }}
                    >
                        <Input
                            aria-label={t('Search quick replies')}
                            maxLength={120}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <Button disabled={busy}>{t('Search')}</Button>
                    </form>
                    {error && <OperationFeedback role="alert">{error}</OperationFeedback>}
                    <div className="max-h-[50vh] space-y-2 overflow-y-auto">
                        {data?.data.map((row) => (
                            <button
                                key={row.id}
                                type="button"
                                disabled={busy || disabled}
                                className="block w-full rounded-lg border p-3 text-left"
                                onClick={() => {
                                    if (onPick(row.body)) setOpen(false);
                                    else setError(t('The message would exceed 2000 characters.'));
                                }}
                            >
                                <strong>{row.title}</strong>
                                <span className="ml-3 text-xs text-muted-foreground">
                                    {t(row.tenant_id ? 'Company shared' : 'Personal')}
                                </span>
                                <p className="mt-2 whitespace-pre-wrap text-sm">{row.body}</p>
                            </button>
                        ))}
                        {data && !data.data.length && <p>{t('No quick replies yet.')}</p>}
                    </div>
                    <AdminPagination
                        page={data?.current_page ?? 1}
                        pages={data?.last_page ?? 1}
                        disabled={busy || !data}
                        onPage={(page) => void load(page)}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}
