import { Head, useForm } from '@inertiajs/react';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { UserLayout } from '@/layouts/UserLayout';

export default function Security() {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    const formError = (form.errors as Record<string, string>).form;
    return (
        <UserLayout>
            <Head title="Account security" />
            <div className="max-w-2xl space-y-6">
                <UserPageHeader
                    title="Change password"
                    description="Confirm your current password before choosing a new one."
                    backHref="/account"
                />
                <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
                    <h2 className="mb-5 font-semibold">Password</h2>
                    <form
                        className="space-y-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/account/security/password', {
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        {formError && (
                            <Alert className="border-red-200 bg-red-50 text-red-800">
                                <AlertTitle>Password not changed</AlertTitle>
                                <AlertDescription>{formError}</AlertDescription>
                            </Alert>
                        )}
                        <FormField
                            id="current_password"
                            label="Current password"
                            error={form.errors.current_password}
                        >
                            <Input
                                id="current_password"
                                type="password"
                                autoComplete="current-password"
                                value={form.data.current_password}
                                onChange={(event) =>
                                    form.setData('current_password', event.target.value)
                                }
                                required
                            />
                        </FormField>
                        <FormField
                            id="password"
                            label="New password"
                            description="At least 12 characters with upper/lowercase letters and a number."
                            error={form.errors.password}
                        >
                            <Input
                                id="password"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password}
                                onChange={(event) => form.setData('password', event.target.value)}
                                required
                            />
                        </FormField>
                        <FormField id="password_confirmation" label="Confirm new password">
                            <Input
                                id="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password_confirmation}
                                onChange={(event) =>
                                    form.setData('password_confirmation', event.target.value)
                                }
                                required
                            />
                        </FormField>
                        <Button
                            className="w-full sm:w-auto"
                            type="submit"
                            disabled={form.processing}
                        >
                            Change password
                        </Button>
                    </form>
                </section>
            </div>
        </UserLayout>
    );
}
