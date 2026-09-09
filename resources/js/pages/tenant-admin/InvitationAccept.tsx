import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PublicLayout } from '@/layouts/PublicLayout';

interface Props {
    token: string;
    invitation: { email: string; role: string; expiresAt: string };
    existingAdmin: boolean;
}

export default function InvitationAccept({ token, invitation, existingAdmin }: Props) {
    const form = useForm({ name: '', password: '', password_confirmation: '' });
    const formError = (form.errors as Record<string, string>).form;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/admin/invitations/${token}`, {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <PublicLayout>
            <Head title="Accept admin invitation" />
            <div className="mx-auto max-w-lg px-4 py-14">
                <Card>
                    <CardHeader>
                        <CardTitle>Accept administrator invitation</CardTitle>
                        <p className="text-sm text-muted-foreground">
                            {invitation.email} · {invitation.role}
                        </p>
                    </CardHeader>
                    <CardContent>
                        <form className="space-y-5" onSubmit={submit}>
                            <Alert>
                                <AlertTitle>
                                    {existingAdmin
                                        ? 'Existing admin identity'
                                        : 'Create your admin identity'}
                                </AlertTitle>
                                <AlertDescription>
                                    {existingAdmin
                                        ? 'Confirm your current password. Your identity and permissions from other Tenants are not copied.'
                                        : 'Choose a strong password with at least 12 characters, mixed case, and numbers.'}
                                </AlertDescription>
                            </Alert>
                            {formError && (
                                <Alert className="border-red-200 bg-red-50 text-danger">
                                    <AlertDescription>{formError}</AlertDescription>
                                </Alert>
                            )}
                            <FormField id="invite-name" label="Your name" error={form.errors.name}>
                                <Input
                                    id="invite-name"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    autoComplete="name"
                                />
                            </FormField>
                            <FormField
                                id="invite-password"
                                label={existingAdmin ? 'Current password' : 'Create password'}
                                error={form.errors.password}
                            >
                                <Input
                                    id="invite-password"
                                    type="password"
                                    value={form.data.password}
                                    onChange={(event) =>
                                        form.setData('password', event.target.value)
                                    }
                                    autoComplete={
                                        existingAdmin ? 'current-password' : 'new-password'
                                    }
                                />
                            </FormField>
                            <FormField
                                id="invite-password-confirmation"
                                label="Confirm password"
                                error={form.errors.password_confirmation}
                            >
                                <Input
                                    id="invite-password-confirmation"
                                    type="password"
                                    value={form.data.password_confirmation}
                                    onChange={(event) =>
                                        form.setData('password_confirmation', event.target.value)
                                    }
                                    autoComplete="new-password"
                                />
                            </FormField>
                            <Button className="w-full" disabled={form.processing}>
                                Accept invitation
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                Expires {new Date(invitation.expiresAt).toLocaleString()}
                            </p>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </PublicLayout>
    );
}
