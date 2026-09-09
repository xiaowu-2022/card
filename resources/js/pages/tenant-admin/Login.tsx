import { Head } from '@inertiajs/react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PublicLayout } from '@/layouts/PublicLayout';

export default function Login() {
    return (
        <PublicLayout>
            <Head title="Tenant admin login" />
            <div className="mx-auto max-w-md px-4 py-16">
                <Card>
                    <CardHeader>
                        <CardTitle>Tenant administrator</CardTitle>
                        <p className="mt-1 text-sm text-muted-foreground">
                            This identity is separate from end-user accounts.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <Alert>
                            <AlertTitle>Authentication skeleton</AlertTitle>
                            <AlertDescription>
                                2FA-capable fields and membership scope are prepared; sign-in is not
                                implemented in Phase 0.
                            </AlertDescription>
                        </Alert>
                        <FormField id="admin-email" label="Work email">
                            <Input id="admin-email" type="email" disabled />
                        </FormField>
                        <FormField id="admin-password" label="Password">
                            <Input id="admin-password" type="password" disabled />
                        </FormField>
                        <Button className="w-full" disabled>
                            Continue
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </PublicLayout>
    );
}
