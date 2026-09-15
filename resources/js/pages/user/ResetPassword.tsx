import { Link, useForm } from '@inertiajs/react';
import { t, errorMessage, useClientTranslation, dateTime } from '@/i18n';
import { PasswordRecoveryLayout } from '@/layouts/PasswordRecoveryLayout';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

export default function ResetPassword({
    reset,
}: {
    reset: { id: string; channel: string; expiresAt: string };
}) {
    useClientTranslation();
    const form = useForm({ code: '', password: '', password_confirmation: '', confirmed: false });
    return (
        <PasswordRecoveryLayout title={t('Reset password')}>
            <p className="mb-3 text-sm text-muted-foreground">
                {t('Code expires at {{time}}', { time: dateTime(reset.expiresAt) })}
            </p>
            <p className="mb-6 text-sm leading-6 text-muted-foreground">
                {t(
                    'If this contact belongs to an eligible account, use the code to reset its password. Receiving a code does not confirm an account exists. If delivery is delayed, wait until the code expires before requesting another.',
                )}
            </p>
            <form
                className="space-y-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/forgot-password/${reset.id}`, { onSuccess: () => form.reset() });
                }}
            >
                {Object.keys(form.errors).length > 0 && (
                    <div role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-800">
                        {Object.values(form.errors).map((error, index) => (
                            <p key={index}>{errorMessage(error)}</p>
                        ))}
                    </div>
                )}
                <FormField id="reset_code" label={t('Verification code')}>
                    <Input
                        id="reset_code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        required
                        pattern="[0-9]{6}"
                        minLength={6}
                        maxLength={6}
                        value={form.data.code}
                        onChange={(event) => form.setData('code', event.target.value)}
                    />
                </FormField>
                <FormField
                    id="reset_password"
                    label={t('New password')}
                    description={t(
                        'At least 12 characters with upper/lowercase letters and a number.',
                    )}
                >
                    <Input
                        id="reset_password"
                        type="password"
                        autoComplete="new-password"
                        required
                        minLength={12}
                        maxLength={1024}
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />
                </FormField>
                <FormField id="reset_password_confirmation" label={t('Confirm new password')}>
                    <Input
                        id="reset_password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        required
                        maxLength={1024}
                        value={form.data.password_confirmation}
                        onChange={(event) =>
                            form.setData('password_confirmation', event.target.value)
                        }
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
                            'I confirm resetting my password. All previous sign-ins will expire and I must sign in again.',
                        )}
                    </span>
                </label>
                <Button
                    type="submit"
                    className="w-full"
                    disabled={form.processing || !form.data.confirmed}
                >
                    {t('Reset password')}
                </Button>
            </form>
            <Link
                href="/forgot-password"
                className="mt-6 block text-center text-sm underline underline-offset-4"
            >
                {t('Request a new code')}
            </Link>
        </PasswordRecoveryLayout>
    );
}
