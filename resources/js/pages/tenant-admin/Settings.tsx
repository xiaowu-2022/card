import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';

export default function Settings() {
    return (
        <TenantAdminLayout>
            <Head title="Tenant settings" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Demo settings"
                    title="Brand and support"
                    description="A safe white-label form pattern. Phase 0 does not persist these demo edits."
                />
                <Alert>
                    <AlertTitle>Shared design system</AlertTitle>
                    <AlertDescription>
                        Tenants may set basic brand tokens and content, but cannot inject CSS,
                        JavaScript, or layouts.
                    </AlertDescription>
                </Alert>
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Brand details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="grid gap-5 sm:grid-cols-2">
                            <FormField
                                id="brand-name"
                                label="Brand name"
                                description="Shown on shared platform surfaces."
                            >
                                <Input id="brand-name" defaultValue="Tenant A Cards" />
                            </FormField>
                            <FormField id="support-email" label="Support email">
                                <Input
                                    id="support-email"
                                    type="email"
                                    defaultValue="support@a.localhost"
                                />
                            </FormField>
                            <FormField
                                id="primary-color"
                                label="Primary color"
                                description="Used for actions and selected states only."
                            >
                                <Input id="primary-color" defaultValue="#155EEF" />
                            </FormField>
                            <FormField id="locale" label="Default locale">
                                <Select defaultValue="en">
                                    <SelectTrigger id="locale">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="en">English</SelectItem>
                                        <SelectItem value="zh-CN">简体中文</SelectItem>
                                    </SelectContent>
                                </Select>
                            </FormField>
                        </div>
                        <FormField
                            id="home-copy"
                            label="Public home copy"
                            description="Plain content only; no HTML or scripts."
                        >
                            <Textarea
                                id="home-copy"
                                defaultValue="Spend online with clarity and control."
                            />
                        </FormField>
                        <div className="flex items-center justify-between rounded-lg border p-4">
                            <div>
                                <p className="font-medium">Wallet top-up</p>
                                <p className="text-sm text-muted-foreground">
                                    Demo business setting; no money workflow is connected.
                                </p>
                            </div>
                            <Switch aria-label="Allow wallet top-up" disabled />
                        </div>
                        <div className="flex justify-end">
                            <Button disabled>Save changes</Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </TenantAdminLayout>
    );
}
