import { PhoneInput } from '@/components/user/PhoneInput';
import { normalizedPhone } from '@/lib/phone-input';
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { t, errorMessage, useClientTranslation } from '@/i18n';
import { PasswordRecoveryLayout } from '@/layouts/PasswordRecoveryLayout';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

export default function ForgotPassword() {
    useClientTranslation();
    const [region, setRegion] = useState('CN');
    const [id] = useState(() => crypto.randomUUID());
    const form = useForm({ channel: 'EMAIL', reset_contact: '', request_id: id });
    return (
        <PasswordRecoveryLayout title={t('Recover password')}>
            <p className="mb-6 text-sm leading-6 text-muted-foreground">
                {t(
                    'Use the verified email or phone already linked to your account. This does not create a new account.',
                )}
            </p>
            <div className="mb-6 flex gap-2" role="group" aria-label={t('Recovery method')}>
                {(['EMAIL', 'PHONE'] as const).map((channel) => (
                    <Button
                        key={channel}
                        type="button"
                        className="min-w-0 flex-1"
                        variant={form.data.channel === channel ? 'default' : 'secondary'}
                        aria-pressed={form.data.channel === channel}
                        disabled={form.processing}
                        onClick={() => {
                            form.clearErrors();
                            form.setData({
                                channel,
                                reset_contact: '',
                                request_id: crypto.randomUUID(),
                            });
                        }}
                    >
                        {t(channel === 'EMAIL' ? 'Email' : 'Phone number')}
                    </Button>
                ))}
            </div>
            <form
                className="space-y-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((data) => ({
                        ...data,
                        reset_contact:
                            data.channel === 'PHONE'
                                ? normalizedPhone(data.reset_contact, region)
                                : data.reset_contact,
                    }));
                    form.post('/forgot-password', {
                        onError: (errors) => {
                            if (
                                errors.form ===
                                'This password reset request is invalid or expired. Request a new code.'
                            )
                                form.setData('request_id', crypto.randomUUID());
                        },
                    });
                }}
            >
                {Object.keys(form.errors).length > 0 && (
                    <div role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-800">
                        {Object.values(form.errors).map((error, index) => (
                            <p key={index}>{errorMessage(error)}</p>
                        ))}
                    </div>
                )}
                <FormField
                    id="reset_contact"
                    label={t(form.data.channel === 'EMAIL' ? 'Email address' : 'Phone number')}
                    description={
                        form.data.channel === 'PHONE'
                            ? t('Select a country code and enter your phone number.')
                            : undefined
                    }
                >
                    {form.data.channel === 'PHONE' ? (
                        <PhoneInput
                            id="reset_contact"
                            value={form.data.reset_contact}
                            region={region}
                            onRegionChange={(value) => {
                                setRegion(value);
                                form.setData('request_id', crypto.randomUUID());
                            }}
                            onChange={(value) =>
                                form.setData({
                                    ...form.data,
                                    reset_contact: value,
                                    request_id: crypto.randomUUID(),
                                })
                            }
                            disabled={form.processing}
                        />
                    ) : (
                        <Input
                            id="reset_contact"
                            type={form.data.channel === 'EMAIL' ? 'email' : 'tel'}
                            autoComplete="username"
                            value={form.data.reset_contact}
                            required
                            maxLength={255}
                            disabled={form.processing}
                            onChange={(event) =>
                                form.setData({
                                    ...form.data,
                                    reset_contact: event.target.value,
                                    request_id: crypto.randomUUID(),
                                })
                            }
                        />
                    )}
                </FormField>
                <Button
                    type="submit"
                    className="w-full"
                    disabled={form.processing || !form.data.reset_contact}
                >
                    {t(form.processing ? 'Sending…' : 'Send verification code')}
                </Button>
            </form>
        </PasswordRecoveryLayout>
    );
}
