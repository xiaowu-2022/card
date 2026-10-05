import { useState } from 'react';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Head } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { PlatformSupportTabs } from '@/components/support/PlatformSupportTabs';
import { SupportProfile } from '@/components/support/SupportProfile';
import { t, useAdminTranslation } from '@/i18n/admin';
type Agent = { id: string; name: string; email: string; supportName: string | null };
export default function SupportAgents({
    agents,
    filters,
}: {
    agents: AccountPage<Agent>;
    filters: { search?: string };
}) {
    useAdminTranslation();
    const [editing, setEditing] = useState<Agent | null>(null);
    const [formState, setFormState] = useState({ dirty: false, busy: false });
    return (
        <PlatformLayout title={t('Customer support')}>
            <Head title={t('Support staff')} />
            <div className="space-y-4">
                <PlatformSupportTabs agents />
                <p className="text-sm text-muted-foreground">
                    {t(
                        'A blank nickname displays Customer support. Changes apply to future messages only.',
                    )}
                </p>
                <PlatformAccountTable
                    key={JSON.stringify(filters)}
                    page={agents}
                    filters={filters}
                    url="/platform/support/agents"
                    searchLabel={t('Search name or email')}
                    columns={[
                        { label: 'Name', render: (a) => a.name },
                        {
                            label: 'Email',
                            className: 'w-64 max-w-64',
                            render: (a) => (
                                <span className="block w-56 truncate" title={a.email}>
                                    {a.email}
                                </span>
                            ),
                        },
                        {
                            label: 'Support nickname',
                            render: (a) => (
                                <div className="flex gap-3">
                                    <span>{a.supportName ?? '—'}</span>
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        onClick={() => setEditing(a)}
                                    >
                                        {t('Edit')}
                                    </Button>
                                </div>
                            ),
                        },
                    ]}
                />
                <Dialog
                    open={!!editing}
                    onOpenChange={(open) => {
                        if (
                            !open &&
                            !formState.busy &&
                            (!formState.dirty || confirm(t('Discard unsaved changes?')))
                        )
                            setEditing(null);
                    }}
                >
                    <DialogContent aria-describedby={undefined} closeDisabled={formState.busy}>
                        <DialogTitle>
                            {t('Support nickname')} · {editing?.name}
                        </DialogTitle>
                        {editing && (
                            <SupportProfile
                                name={editing.supportName}
                                url={`/platform/support/agents/${editing.id}`}
                                onSaved={() => setEditing(null)}
                                onState={(dirty, busy) =>
                                    setFormState((old) =>
                                        old.dirty === dirty && old.busy === busy
                                            ? old
                                            : { dirty, busy },
                                    )
                                }
                            />
                        )}
                    </DialogContent>
                </Dialog>
            </div>
        </PlatformLayout>
    );
}
