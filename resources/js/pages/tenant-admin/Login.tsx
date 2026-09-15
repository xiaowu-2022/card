import { useAdminTranslation, t, errorMessage } from '@/i18n/admin';
import { Head, useForm, usePage } from '@inertiajs/react';
import type { CSSProperties, FormEvent } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { AdminAuthLayout } from '@/layouts/AdminAuthLayout';
import type { SharedProps } from '@/types/global';

export default function Login() {
    useAdminTranslation();
    const { tenant } = usePage<SharedProps>().props;
    const form = useForm({ email: '', password: '' });
    const style = {
        '--tenant-primary': tenant?.branding.primaryColor ?? '#155EEF',
    } as CSSProperties;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/admin/login', { onFinish: () => form.reset('password') });
    };

    return (
        <div style={style}>
            <AdminAuthLayout>
                <Head title={t('Tenant admin sign in')} />
                <div className="mx-auto max-w-md px-4 py-16">
                    <Card>
                        <CardHeader>
                            {tenant?.branding.logoUrl && (
                                <img
                                    src={tenant.branding.logoUrl}
                                    alt=""
                                    className="mb-4 h-10 max-w-40 object-contain"
                                />
                            )}
                            <CardTitle>
                                {t('{{value1}} administration', {
                                    value1: tenant?.branding.brandName ?? t('Tenant'),
                                })}
                            </CardTitle>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {t('Admin identity is separate from end-user accounts.')}
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
                                    id="admin-email"
                                    label={t('Work email')}
                                    error={errorMessage(form.errors.email)}
                                >
                                    <Input
                                        id="admin-email"
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
                                    id="admin-password"
                                    label={t('Password')}
                                    error={errorMessage(form.errors.password)}
                                >
                                    <Input
                                        id="admin-password"
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
            </AdminAuthLayout>
        </div>
    );
}
