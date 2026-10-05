import { SettingsTabs } from './SettingsTabs';
import { companySettings, companyEditor, companySettingsUrl } from './company-settings';
import { router } from '@inertiajs/react';
import { useEditor, usePage } from '@/components/admin/editor-context';
import { Link } from '@inertiajs/react';
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
    const editor = useEditor();
    if (!configurationBase && !editor) return <PageHeader {...props} />;
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
    const editor = useEditor();
    if (editor) return <>{children}</>;
    if (configurationBase && configurationCompany) {
        return (
            <PlatformLayout
                title={`${configurationCompany.name} · ${t('Company configuration')}`}
                actions={
                    <>
                        <RenameCompany
                            key={configurationCompany.id}
                            company={configurationCompany}
                        />
                        <CompanyLifecycleControls company={configurationCompany} />
                        <Button asChild variant="ghost" size="sm">
                            <Link href="/platform/company-configurations">
                                <ArrowLeft className="size-4" />
                                {t('Company configuration')}
                            </Link>
                        </Button>
                    </>
                }
            >
                {companyEditor(url) && (
                    <SettingsTabs
                        label={t('Company configuration')}
                        items={companySettings}
                        value={companyEditor(url)?.section ?? ''}
                        onChange={(section) =>
                            router.get(companySettingsUrl(configurationCompany.id, section))
                        }
                    />
                )}
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
