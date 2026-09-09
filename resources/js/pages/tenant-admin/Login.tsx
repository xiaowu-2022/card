import { Head, useForm, usePage } from '@inertiajs/react';
import type { CSSProperties, FormEvent } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { SharedProps } from '@/types/global';

export default function Login() {
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
            <PublicLayout>
                <Head title="Tenant admin sign in" />
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
                                {tenant?.branding.brandName ?? 'Tenant'} administration
                            </CardTitle>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Admin identity is separate from end-user accounts.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <form className="space-y-5" onSubmit={submit}>
                                {form.errors.email && (
                                    <Alert className="border-red-200 bg-red-50 text-danger">
                                        <AlertDescription>{form.errors.email}</AlertDescription>
                                    </Alert>
                                )}
                                <FormField
                                    id="admin-email"
                                    label="Work email"
                                    error={form.errors.email}
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
                                    label="Password"
                                    error={form.errors.password}
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
                                    Sign in
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </PublicLayout>
        </div>
    );
}
