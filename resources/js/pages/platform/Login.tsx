import { AppMark } from '@/components/shared/AppMark';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';

export default function Login() {
    return (
        <div className="grid min-h-screen place-items-center px-4 py-12">
            <div className="w-full max-w-md">
                <div className="mb-8 flex justify-center">
                    <AppMark name="Aperture Platform" />
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Platform administrator</CardTitle>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Independent platform scope and authentication surface.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <Alert>
                            <AlertTitle>Phase 0 only</AlertTitle>
                            <AlertDescription>
                                Production sign-in and enforced 2FA will be completed before launch.
                            </AlertDescription>
                        </Alert>
                        <FormField id="platform-email" label="Work email">
                            <Input id="platform-email" disabled />
                        </FormField>
                        <FormField id="platform-password" label="Password">
                            <Input id="platform-password" type="password" disabled />
                        </FormField>
                        <Button className="w-full" disabled>
                            Continue securely
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
