import { dialCountries } from '@/lib/phone-input';
import { t, useClientTranslation, errorMessage } from '@/i18n';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { parsePhoneNumberFromString, type CountryCode } from 'libphonenumber-js/max';
import { SearchSelect } from '@/components/ui/search-select';
import { countryOptions } from '@/hooks/useCardGeography';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PublicLayout } from '@/layouts/PublicLayout';

type Props = {
    registration: {
        emailAvailable: boolean;
        phoneAvailable: boolean;
        invitationCode: string;
        invitationLocked: boolean;
        invitationInvalid?: boolean;
    };
};

export default function Register({ registration }: Props) {
    const { i18n } = useClientTranslation();

    const available = registration.emailAvailable || registration.phoneAvailable;
    const initialChannel = registration.emailAvailable ? 'EMAIL' : 'PHONE';
    const [channel, setChannel] = useState<'EMAIL' | 'PHONE'>(initialChannel);
    const form = useForm({
        channel,
        destination: '',
        region: 'CN',
        invitation_code: registration.invitationCode ?? '',
    });
    const formError = (form.errors as Record<string, string>).form;
    const selectChannel = (value: string) => {
        const selected = value as 'EMAIL' | 'PHONE';
        setChannel(selected);
        form.clearErrors();
        form.setData({ ...form.data, channel: selected, destination: '', region: 'CN' });
    };
    return (
        <PublicLayout compact authPromotion>
            <Head title={t('Create account')} />
            <div className="mx-auto max-w-md px-4 pt-6 pb-12 sm:pt-12 sm:pb-20">
                <Card className="rounded-[var(--user-radius-lg)] shadow-[0_12px_40px_rgba(23,32,28,0.06)]">
                    <CardHeader>
                        <CardTitle className="text-2xl">{t('Create your account')}</CardTitle>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('First, verify one contact method.')}
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
                                <Tabs
                                    value={channel}
                                    onValueChange={selectChannel}
                                    className="mb-6"
                                >
                                    <TabsList
                                        className={
                                            registration.emailAvailable &&
                                            registration.phoneAvailable
                                                ? 'grid w-full grid-cols-2'
                                                : 'grid w-full grid-cols-1'
                                        }
                                    >
                                        {registration.emailAvailable && (
                                            <TabsTrigger value="EMAIL">{t('Email')}</TabsTrigger>
                                        )}
                                        {registration.phoneAvailable && (
                                            <TabsTrigger value="PHONE">{t('Phone')}</TabsTrigger>
                                        )}
                                    </TabsList>
                                </Tabs>
                                <form
                                    className="space-y-5"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        form.clearErrors();
                                        if (channel === 'PHONE') {
                                            const phone = parsePhoneNumberFromString(
                                                form.data.destination,
                                                form.data.region as CountryCode,
                                            );
                                            if (!phone?.isValid()) {
                                                form.setError(
                                                    'destination',
                                                    'Enter a valid phone number.',
                                                );
                                                return;
                                            }
                                        }
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
                                    {channel === 'EMAIL' ? (
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
                                    ) : (
                                        <>
                                            <FormField
                                                id="region"
                                                label={t('Country code')}
                                                error={errorMessage(form.errors.region)}
                                            >
                                                <SearchSelect
                                                    id="region"
                                                    label={t('Country code')}
                                                    options={countryOptions(
                                                        dialCountries,
                                                        i18n.language,
                                                        true,
                                                    )}
                                                    placeholder={t('Please select')}
                                                    searchLabel={t('Search options')}
                                                    emptyLabel={t('No matching options')}
                                                    disabled={form.processing}
                                                    value={form.data.region}
                                                    onValueChange={(value) =>
                                                        form.setData('region', value)
                                                    }
                                                />
                                            </FormField>
                                            <FormField
                                                id="destination"
                                                label={t('Phone number')}
                                                description={t(
                                                    'Include the international prefix, or select a country code above.',
                                                )}
                                                error={errorMessage(form.errors.destination)}
                                            >
                                                <Input
                                                    id="destination"
                                                    type="tel"
                                                    autoComplete="tel"
                                                    inputMode="tel"
                                                    maxLength={30}
                                                    aria-invalid={Boolean(form.errors.destination)}
                                                    value={form.data.destination}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'destination',
                                                            event.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                            </FormField>
                                        </>
                                    )}
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
                                        disabled={
                                            form.processing ||
                                            (channel === 'PHONE' && dialCountries.length === 0)
                                        }
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
