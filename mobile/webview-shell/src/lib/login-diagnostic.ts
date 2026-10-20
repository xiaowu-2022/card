// Only allowlisted categories cross into the error screen. Never expose native
// error text, response bodies, addresses, credentials or account identifiers.
const storageCodes: Record<string, string> = {
    'Protected credential save failed': 'IOS-WRITE',
    'Protected credential save unavailable': 'IOS-PROTECTION',
    'Protected credential backup exclusion failed': 'IOS-BACKUP',
    'Protected credential storage locked': 'IOS-LOCKED',
    'Protected credential storage unavailable': 'IOS-PROTECTION',
    'Invalid protected credential': 'IOS-RECORD',
};
export function loginDiagnostic(stage: string | undefined, error: unknown): string {
    const value = error as { message?: unknown; status?: unknown; diagnostic?: unknown } | null;
    const prefix = stage === 'storage' ? 'SAVE' : stage === 'verify' ? 'VERIFY' : 'CLAIM';
    if (stage === 'storage' && typeof value?.message === 'string' && storageCodes[value.message])
        return storageCodes[value.message];
    if (typeof value?.status === 'number' && Number.isInteger(value.status) && value.status >= 100 && value.status <= 599)
        return `${prefix}-HTTP-${value.status}`;
    if (value?.diagnostic === 'timeout') return `${prefix}-TIMEOUT`;
    if (value?.diagnostic === 'network') return `${prefix}-NETWORK`;
    if (value?.diagnostic === 'identity') return `${prefix}-IDENTITY`;
    return `${prefix}-INVALID`;
}
