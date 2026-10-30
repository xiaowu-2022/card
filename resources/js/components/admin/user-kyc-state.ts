export type KycTarget = { company: string; user: string };
export type KycActionEligibility = {
    reviewStatus: string;
    processingStatus?: string | null;
    isSuperseded?: boolean;
    currentApplicationId?: string;
    reviewable?: boolean;
    retryable?: boolean;
};

export function kycReviewable(application: KycActionEligibility): boolean {
    return (
        application.reviewStatus === 'PENDING' &&
        !application.isSuperseded &&
        (application.reviewable ?? true)
    );
}

export function kycRetryable(application: KycActionEligibility): boolean {
    return (
        !application.isSuperseded &&
        ['PENDING', 'REJECTED'].includes(application.reviewStatus) &&
        (application.retryable ??
            (application.processingStatus === 'FAILED' ||
                (!!application.processingStatus && application.reviewStatus === 'REJECTED')))
    );
}
export function initialKycTarget(): KycTarget | null {
    const query = new URL(location.href).searchParams;
    const company = query.get('kyc_company');
    const user = query.get('kyc_user');
    return company && user ? { company, user } : null;
}
export function kycLocation(target: KycTarget | null, application?: string, page = 1) {
    const url = new URL(location.href);
    for (const key of ['kyc_company', 'kyc_user', 'kyc_application', 'kyc_page'])
        url.searchParams.delete(key);
    if (target) {
        url.searchParams.set('kyc_company', target.company);
        url.searchParams.set('kyc_user', target.user);
        url.searchParams.set('kyc_page', String(page));
        if (application) url.searchParams.set('kyc_application', application);
    }
    history.replaceState(history.state, '', url);
}

// A technical failure is the displayed outcome until a review decision is made.
// Keep reviewStatus unchanged for authorized manual review and audit history.
export function kycDisplayStatus(application: {
    reviewStatus: string;
    processingStatus?: string | null;
}): string {
    return application.reviewStatus === 'PENDING' && application.processingStatus === 'FAILED'
        ? 'FAILED'
        : application.reviewStatus;
}
