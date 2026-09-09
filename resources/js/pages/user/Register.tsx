import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PublicLayout } from '@/layouts/PublicLayout';

export default function Register() {
    const [channel, setChannel] = useState<'EMAIL' | 'PHONE'>('EMAIL');
    const form = useForm({ channel, destination: '', region: '' });
    const selectChannel = (value: string) => {
        const selected = value as 'EMAIL' | 'PHONE';
        setChannel(selected);
        form.setData({ channel: selected, destination: '', region: '' });
    };
    return (
        <PublicLayout>
            <Head title="Create account" />
            <div className="mx-auto max-w-md px-4 py-12 sm:py-20">
                <Card>
                    <CardHeader>
                        <CardTitle>Create your account</CardTitle>
                        <p className="mt-1 text-sm text-muted-foreground">
                            First, verify one contact method.
                        </p>
                    </CardHeader>
                    <CardContent>
                        <Tabs value={channel} onValueChange={selectChannel} className="mb-6">
                            <TabsList className="grid w-full grid-cols-2">
                                <TabsTrigger value="EMAIL">Email</TabsTrigger>
                                <TabsTrigger value="PHONE">Phone</TabsTrigger>
                            </TabsList>
                        </Tabs>
                        <form
                            className="space-y-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/register/challenges');
                            }}
                        >
                            {channel === 'EMAIL' ? (
                                <FormField
                                    id="destination"
                                    label="Email address"
                                    error={form.errors.destination}
                                >
                                    <Input
                                        id="destination"
                                        type="email"
                                        autoComplete="email"
                                        value={form.data.destination}
                                        onChange={(event) =>
                                            form.setData('destination', event.target.value)
                                        }
                                        required
                                    />
                                </FormField>
                            ) : (
                                <>
                                    <FormField
                                        id="region"
                                        label="Country code"
                                        description="Two-letter country code, for example MY or US."
                                        error={form.errors.region}
                                    >
                                        <Input
                                            id="region"
                                            autoCapitalize="characters"
                                            placeholder="MY"
                                            maxLength={2}
                                            value={form.data.region}
                                            onChange={(event) =>
                                                form.setData(
                                                    'region',
                                                    event.target.value.toUpperCase(),
                                                )
                                            }
                                        />
                                    </FormField>
                                    <FormField
                                        id="destination"
                                        label="Phone number"
                                        description="Include the international prefix, or select a country code above."
                                        error={form.errors.destination}
                                    >
                                        <Input
                                            id="destination"
                                            type="tel"
                                            autoComplete="tel"
                                            inputMode="tel"
                                            placeholder="+60 12-345 6789"
                                            value={form.data.destination}
                                            onChange={(event) =>
                                                form.setData('destination', event.target.value)
                                            }
                                            required
                                        />
                                    </FormField>
                                </>
                            )}
                            <Button className="w-full" type="submit" disabled={form.processing}>
                                Send verification code
                            </Button>
                            <p className="text-center text-sm text-muted-foreground">
                                Already registered?{' '}
                                <Link className="font-semibold text-primary" href="/login">
                                    Sign in
                                </Link>
                            </p>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </PublicLayout>
    );
}
