import { Link } from '@inertiajs/react';
import { t } from '@/i18n';
import { reportMoney, teamHref, type IncomeTotals } from '@/lib/promotion-report';

export type TeamSubject = { id: string | null; accountId: string; displayName: string | null };
export function TeamViewingContext({
    subject,
    breadcrumbs = [],
    totals,
    returnHref = teamHref,
}: {
    subject: TeamSubject;
    breadcrumbs?: TeamSubject[];
    totals?: IncomeTotals;
    returnHref?: (subject?: string | null) => string;
}) {
    return (
        <section className="team-viewing-context">
            <nav className="team-breadcrumbs" aria-label={t('Team path')}>
                <Link href={returnHref()}>{t('My team')}</Link>
                {breadcrumbs.map((node) => (
                    <span className="team-breadcrumb-item" key={node.id}>
                        <span aria-hidden="true">/</span>
                        {node.id === subject.id ? (
                            <span aria-current="page">{node.displayName || node.accountId}</span>
                        ) : (
                            <Link href={returnHref(node.id)}>
                                {node.displayName || node.accountId}
                            </Link>
                        )}
                    </span>
                ))}
            </nav>
            <div className="team-viewing-summary">
                <div>
                    <p>{t('Viewing {{account}}', { account: subject.accountId })}</p>
                    {subject.displayName && <strong>{subject.displayName}</strong>}
                </div>
                {totals && (
                    <div>
                        <p>{t('Member cumulative commission')}</p>
                        <strong>{reportMoney(totals.total)}</strong>
                    </div>
                )}
            </div>
        </section>
    );
}
