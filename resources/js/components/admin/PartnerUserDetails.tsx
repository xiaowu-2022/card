import { router } from '@inertiajs/react';
import { useEffect, useState, type ComponentProps } from 'react';
import UserManagement, { type PlatformUser } from './UserManagement';
import type { UserInfo } from './UserInfoCell';
import { readEditorResponse } from './editor-response';
import { OperationFeedback } from './OperationFeedback';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n/admin';

type Detail = Omit<ComponentProps<typeof UserManagement>, 'users' | 'companies' | 'filters'> & {
    user: PlatformUser;
};
export function PartnerUserDetails({
    user,
    ...workspace
}: {
    user: UserInfo;
} & Pick<
    ComponentProps<typeof UserManagement>,
    'extraDetailTabs' | 'extraDetailActions' | 'selectedDetailTab' | 'onDetailTabChange'
>) {
    const [data, setData] = useState<Detail | null>(null);
    const [error, setError] = useState(false);
    const [revision, setRevision] = useState(0);
    useEffect(() => router.on('finish', () => setRevision((value) => value + 1)), []);
    useEffect(() => {
        const controller = new AbortController();
        setData(null);
        setError(false);
        void fetch(
            `/platform/tenants/${encodeURIComponent(user.companyId)}/users/${encodeURIComponent(user.id)}/details`,
            {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            },
        )
            .then(async (response) => {
                const result = (await readEditorResponse(response)) as unknown as Detail;
                if (
                    !response.ok ||
                    !result.user ||
                    result.user.id !== user.id ||
                    result.user.companyId !== user.companyId
                )
                    throw new Error();
                if (!controller.signal.aborted) setData(result);
            })
            .catch(() => {
                if (!controller.signal.aborted) setError(true);
            });
        return () => controller.abort();
    }, [user.companyId, user.id, revision]);
    if (error)
        return (
            <div className="space-y-4 p-5">
                <OperationFeedback role="alert">
                    {t('Unable to load. Please retry.')}
                </OperationFeedback>
                <Button variant="secondary" onClick={() => setRevision((value) => value + 1)}>
                    {t('Retry')}
                </Button>
            </div>
        );
    if (!data)
        return (
            <p className="p-5" role="status">
                {t('Loading…')}
            </p>
        );
    return (
        <UserManagement
            {...data}
            {...workspace}
            detail
            canManagePartners={false}
            canManageSupport={data.canManageSupport && data.user.supportAgent}
            companies={[]}
            filters={{}}
            users={{
                data: [data.user],
                total: 1,
                current_page: 1,
                last_page: 1,
                prev_page_url: null,
                next_page_url: null,
            }}
        />
    );
}
