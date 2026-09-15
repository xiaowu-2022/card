import { Link } from '@inertiajs/react';
import { ChevronRight, ScanFace } from 'lucide-react';
import { t, useClientTranslation } from '@/i18n';

export function AccountVerificationLink({
    status,
    fromSecurity = false,
}: {
    status: string;
    fromSecurity?: boolean;
}) {
    useClientTranslation();
    const label =
        status === 'APPROVED'
            ? t('Verified')
            : status === 'PENDING'
              ? t('Under review')
              : status === 'RESUBMISSION_REQUIRED'
                ? t('Action required')
                : status === 'REJECTED'
                  ? t('Verification could not be approved')
                  : t('Not verified');
    const actionable = status === 'NOT_SUBMITTED' || status === 'RESUBMISSION_REQUIRED';
    return (
        <Link
            href={fromSecurity ? '/kyc?from=account-security' : '/kyc'}
            className="user-verification-link"
        >
            <ScanFace className="size-5 shrink-0 text-[var(--user-primary)]" aria-hidden="true" />
            <span className="min-w-0 flex-1">
                <span className="block text-sm">{t('Identity verification')}</span>
                <span
                    className={`block text-xs ${status === 'APPROVED' ? 'text-emerald-700' : 'text-muted-foreground'}`}
                >
                    {label}
                </span>
            </span>
            <span className="max-w-[40%] text-right text-xs text-[var(--user-primary)]">
                {actionable ? t('Go to verification') : t('View details')}
            </span>
            <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        </Link>
    );
}
