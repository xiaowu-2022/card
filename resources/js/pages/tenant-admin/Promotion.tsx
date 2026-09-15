import { useCompanyConfigurationUrl } from '@/hooks/useCompanyConfigurationUrl';
import { ConfigurationForm } from '@/components/admin/CompanyConfiguration';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CompanyConfigurationLayout as TenantAdminLayout } from '@/components/admin/CompanyConfiguration';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/ui/form-field';
import {
    Select,
    SelectTrigger,
    SelectValue,
    SelectContent,
    SelectItem,
} from '@/components/ui/select';
import { t, useAdminTranslation, errorMessage } from '@/i18n/admin';
import type { SharedProps } from '@/types/global';

type Level = { id: string; rank: number; name: string; reward: string; revision: number };
type Props = {
    promotion: {
        companyCode: string;
        levels: Level[];
        members: { accountId: string; levelId: string | null; code: string | null }[];
        accountId: string;
        page: number;
        hasMore: boolean;
    };
};
function LevelEditor({ level }: { level?: Level }) {
    const form = useForm({
        action: 'level',
        rank: level?.rank ?? 1,
        name: level?.name ?? '',
        reward: level?.reward.split('.')[0] ?? '',
        revision: level?.revision ?? 0,
    });
    return (
        <ConfigurationForm
            className="grid gap-3 rounded-xl border bg-surface p-4 md:grid-cols-[5rem_1fr_8rem_auto] md:items-end"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/admin/promotion', { preserveScroll: true });
            }}
        >
            <FormField
                id={`rank-${level?.id ?? 'new'}`}
                label={t('Promotion rank')}
                error={errorMessage(form.errors.rank)}
            >
                <Input
                    id={`rank-${level?.id ?? 'new'}`}
                    type="number"
                    min="1"
                    max="1000000"
                    value={form.data.rank}
                    readOnly={Boolean(level)}
                    onChange={(e) => form.setData('rank', Number(e.target.value))}
                    required
                />
            </FormField>
            <FormField
                id={`name-${level?.id ?? 'new'}`}
                label={t('Promotion level name')}
                error={errorMessage(form.errors.name)}
            >
                <Input
                    id={`name-${level?.id ?? 'new'}`}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    maxLength={80}
                    required
                />
            </FormField>
            <FormField
                id={`reward-${level?.id ?? 'new'}`}
                label={t('Fixed reward (U)')}
                error={errorMessage(form.errors.reward)}
            >
                <Input
                    id={`reward-${level?.id ?? 'new'}`}
                    inputMode="numeric"
                    pattern="[0-9]+"
                    value={form.data.reward}
                    onChange={(e) => form.setData('reward', e.target.value)}
                    required
                />
            </FormField>
            <Button disabled={form.processing}>{t('Save')}</Button>
        </ConfigurationForm>
    );
}
function MemberEditor({
    accountId,
    levelId,
    levels,
}: {
    accountId: string;
    levelId: string | null;
    levels: Level[];
}) {
    const form = useForm({ action: 'member', account_id: accountId, level_id: levelId });
    return (
        <ConfigurationForm
            className="flex min-w-[15rem] gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/admin/promotion', { preserveScroll: true });
            }}
        >
            <Select
                value={form.data.level_id ?? 'none'}
                onValueChange={(value) => form.setData('level_id', value === 'none' ? null : value)}
            >
                <SelectTrigger aria-label={t('Promotion level')}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="none">{t('Unranked')}</SelectItem>
                    {levels.map((level) => (
                        <SelectItem key={level.id} value={level.id}>
                            {level.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <Button type="submit" variant="secondary" disabled={form.processing}>
                {t('Save')}
            </Button>
        </ConfigurationForm>
    );
}
export default function PromotionSettings({ promotion: p }: Props) {
    useAdminTranslation();
    const configurationUrl = useCompanyConfigurationUrl();
    const { errors: pageErrors, configurationBase } = usePage<
        SharedProps & { configurationBase?: string }
    >().props;
    const errors = pageErrors as Record<string, string>;
    const search = useForm({ account_id: p.accountId });
    return (
        <TenantAdminLayout>
            <Head title={t('Promotion management')} />
            <div className="mx-auto max-w-5xl space-y-8">
                {!configurationBase && (
                    <h1 className="text-2xl font-semibold">{t('Promotion management')}</h1>
                )}
                {errors?.form && (
                    <p className="text-red-700" role="alert">
                        {errorMessage(errors.form)}
                    </p>
                )}
                <section className="rounded-xl border bg-surface p-5">
                    <h2 className="font-semibold">{t('Company invitation code')}</h2>
                    <p className="my-3 break-all font-mono">{p.companyCode}</p>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Use this code to register root members without an individual inviter. Then assign their promotion level here.',
                        )}
                    </p>
                </section>
                <section className="space-y-3">
                    <h2 className="font-semibold">{t('Promotion levels')}</h2>
                    {p.levels.map((level) => (
                        <LevelEditor key={`${level.id}:${level.revision}`} level={level} />
                    ))}
                    <h3 className="pt-3 text-sm font-medium">{t('Add promotion level')}</h3>
                    <LevelEditor />
                </section>
                <section className="space-y-4">
                    <h2 className="font-semibold">{t('Member promotion levels')}</h2>
                    <ConfigurationForm
                        allowRead
                        className="flex gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            search.get(configurationUrl('/admin/promotion'));
                        }}
                    >
                        <Input
                            aria-label={t('Account ID')}
                            placeholder={t('Account ID')}
                            value={search.data.account_id}
                            onChange={(e) => search.setData('account_id', e.target.value)}
                        />
                        <Button variant="secondary">{t('Search')}</Button>
                    </ConfigurationForm>
                    <div className="overflow-x-auto rounded-xl border bg-surface">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-muted">
                                <tr>
                                    <th className="p-4">{t('Account ID')}</th>
                                    <th className="p-4">{t('Invitation code')}</th>
                                    <th className="p-4">{t('Promotion level')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {p.members.map((member) => (
                                    <tr key={member.accountId} className="border-b last:border-0">
                                        <td className="p-4">{member.accountId}</td>
                                        <td className="p-4 font-mono text-xs">
                                            {member.code ?? t('Not generated')}
                                        </td>
                                        <td className="p-4">
                                            <MemberEditor
                                                key={`${member.accountId}:${member.levelId}`}
                                                {...member}
                                                levels={p.levels}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="flex justify-between">
                        <Button
                            variant="secondary"
                            disabled={p.page === 1}
                            onClick={() =>
                                router.get(configurationUrl('/admin/promotion'), {
                                    account_id: p.accountId,
                                    page: p.page - 1,
                                })
                            }
                        >
                            {t('Previous')}
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={!p.hasMore}
                            onClick={() =>
                                router.get(configurationUrl('/admin/promotion'), {
                                    account_id: p.accountId,
                                    page: p.page + 1,
                                })
                            }
                        >
                            {t('Next')}
                        </Button>
                    </div>
                </section>
            </div>
        </TenantAdminLayout>
    );
}
