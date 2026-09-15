import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { t, useAdminTranslation } from '@/i18n/admin';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PageHeader } from '@/components/shared/PageHeader';
import { TenantSmsSettings, type SmsSettings } from '@/components/admin/TenantSmsSettings';
import { TenantEmailSettings, type EmailSettings } from '@/components/admin/TenantEmailSettings';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import {
    Table,
    TableHeader,
    TableBody,
    TableHead,
    TableRow,
    TableCell,
} from '@/components/ui/table';
type Profile = {
    id: string;
    name: string;
    enabled: boolean;
    available: boolean;
    companyCount: number;
} & Partial<SmsSettings & EmailSettings>;
const emptySms: SmsSettings = {
    enabled: false,
    credentialsConfigured: false,
    signName: '',
    verificationTemplateCode: '',
    existingAccountTemplateCode: '',
    resendIntervalSeconds: 60,
    codeTtlSeconds: 600,
};
const emptyEmail: EmailSettings = {
    enabled: false,
    tokenConfigured: false,
    fromAddress: '',
    fromName: '',
    dailyRecipientLimit: 10,
};
export default function NotificationProfiles({
    channel,
    profiles,
}: {
    channel: 'sms' | 'email';
    profiles: Profile[];
}) {
    useAdminTranslation();
    const [editing, setEditing] = useState<Profile | 'new' | null>(null);
    const [saving, setSaving] = useState(false);
    const title = t(channel === 'sms' ? 'SMS configurations' : 'Email configurations');
    const selected = editing && editing !== 'new' ? editing : null;
    const actionUrl = `/platform/settings/${channel}${selected ? `/${selected.id}` : ''}`;
    return (
        <PlatformLayout>
            <Head title={title} />
            <div className="space-y-6">
                <PageHeader
                    title={title}
                    actions={
                        <Button onClick={() => setEditing('new')}>{t('Add configuration')}</Button>
                    }
                />
                <div className="min-w-0 overflow-hidden rounded-xl border bg-surface">
                    <Table className="min-w-[600px]">
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('Configuration name')}</TableHead>
                                <TableHead>
                                    {t(channel === 'sms' ? 'SMS signature' : 'Sender email')}
                                </TableHead>
                                <TableHead>{t('Status')}</TableHead>
                                <TableHead>{t('Companies using this configuration')}</TableHead>
                                <TableHead>{t('Actions')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {profiles.map((profile) => (
                                <TableRow key={profile.id}>
                                    <TableCell>{profile.name}</TableCell>
                                    <TableCell>
                                        {channel === 'sms'
                                            ? profile.signName || '—'
                                            : profile.fromAddress || '—'}
                                    </TableCell>
                                    <TableCell>
                                        {t(
                                            profile.available
                                                ? 'Available'
                                                : profile.enabled
                                                  ? 'Not configured'
                                                  : 'Disabled',
                                        )}
                                    </TableCell>
                                    <TableCell>{profile.companyCount}</TableCell>
                                    <TableCell>
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            onClick={() => setEditing(profile)}
                                        >
                                            {t('Edit')}
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {profiles.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        {t('No configurations yet.')}
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
            <Dialog
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open && !saving) setEditing(null);
                }}
            >
                <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>
                            {t(selected ? 'Edit configuration' : 'Add configuration')}
                        </DialogTitle>
                        <DialogDescription
                            className={selected?.companyCount ? undefined : 'sr-only'}
                        >
                            {selected?.companyCount
                                ? t('Changes apply to all companies using this configuration.')
                                : title}
                        </DialogDescription>
                    </DialogHeader>
                    {editing &&
                        (channel === 'sms' ? (
                            <TenantSmsSettings
                                key={selected?.id ?? 'new'}
                                settings={{ ...emptySms, ...selected }}
                                name={selected?.name ?? ''}
                                actionUrl={actionUrl}
                                onSaved={() => setEditing(null)}
                                onProcessingChange={setSaving}
                            />
                        ) : (
                            <TenantEmailSettings
                                key={selected?.id ?? 'new'}
                                settings={{ ...emptyEmail, ...selected }}
                                name={selected?.name ?? ''}
                                actionUrl={actionUrl}
                                onSaved={() => setEditing(null)}
                                onProcessingChange={setSaving}
                            />
                        ))}
                </DialogContent>
            </Dialog>
        </PlatformLayout>
    );
}
