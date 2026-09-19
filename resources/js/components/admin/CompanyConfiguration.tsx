import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ComponentProps, FormHTMLAttributes, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/shared/PageHeader';
import { CompanyLifecycleControls } from '@/components/admin/CompanyLifecycleControls';
import { RenameCompany } from '@/components/admin/RenameCompany';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';
import { t } from '@/i18n/admin';

type ConfigurationProps = {
    configurationBase?: string;
    configurationReadOnly?: boolean;
    configurationCompany?: { id: string; name: string; slug: string; status?: string };
};

export function CompanyConfigurationHeader(props: ComponentProps<typeof PageHeader>) {
    const { configurationBase } = usePage<ConfigurationProps>().props;
    if (!configurationBase) return <PageHeader {...props} />;
    if (!props.description && !props.actions) return null;

    return (
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            {props.description && (
                <p className="max-w-2xl text-sm text-muted-foreground sm:text-base">
                    {props.description}
                </p>
            )}
            {props.actions && <div className="flex flex-wrap gap-2">{props.actions}</div>}
        </div>
    );
}
export function ConfigurationForm({
    children,
    onSubmit,
    allowRead = false,
    ...props
}: FormHTMLAttributes<HTMLFormElement> & { allowRead?: boolean }) {
    const configurationReadOnly =
        Boolean(usePage<ConfigurationProps>().props.configurationReadOnly) && !allowRead;
    return (
        <fieldset
            disabled={configurationReadOnly}
            className={
                configurationReadOnly
                    ? 'min-w-0 [&_button[type=submit]]:hidden [&_button:not([type])]:hidden'
                    : 'min-w-0'
            }
        >
            <form
                {...props}
                onSubmit={(event) => {
                    if (configurationReadOnly) event.preventDefault();
                    else onSubmit?.(event);
                }}
            >
                {children}
            </form>
        </fieldset>
    );
}
export function CompanyConfigurationLayout({ children }: { children: ReactNode }) {
    const { props, url } = usePage<ConfigurationProps>();
    const { configurationBase, configurationCompany, configurationReadOnly } = props;
    const currentPath = url.split(/[?#]/)[0];
    if (configurationBase && configurationCompany) {
        return (
            <PlatformLayout>
                <div className="mb-8 space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h1 className="min-w-0 break-words text-xl font-semibold">
                            {configurationCompany.name} · {t('Company configuration')}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <RenameCompany
                                key={configurationCompany.id}
                                company={configurationCompany}
                            />
                            <CompanyLifecycleControls company={configurationCompany} />
                            <Button asChild variant="ghost" size="sm">
                                <Link href="/platform/tenants">
                                    <ArrowLeft className="size-4" aria-hidden="true" />
                                    {t('Tenants')}
                                </Link>
                            </Button>
                        </div>
                    </div>
                    <nav
                        aria-label={t('Company configuration')}
                        className="flex gap-1 overflow-x-auto rounded-xl border bg-surface p-1.5"
                    >
                        {[
                            { path: 'card-products', label: 'Card products' },
                            { path: 'settings/branding', label: 'Branding' },
                            { path: 'settings/locales', label: 'Locales' },
                            { path: 'settings/business', label: 'Business rules' },
                            { path: 'settings/articles', label: 'About us articles' },
                            { path: 'settings/sms', label: 'Aliyun SMS' },
                            { path: 'settings/email', label: 'Proton email' },
                            { path: 'promotion', label: 'Promotion' },
                            { path: 'wealth', label: 'Wealth settings' },
                            { path: 'team', label: 'Team' },
                        ].map(({ path, label }) => {
                            const sectionPath = `${configurationBase}/${path}`;
                            const active =
                                currentPath === sectionPath ||
                                (path === 'promotion' &&
                                    currentPath === `${configurationBase}/paid-promotion`) ||
                                currentPath?.startsWith(`${sectionPath}/`) ||
                                (path === 'settings/branding' &&
                                    currentPath === `${configurationBase}/settings`);
                            return (
                                <Button
                                    key={path}
                                    asChild
                                    variant={active ? 'default' : 'ghost'}
                                    className="shrink-0 whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                                >
                                    <Link
                                        href={`${configurationBase}/${path}`}
                                        aria-current={active ? 'page' : undefined}
                                    >
                                        {t(label)}
                                    </Link>
                                </Button>
                            );
                        })}
                    </nav>
                </div>
                {children}
            </PlatformLayout>
        );
    }
    return (
        <TenantAdminLayout>
            <div
                className={
                    configurationReadOnly ? '[&_button[data-config-write]]:hidden' : undefined
                }
            >
                {children}
            </div>
        </TenantAdminLayout>
    );
}
