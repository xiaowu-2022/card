import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AppMark } from '@/components/shared/AppMark';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';

export default function Login() {
    const form = useForm({ email: '', password: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/platform/login', { onFinish: () => form.reset('password') });
    };

    return (
        <div className="grid min-h-screen place-items-center px-4 py-12">
            <Head title="Platform sign in" />
            <div className="w-full max-w-md">
                <div className="mb-8 flex justify-center">
                    <AppMark name="Aperture Platform" />
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Platform administrator</CardTitle>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Sign in to the isolated Platform scope.
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
                                id="platform-email"
                                label="Work email"
                                error={form.errors.email}
                            >
                                <Input
                                    id="platform-email"
                                    type="email"
                                    autoComplete="username"
                                    value={form.data.email}
                                    onChange={(event) => form.setData('email', event.target.value)}
                                    autoFocus
                                />
                            </FormField>
                            <FormField
                                id="platform-password"
                                label="Password"
                                error={form.errors.password}
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
                                Sign in
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
