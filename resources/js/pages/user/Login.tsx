import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PublicLayout } from '@/layouts/PublicLayout';

export default function Login() {
    const form = useForm({ identifier: '', password: '', region: '' });
    return (
        <PublicLayout>
            <Head title="Sign in" />
            <div className="mx-auto max-w-md px-4 py-12 sm:py-20">
                <Card>
                    <CardHeader>
                        <CardTitle>Welcome back</CardTitle>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Sign in with your verified email or international phone number.
                        </p>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="space-y-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/login');
                            }}
                        >
                            <FormField
                                id="identifier"
                                label="Email or phone"
                                error={form.errors.identifier}
                            >
                                <Input
                                    id="identifier"
                                    autoComplete="username"
                                    value={form.data.identifier}
                                    onChange={(event) =>
                                        form.setData('identifier', event.target.value)
                                    }
                                    aria-invalid={Boolean(form.errors.identifier)}
                                    required
                                />
                            </FormField>
                            <FormField id="password" label="Password" error={form.errors.password}>
                                <Input
                                    id="password"
                                    type="password"
                                    autoComplete="current-password"
                                    value={form.data.password}
                                    onChange={(event) =>
                                        form.setData('password', event.target.value)
                                    }
                                    aria-invalid={Boolean(form.errors.password)}
                                    required
                                />
                            </FormField>
                            <Button className="w-full" type="submit" disabled={form.processing}>
                                Sign in
                            </Button>
                            <p className="text-center text-sm text-muted-foreground">
                                New here?{' '}
                                <Link className="font-semibold text-primary" href="/register">
                                    Create an account
                                </Link>
                            </p>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </PublicLayout>
    );
}
