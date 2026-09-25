import { t, useClientTranslation, errorMessage } from '@/i18n';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    ChevronDown,
    Mail,
    LockKeyhole,
    UserRoundPen,
    MonitorX,
    type LucideIcon,
} from 'lucide-react';
import type { SharedProps } from '@/types/global';
import {
    ContactForm,
    NameForm,
    type ContactChallenge,
} from '@/components/user/AccountInformationForms';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { AccountVerificationLink } from '@/components/user/AccountVerificationLink';
import { UserLayout } from '@/layouts/UserLayout';

type Information = {
    canEdit: boolean;
    email: string | null;
    challenge: ContactChallenge | null;
};

export default function Security({
    information,
    kycStatus,
}: {
    information: Information;
    kycStatus: string;
}) {
    useClientTranslation();
    const { auth } = usePage<SharedProps>().props;
    const [expanded, setExpanded] = useState<string | null>(
        information.challenge?.channel === 'EMAIL' ? 'EMAIL' : null,
    );
    const items: { id: string; title: string; value: string; icon: LucideIcon }[] = [
        {
            id: 'name',
            title: t('Display name'),
            value: auth.user?.displayName || t('Account user'),
            icon: UserRoundPen,
        },
        {
            id: 'EMAIL',
            title: t('Email address'),
            value: information.email || t('Not linked'),
            icon: Mail,
        },
        { id: 'password', title: t('Change password'), value: '••••••••', icon: LockKeyhole },
        {
            id: 'sessions',
            title: t('Sign out other devices'),
            value: t('Keep this device signed in'),
            icon: MonitorX,
        },
    ];
    return (
        <UserLayout>
            <Head title={t('Account and security')} />
            <div className="mx-auto max-w-2xl space-y-6">
                <UserPageHeader title={t('Account and security')} backHref="/account" />
                <AccountVerificationLink status={kycStatus} fromSecurity />
                <div className="overflow-hidden rounded-3xl border bg-surface">
                    {items.map(({ id, title, value, icon: Icon }) => (
                        <section key={id} className="border-b last:border-0">
                            <h2>
                                <button
                                    type="button"
                                    className="flex min-h-24 w-full items-center gap-3 px-5 py-5 text-left disabled:opacity-50 sm:px-6"
                                    disabled={
                                        !['password', 'sessions'].includes(id) &&
                                        !information.canEdit
                                    }
                                    aria-expanded={expanded === id}
                                    aria-controls={`information-${id}`}
                                    onClick={() => setExpanded(expanded === id ? null : id)}
                                >
                                    <Icon
                                        className="size-5 shrink-0 text-[var(--user-primary)]"
                                        aria-hidden="true"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm text-muted-foreground">
                                            {title}
                                        </span>
                                        <span className="mt-1 block font-medium break-all">
                                            {value}
                                        </span>
                                    </span>
                                    <ChevronDown
                                        className={`size-4 shrink-0 text-muted-foreground ${expanded === id ? 'rotate-180' : ''}`}
                                        aria-hidden="true"
                                    />
                                </button>
                            </h2>
                            <div
                                id={`information-${id}`}
                                hidden={expanded !== id}
                                className="px-5 pb-6 sm:px-6"
                            >
                                {id === 'name' && <NameForm name={auth.user?.displayName ?? ''} />}
                                {id === 'EMAIL' && (
                                    <ContactForm channel={id} challenge={information.challenge} />
                                )}
                                {id === 'password' && <PasswordForm />}
                                {id === 'sessions' && <RevokeSessionsForm />}
                            </div>
                        </section>
                    ))}
                </div>
            </div>
        </UserLayout>
    );
}

function PasswordForm() {
    useClientTranslation();
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    const formError = (form.errors as Record<string, string>).form;
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/account/security/password', {
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <p className="text-sm text-muted-foreground">
                {t(
                    'Changing your password requires other devices to sign in again. This device stays signed in.',
                )}
            </p>
            {formError && (
                <Alert className="border-red-200 bg-red-50 text-red-800">
                    <AlertTitle>{t('Password not changed')}</AlertTitle>
                    <AlertDescription>{errorMessage(formError)}</AlertDescription>
                </Alert>
            )}
            <FormField
                id="current_password"
                label={t('Current password')}
                error={errorMessage(form.errors.current_password)}
            >
                <Input
                    id="current_password"
                    type="password"
                    autoComplete="current-password"
                    value={form.data.current_password}
                    onChange={(event) => form.setData('current_password', event.target.value)}
                    required
                />
            </FormField>
            <FormField
                id="password"
                label={t('New password')}
                description={t('At least 6 characters.')}
                error={errorMessage(form.errors.password)}
            >
                <Input
                    id="password"
                    type="password"
                    autoComplete="new-password"
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                    required
                />
            </FormField>
            <FormField id="password_confirmation" label={t('Confirm new password')}>
                <Input
                    id="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    value={form.data.password_confirmation}
                    onChange={(event) => form.setData('password_confirmation', event.target.value)}
                    required
                />
            </FormField>
            <Button className="w-full sm:w-auto" type="submit" disabled={form.processing}>
                {t('Change password')}
            </Button>
        </form>
    );
}

function RevokeSessionsForm() {
    const form = useForm({ current_password: '', confirmed: false });
    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/account/security/sessions/revoke', {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <p className="text-sm text-muted-foreground">
                {t(
                    'Other devices will be blocked on their next request. Your password and administrator login are unchanged.',
                )}
            </p>
            {Object.keys(form.errors).length > 0 && (
                <div role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-800">
                    {Object.values(form.errors).map((error, index) => (
                        <p key={index}>{errorMessage(error)}</p>
                    ))}
                </div>
            )}
            <FormField id="revoke_current_password" label={t('Current password')}>
                <Input
                    id="revoke_current_password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={form.data.current_password}
                    onChange={(event) => form.setData('current_password', event.target.value)}
                />
            </FormField>
            <label className="flex items-start gap-3 text-sm leading-6">
                <input
                    type="checkbox"
                    className="mt-1 size-4 shrink-0 accent-[var(--user-primary)]"
                    required
                    checked={form.data.confirmed}
                    onChange={(event) => form.setData('confirmed', event.target.checked)}
                />
                <span>{t('I confirm signing out all other devices for this account.')}</span>
            </label>
            <Button
                type="submit"
                className="w-full"
                disabled={form.processing || !form.data.confirmed}
            >
                {t('Sign out other devices')}
            </Button>
        </form>
    );
}
