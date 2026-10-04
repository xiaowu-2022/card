import { usePage } from '@/components/admin/editor-context';

export function useCompanyConfigurationUrl() {
    const { configurationBase } = usePage<{ configurationBase?: string }>().props;
    return (path: string) =>
        configurationBase ? path.replace(/^\/admin(?=\/|$)/, configurationBase) : path;
}
