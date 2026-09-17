import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { t, errorMessage, useClientTranslation } from '@/i18n';
import { PasswordRecoveryLayout } from '@/layouts/PasswordRecoveryLayout';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

export default function ForgotPassword() {
    useClientTranslation();
    const [id] = useState(() => crypto.randomUUID());
    const form = useForm({ channel: 'EMAIL', reset_contact: '', request_id: id });
    return (
        <PasswordRecoveryLayout title={t('Recover password')}>
            <p className="mb-6 text-sm leading-6 text-muted-foreground">
                {t(
                    'Use the verified email already linked to your account. This does not create a new account.',
                )}
            </p>
            <form
                className="space-y-5"
                onSubmit={(event) => {
                    event.preventDefault();
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
                <FormField id="reset_contact" label={t('Email address')}>
                    <Input
                        id="reset_contact"
                        type="email"
                        autoComplete="username"
                        inputMode="email"
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
