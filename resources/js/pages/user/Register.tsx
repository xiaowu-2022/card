import { t, useClientTranslation, errorMessage } from '@/i18n';
import { Head, Link, useForm } from '@inertiajs/react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PublicLayout } from '@/layouts/PublicLayout';

type Props = {
    registration: {
        emailAvailable: boolean;
        invitationCode: string;
        invitationLocked: boolean;
        invitationInvalid?: boolean;
    };
};

export default function Register({ registration }: Props) {
    useClientTranslation();

    const available = registration.emailAvailable;
    const form = useForm({
        channel: 'EMAIL',
        destination: '',
        invitation_code: registration.invitationCode ?? '',
    });
    const formError = (form.errors as Record<string, string>).form;
    return (
        <PublicLayout compact authPromotion>
            <Head title={t('Create account')} />
            <div className="mx-auto max-w-md px-4 pt-6 pb-12 sm:pt-12 sm:pb-20">
                <Card className="rounded-[var(--user-radius-lg)] shadow-[0_12px_40px_rgba(23,32,28,0.06)]">
                    <CardHeader>
                        <CardTitle className="text-2xl">{t('Create your account')}</CardTitle>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('First, verify your email address.')}
                        </p>
                    </CardHeader>
                    <CardContent>
                        {registration.invitationInvalid && (
                            <p role="alert" className="mb-4 text-sm text-danger">
                                {t('Enter a valid invitation code.')}
                            </p>
                        )}
                        {!available && (
                            <p role="status" className="text-sm text-muted-foreground">
                                {t('Registration is temporarily unavailable.')}
                            </p>
                        )}
                        {available && (
                            <>
                                <form
                                    className="space-y-5"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        form.clearErrors();
                                        form.post('/register/challenges');
                                    }}
                                >
                                    {formError && (
                                        <Alert className="border-red-200 bg-red-50 text-red-800">
                                            <AlertTitle>{t('Unable to send code')}</AlertTitle>
                                            <AlertDescription>
                                                {errorMessage(formError)}
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                    <FormField
                                        id="destination"
                                        label={t('Email address')}
                                        error={errorMessage(form.errors.destination)}
                                    >
                                        <Input
                                            id="destination"
                                            type="email"
                                            autoComplete="email"
                                            value={form.data.destination}
                                            onChange={(event) =>
                                                form.setData('destination', event.target.value)
                                            }
                                            required
                                        />
                                    </FormField>
                                    <FormField
                                        id="invitation-code"
                                        label={t('Invitation code')}
                                        error={errorMessage(form.errors.invitation_code)}
                                    >
                                        <Input
                                            id="invitation-code"
                                            value={form.data.invitation_code}
                                            readOnly={registration.invitationLocked}
                                            required
                                            maxLength={6}
                                            inputMode="numeric"
                                            pattern="[0-9]{6}"
                                            onChange={(event) =>
                                                form.setData(
                                                    'invitation_code',
                                                    event.target.value.replace(/\D/g, ''),
                                                )
                                            }
                                        />
                                    </FormField>
                                    <Button
                                        className="w-full"
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        {t('Send verification code')}
                                    </Button>
                                    <p className="text-center text-sm text-muted-foreground">
                                        {t('Already registered?')}{' '}
                                        <Link className="font-semibold text-primary" href="/login">
                                            {t('Sign in')}
                                        </Link>
                                    </p>
                                </form>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </PublicLayout>
    );
}
