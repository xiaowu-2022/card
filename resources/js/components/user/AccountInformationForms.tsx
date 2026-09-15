import { PhoneInput } from '@/components/user/PhoneInput';
import { normalizedPhone } from '@/lib/phone-input';
import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { t, errorMessage } from '@/i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/ui/form-field';

export type ContactChallenge = {
    id: string;
    channel: 'EMAIL' | 'PHONE';
    destination: string;
    expiresAt: string;
    resendAt?: string;
    deliveryUncertain: boolean;
};

function FormErrors({ errors }: { errors: Record<string, string> }) {
    return Object.keys(errors).length ? (
        <div role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-800">
            {[...new Set(Object.values(errors))].map((error) => (
                <p key={error}>{errorMessage(error)}</p>
            ))}
        </div>
    ) : null;
}

export function NameForm({ name }: { name: string }) {
    const form = useForm({ display_name: name });
    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/account/information/name', { preserveScroll: true });
            }}
        >
            <FormErrors errors={form.errors} />
            <FormField id="display_name" label={t('Display name')}>
                <Input
                    id="display_name"
                    value={form.data.display_name}
                    autoComplete="nickname"
                    maxLength={80}
                    required
                    onChange={(event) => form.setData('display_name', event.target.value)}
                />
            </FormField>
            <Button type="submit" disabled={form.processing} className="w-full">
                {t('Save name')}
            </Button>
        </form>
    );
}

export function ContactForm({
    channel,
    challenge,
}: {
    channel: 'EMAIL' | 'PHONE';
    challenge: ContactChallenge | null;
}) {
    const [editing, setEditing] = useState(false);
    const [region, setRegion] = useState('CN');
    const [requestId] = useState(() => crypto.randomUUID());
    const form = useForm({ channel, new_contact: '', current_password: '', request_id: requestId });
    const pending = challenge?.channel === channel ? challenge : null;

    if (pending && !editing)
        return (
            <ContactVerification
                key={pending.id}
                challenge={pending}
                onChange={() => {
                    form.setData('request_id', crypto.randomUUID());
                    form.clearErrors();
                    setEditing(true);
                }}
            />
        );

    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    ...data,
                    new_contact:
                        channel === 'PHONE'
                            ? normalizedPhone(data.new_contact, region)
                            : data.new_contact,
                }));
                form.post('/account/information/contacts', {
                    preserveScroll: true,
                    onError: (errors) => {
                        if (errors.form === 'Start a new contact change request.') {
                            form.setData('request_id', crypto.randomUUID());
                        }
                    },
                    onSuccess: () => {
                        form.reset('current_password');
                        setEditing(false);
                    },
                });
            }}
        >
            <p className="text-sm text-muted-foreground">
                {t(
                    channel === 'EMAIL'
                        ? 'Verify your current password, then enter the code sent to your new email address to save the change.'
                        : 'Verify your current password, then enter the SMS code sent to your new phone number to save the change.',
                )}
            </p>
            <FormErrors errors={form.errors} />
            <FormField
                id={`new_contact_${channel}`}
                label={t(channel === 'EMAIL' ? 'New email address' : 'New phone number')}
                description={
                    channel === 'PHONE'
                        ? t('Select a country code and enter your phone number.')
                        : undefined
                }
            >
                {channel === 'PHONE' ? (
                    <PhoneInput
                        id={`new_contact_${channel}`}
                        value={form.data.new_contact}
                        region={region}
                        onRegionChange={(value) => {
                            setRegion(value);
                            form.setData('request_id', crypto.randomUUID());
                        }}
                        onChange={(value) =>
                            form.setData({
                                ...form.data,
                                new_contact: value,
                                request_id: crypto.randomUUID(),
                            })
                        }
                        disabled={form.processing}
                    />
                ) : (
                    <Input
                        id={`new_contact_${channel}`}
                        type={channel === 'EMAIL' ? 'email' : 'tel'}
                        autoComplete={channel === 'EMAIL' ? 'email' : 'tel'}
                        value={form.data.new_contact}
                        maxLength={255}
                        required
                        disabled={form.processing}
                        onChange={(event) =>
                            form.setData({
                                ...form.data,
                                new_contact: event.target.value,
                                request_id: crypto.randomUUID(),
                            })
                        }
                    />
                )}
            </FormField>
            <FormField id={`contact_current_password_${channel}`} label={t('Current password')}>
                <Input
                    id={`contact_current_password_${channel}`}
                    type="password"
                    autoComplete="current-password"
                    value={form.data.current_password}
                    required
                    disabled={form.processing}
                    onChange={(event) => form.setData('current_password', event.target.value)}
                />
            </FormField>
            <Button type="submit" disabled={form.processing} className="w-full">
                {t(
                    form.processing
                        ? 'Sending…'
                        : channel === 'EMAIL'
                          ? 'Send email verification code'
                          : 'Send SMS verification code',
                )}
            </Button>
        </form>
    );
}

function ContactVerification({
    challenge,
    onChange,
}: {
    challenge: ContactChallenge;
    onChange: () => void;
}) {
    const form = useForm({ code: '', confirmed: false });
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);
    const remaining = Math.max(0, Math.ceil((Date.parse(challenge.expiresAt) - now) / 1000));
    const resendRemaining = Math.max(
        0,
        Math.ceil((Date.parse(challenge.resendAt ?? challenge.expiresAt) - now) / 1000),
    );
    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/account/information/contacts/${challenge.id}/verify`, {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <div className="space-y-1 text-sm">
                <p>
                    {t('New login contact')}:{' '}
                    <span className="font-medium break-all">{challenge.destination}</span>
                </p>
                <p className="text-muted-foreground">
                    {t(
                        challenge.deliveryUncertain
                            ? 'Delivery is not confirmed. If a code arrives, use it here; wait until it expires before resending.'
                            : challenge.channel === 'EMAIL'
                              ? 'Enter the verification code sent to your new email address.'
                              : 'Enter the SMS verification code sent to your new phone number.',
                    )}
                </p>
            </div>
            <p role="status" className="text-sm text-muted-foreground">
                {remaining > 0
                    ? t('Code expires in {{seconds}}s', { seconds: remaining })
                    : t('Verification code expired. Request a new code.')}
            </p>
            <FormErrors errors={form.errors} />
            <FormField
                id={`contact_code_${challenge.channel}`}
                label={t(
                    challenge.channel === 'EMAIL'
                        ? 'Email verification code'
                        : 'SMS verification code',
                )}
            >
                <Input
                    id={`contact_code_${challenge.channel}`}
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    pattern="[0-9]{6}"
                    minLength={6}
                    maxLength={6}
                    required
                    value={form.data.code}
                    onChange={(event) => form.setData('code', event.target.value)}
                />
            </FormField>
            <label className="flex items-start gap-3 rounded-xl border p-3 text-sm leading-6">
                <input
                    type="checkbox"
                    className="mt-1 size-4 shrink-0 accent-[var(--user-primary)]"
                    required
                    checked={form.data.confirmed}
                    onChange={(event) => form.setData('confirmed', event.target.checked)}
                />
                <span>
                    {t(
                        'I confirm replacing this login contact. The old contact will no longer work for login.',
                    )}
                </span>
            </label>
            <Button
                type="submit"
                className="w-full"
                disabled={form.processing || !form.data.confirmed || remaining === 0}
            >
                {t('Confirm contact change')}
            </Button>
            <Button
                type="button"
                variant="ghost"
                className="w-full"
                disabled={form.processing || resendRemaining > 0}
                onClick={onChange}
            >
                {resendRemaining > 0
                    ? t('Request a new code in {{seconds}}s', { seconds: resendRemaining })
                    : t('Request a new code')}
            </Button>
            <Button
                type="button"
                variant="ghost"
                className="w-full"
                disabled={form.processing}
                onClick={onChange}
            >
                {t('Change contact details')}
            </Button>
        </form>
    );
}
