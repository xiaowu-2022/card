import { useAdminTranslation, t, errorMessage } from '@/i18n/admin';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AdminAuthLayout } from '@/layouts/AdminAuthLayout';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';

export default function Login() {
    useAdminTranslation();
    const form = useForm({ email: '', password: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/platform/login', { onFinish: () => form.reset('password') });
    };

    return (
        <AdminAuthLayout>
            <div className="grid place-items-center px-4 py-12">
                <Head title={t('Platform sign in')} />
                <div className="w-full max-w-md">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Platform administrator')}</CardTitle>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {t('Sign in to the isolated Platform scope.')}
                            </p>
                        </CardHeader>
                        <CardContent>
                            <form className="space-y-5" onSubmit={submit}>
                                {form.errors.email && (
                                    <Alert className="border-red-200 bg-red-50 text-danger">
                                        <AlertDescription>
                                            {errorMessage(form.errors.email)}
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <FormField
                                    id="platform-email"
                                    label={t('Work email')}
                                    error={errorMessage(form.errors.email)}
                                >
                                    <Input
                                        id="platform-email"
                                        type="email"
                                        autoComplete="username"
                                        value={form.data.email}
                                        onChange={(event) =>
                                            form.setData('email', event.target.value)
                                        }
                                        autoFocus
                                    />
                                </FormField>
                                <FormField
                                    id="platform-password"
                                    label={t('Password')}
                                    error={errorMessage(form.errors.password)}
                                >
                                    <Input
                                        id="platform-password"
                                        type="password"
                                        autoComplete="current-password"
                                        value={form.data.password}
                                        onChange={(event) =>
                                            form.setData('password', event.target.value)
                                        }
                                    />
                                </FormField>
                                <Button className="w-full" disabled={form.processing}>
                                    {t('Sign in')}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AdminAuthLayout>
    );
}
