import { t, useClientTranslation, errorMessage, clientI18n } from '@/i18n';
import { Head, Link, useForm } from '@inertiajs/react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PublicLayout } from '@/layouts/PublicLayout';

type Props = { challenge: { id: string; channel: 'EMAIL' | 'PHONE'; status: string } };

export default function VerifyRegistration({ challenge }: Props) {
    useClientTranslation();
    const verify = useForm({ code: '' });
    const complete = useForm({
        display_name: '',
        locale: '',
        password: '',
        password_confirmation: '',
    });
    const verified = challenge.status === 'VERIFIED';
    const unavailable = !['PENDING', 'VERIFIED'].includes(challenge.status);
    const verifyFormError = (verify.errors as Record<string, string>).form;
    const completeFormError = (complete.errors as Record<string, string>).form;
    return (
        <PublicLayout compact>
            <Head title={verified ? t('Finish registration') : t('Verify contact')} />
            <div className="mx-auto max-w-md px-4 pt-6 pb-12 sm:pt-12 sm:pb-20">
                <Card className="rounded-[var(--user-radius-lg)] shadow-[0_12px_40px_rgba(23,32,28,0.06)]">
                    <CardHeader>
                        <CardTitle className="text-2xl">
                            {verified ? t('Create your password') : t('Enter verification code')}
                        </CardTitle>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {verified
                                ? t('Your contact is verified. Finish creating your account.')
                                : t('Enter the six-digit code sent by {{value1}}.', {
                                      value1: challenge.channel === 'EMAIL' ? t('Email') : t('SMS'),
                                  })}
                        </p>
                    </CardHeader>
                    <CardContent>
                        {unavailable ? (
                            <Alert>
                                <AlertTitle>{t('Challenge unavailable')}</AlertTitle>
                                <AlertDescription>
                                    {t(
                                        'Request a new code or sign in if you already have an account.',
                                    )}
                                </AlertDescription>
                            </Alert>
                        ) : verified ? (
                            <form
                                className="space-y-5"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    complete.transform((data) => ({
                                        ...data,
                                        locale: clientI18n.language,
                                    }));
                                    complete.post(`/register/challenges/${challenge.id}/complete`);
                                }}
                            >
                                {completeFormError && (
                                    <Alert className="border-red-200 bg-red-50 text-red-800">
                                        <AlertTitle>{t('Unable to create account')}</AlertTitle>
                                        <AlertDescription>
                                            {errorMessage(completeFormError)}
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <FormField
                                    id="display_name"
                                    label={t('Display name')}
                                    description={t('Optional. This is not a legal identity field.')}
                                    error={errorMessage(complete.errors.display_name)}
                                >
                                    <Input
                                        id="display_name"
                                        autoComplete="name"
                                        value={complete.data.display_name}
                                        onChange={(event) =>
                                            complete.setData('display_name', event.target.value)
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id="password"
                                    label={t('Password')}
                                    description={t(
                                        'At least 12 characters with upper/lowercase letters and a number.',
                                    )}
                                    error={errorMessage(complete.errors.password)}
                                >
                                    <Input
                                        id="password"
                                        type="password"
                                        autoComplete="new-password"
                                        value={complete.data.password}
                                        onChange={(event) =>
                                            complete.setData('password', event.target.value)
                                        }
                                        required
                                    />
                                </FormField>
                                <FormField id="password_confirmation" label={t('Confirm password')}>
                                    <Input
                                        id="password_confirmation"
                                        type="password"
                                        autoComplete="new-password"
                                        value={complete.data.password_confirmation}
                                        onChange={(event) =>
                                            complete.setData(
                                                'password_confirmation',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </FormField>
                                <Button
                                    className="w-full"
                                    type="submit"
                                    disabled={complete.processing}
                                >
                                    {t('Create account')}
                                </Button>
                            </form>
                        ) : (
                            <form
                                className="space-y-5"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    verify.post(`/register/challenges/${challenge.id}/verify`);
                                }}
                            >
                                {verifyFormError && (
                                    <Alert className="border-red-200 bg-red-50 text-red-800">
                                        <AlertTitle>{t('Verification failed')}</AlertTitle>
                                        <AlertDescription>
                                            {errorMessage(verifyFormError)}
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <FormField
                                    id="code"
                                    label={t('Verification code')}
                                    error={errorMessage(verify.errors.code)}
                                >
                                    <Input
                                        id="code"
                                        className="text-center text-xl tracking-[0.45em]"
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        pattern="[0-9]{6}"
                                        maxLength={6}
                                        value={verify.data.code}
                                        onChange={(event) =>
                                            verify.setData(
                                                'code',
                                                event.target.value.replace(/\D/g, ''),
                                            )
                                        }
                                        aria-invalid={Boolean(verify.errors.code)}
                                        required
                                    />
                                </FormField>
                                <Button
                                    className="w-full"
                                    type="submit"
                                    disabled={verify.processing}
                                >
                                    {t('Verify code')}
                                </Button>
                            </form>
                        )}
                        <Link
                            className="mt-5 block text-center text-sm font-semibold text-primary"
                            href="/register"
                        >
                            {t('Request another code')}
                        </Link>
                    </CardContent>
                </Card>
            </div>
        </PublicLayout>
    );
}
