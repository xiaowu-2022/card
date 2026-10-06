import { useEffect, useRef, useState } from 'react';
import { PartnerStockReport, type StockReport } from '@/components/user/PartnerStockReport';
import { t } from '@/i18n/admin';

type Identity = { id: string; name: string; accountId: string };
type Listing = {
    subject: Identity;
    items: (Identity & { teamCount: number })[];
    page: number;
    hasMore: boolean;
};
type View = {
    id: string;
    kind: 'children' | 'stock';
    page: number;
    flow?: 'inflow' | 'outflow' | null;
    flowPage?: number;
    scroll: number;
};
export function PartnerHierarchyPanel({
    partner,
    company,
    onClose,
}: {
    partner: string;
    company: string | null;
    onClose: () => void;
}) {
    const [stack, setStack] = useState<View[]>([
        { id: partner, kind: 'children', page: 1, scroll: 0 },
    ]);
    const [data, setData] = useState<
        Listing | { report: StockReport & { subject: Identity } } | null
    >(null);
    const [failed, setFailed] = useState(false);
    const [retry, setRetry] = useState(0);
    const container = useRef<HTMLDivElement>(null);
    const view = stack[stack.length - 1]!;
    const scroller = () => container.current?.closest('[data-detail-body]') as HTMLElement | null;
    useEffect(() => {
        const controller = new AbortController();
        setData(null);
        setFailed(false);
        const query = new URLSearchParams({ page: String(view.page) });
        if (company) query.set('company', company);
        if (view.flow) {
            query.set('flow', view.flow);
            query.set('flow_page', String(view.flowPage ?? 1));
        }
        fetch(`/platform/partners/${view.id}/${view.kind}?${query}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) throw Error();
                return response.json();
            })
            .then((result) => {
                if (!controller.signal.aborted) {
                    setData(result);
                    requestAnimationFrame(() => {
                        const el = scroller();
                        if (el) el.scrollTop = view.scroll;
                    });
                }
            })
            .catch(() => {
                if (!controller.signal.aborted) setFailed(true);
            });
        return () => controller.abort();
    }, [view, company, retry]);
    const push = (next: View) =>
        setStack((current) => [
            ...current.slice(0, -1),
            { ...current[current.length - 1]!, scroll: scroller()?.scrollTop ?? 0 },
            next,
        ]);
    const replace = (next: Partial<View>) =>
        setStack((current) => [...current.slice(0, -1), { ...view, ...next, scroll: 0 }]);
    const back = () =>
        stack.length === 1 ? onClose() : setStack((current) => current.slice(0, -1));
    const report = data && 'report' in data ? data.report : null;
    const list = data && 'items' in data ? data : null;
    return (
        <div ref={container} className="space-y-4">
            <button className="partner-admin-action" onClick={back}>
                {t('Back')}
            </button>
            <h2 className="font-semibold">
                {t(view.kind === 'children' ? 'Partner data' : 'Stock data')}
                {data ? ` · ${report?.subject.name ?? list?.subject.name}` : ''}
            </h2>
            {failed ? (
                <div role="alert">
                    <p>{t('Unable to load. Please retry.')}</p>
                    <button
                        className="partner-admin-action"
                        onClick={() => setRetry((value) => value + 1)}
                    >
                        {t('Retry')}
                    </button>
                </div>
            ) : !data ? (
                <p role="status">{t('Loading…')}</p>
            ) : null}
            {list && (
                <>
                    <table className="w-full text-sm">
                        <thead>
                            <tr>
                                <th className="p-2 text-left">{t('Name')}</th>
                                <th>{t('Team members')}</th>
                                <th>{t('Actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {list.items.map((row) => (
                                <tr key={row.id} className="border-b">
                                    <td className="p-2">{row.name}</td>
                                    <td className="text-center">{row.teamCount}</td>
                                    <td className="space-x-3 text-center">
                                        <button
                                            className="partner-admin-action"
                                            onClick={() =>
                                                push({
                                                    id: row.id,
                                                    kind: 'stock',
                                                    page: 1,
                                                    scroll: 0,
                                                })
                                            }
                                        >
                                            {t('Details')}
                                        </button>
                                        <button
                                            className="partner-admin-action"
                                            onClick={() =>
                                                push({
                                                    id: row.id,
                                                    kind: 'children',
                                                    page: 1,
                                                    scroll: 0,
                                                })
                                            }
                                        >
                                            {t('Subordinate partners')}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {!list.items.length && <p>{t('No subordinate partners')}</p>}
                    <div className="flex justify-between">
                        <button
                            disabled={view.page <= 1}
                            onClick={() => replace({ page: view.page - 1 })}
                        >
                            {t('Previous')}
                        </button>
                        <span>{view.page}</span>
                        <button
                            disabled={!list.hasMore}
                            onClick={() => replace({ page: view.page + 1 })}
                        >
                            {t('Next')}
                        </button>
                    </div>
                </>
            )}
            {report && (
                <PartnerStockReport
                    report={report}
                    compactDecimals
                    compactHeader
                    showUserIdentity
                    onPage={(page) => replace({ page })}
                    onPartners={() => push({ id: view.id, kind: 'children', page: 1, scroll: 0 })}
                    onFlow={(flow, page) => replace({ flow, flowPage: page ?? 1 })}
                />
            )}
        </div>
    );
}
