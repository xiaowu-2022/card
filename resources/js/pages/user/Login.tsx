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
            <Head title="User login" />
            <div className="mx-auto max-w-md px-4 py-16 sm:py-24">
                <Card>
                    <CardHeader>
                        <CardTitle>User login</CardTitle>
                        <p className="mt-1 text-sm text-muted-foreground">
                            End-user authentication placeholder.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <Alert>
                            <AlertTitle>Phase 0 preview</AlertTitle>
                            <AlertDescription>
                                Registration and authentication workflows are intentionally not
                                connected.
                            </AlertDescription>
                        </Alert>
                        <FormField id="email" label="Email">
                            <Input
                                id="email"
                                type="email"
                                placeholder="name@example.com"
                                disabled
                            />
                        </FormField>
                        <FormField id="password" label="Password">
                            <Input id="password" type="password" disabled />
                        </FormField>
                        <Button className="w-full" disabled>
                            Log in
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </PublicLayout>
    );
}
