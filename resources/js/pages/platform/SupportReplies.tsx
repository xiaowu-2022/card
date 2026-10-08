import { useEditorRouter } from '@/components/admin/useEditorRouter';
import { useEditor } from '@/components/admin/editor-context';
import { Head } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformSupportTabs } from '@/components/support/PlatformSupportTabs';
import { QuickReplyManager, type QuickReply } from '@/components/support/QuickReplyManager';
import type { AccountPage } from '@/components/shared/PlatformAccountTable';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t, useAdminTranslation } from '@/i18n/admin';
export default function SupportReplies({
    replies,
    companies,
    filters,
}: {
    replies: AccountPage<QuickReply>;
    companies: { id: string; name: string }[];
    filters: { company?: string; search?: string };
}) {
    const router = useEditorRouter();
    const editor = useEditor();
    useAdminTranslation();
    return (
        <PlatformLayout title={t('Customer support')}>
            <Head title={t('Quick replies')} />
            <div className="space-y-4">
                <PlatformSupportTabs replies />
                <form
                    className="flex gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const f = new FormData(e.currentTarget);
                        router.get('/platform/support/replies', {
                            company: editor ? filters.company : (f.get('company') as string),
                            search: f.get('search') as string,
                        });
                    }}
                >
                    {!editor && (
                        <select
                            name="company"
                            aria-label={t('Company')}
                            className="h-9 rounded-lg border px-3"
                            defaultValue={filters.company ?? ''}
                        >
                            <option value="">{t('All companies')}</option>
                            {companies.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    )}
                    <Input
                        name="search"
                        maxLength={120}
                        defaultValue={filters.search ?? ''}
                        aria-label={t('Search quick replies')}
                    />
                    <Button>{t('Search')}</Button>
                </form>
                <QuickReplyManager
                    replies={replies}
                    url="/platform/support/replies"
                    companies={editor ? undefined : companies}
                    company={filters.company}
                />
            </div>
        </PlatformLayout>
    );
}
