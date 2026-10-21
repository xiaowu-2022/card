const detailedStorageCodes = new Set([
    'IOS-MANAGER-EMPTY', 'IOS-EXISTS-INVALID', 'IOS-ATTRIBUTES-EMPTY',
    'IOS-PROTECTION-MISSING', 'IOS-PROTECTION-MISMATCH', 'IOS-READ-EMPTY',
    'IOS-READ-TYPE', 'IOS-JSON-EMPTY', 'IOS-JSON-PARSE',
    'IOS-RECORD-SHAPE', 'IOS-SCOPE-MISMATCH', 'IOS-TOKEN-INVALID',
    'IOS-DELETE-FAILED', 'IOS-INPUT-TOKEN', 'IOS-CREATE-EMPTY',
    'IOS-ENCODE-EMPTY', 'IOS-WRITE-FAILED', 'IOS-URL-EMPTY',
    'IOS-BOOL-EMPTY', 'IOS-BACKUP-CLEANUP', 'IOS-BACKUP-FAILED',
    'IOS-READBACK-MISSING', 'IOS-READBACK-MISMATCH', 'SAVE-META-LOGOUT',
    'SAVE-META-READ', 'SAVE-RECOVERY-PENDING', 'SAVE-CREDENTIAL-MISSING',
    'IOS-PATH-INVALID', 'SAVE-META-PENDING', 'SAVE-META-COMMIT',
    'SAVE-VAULT-UNEXPECTED',
]);
// Only allowlisted categories cross into the error screen. Never expose native
// error text, response bodies, addresses, credentials or account identifiers.
const storageCodes: Record<string, string> = {
    'Protected credential native bridge failed': 'IOS-NATIVE',
    'Protected credential record creation failed': 'IOS-CREATE',
    'Protected credential record encoding failed': 'IOS-ENCODE',
    'Protected credential record decode failed': 'IOS-JSON',
    'Protected credential save failed': 'IOS-WRITE',
    'Protected credential save unavailable': 'IOS-PROTECTION',
    'Protected credential backup exclusion failed': 'IOS-BACKUP',
    'Protected credential storage locked': 'IOS-LOCKED',
    'Protected credential storage unavailable': 'IOS-PROTECTION',
    'Invalid protected credential': 'IOS-RECORD',
};
export function loginDiagnostic(stage: string | undefined, error: unknown): string {
    const value = error as { message?: unknown; status?: unknown; diagnostic?: unknown; iosStep?: unknown; storageCode?: unknown } | null;
    const prefix = stage === 'storage' ? 'SAVE' : stage === 'verify' ? 'VERIFY' : 'CLAIM';
    if (typeof value?.storageCode === 'string' && detailedStorageCodes.has(value.storageCode)) return value.storageCode;
    if (stage === 'storage' && value?.message === 'Protected credential native bridge failed'
        && typeof value.iosStep === 'string'
        && ['MANAGER','EXISTS','DELETE','URL','BOOL','CREATE','ENCODE','WRITE','ATTRIBUTES','COMPARE','READ','BACKUP'].includes(value.iosStep))
        return `IOS-NATIVE-${value.iosStep}`;
    if (stage === 'storage' && typeof value?.message === 'string' && storageCodes[value.message])
        return storageCodes[value.message];
    if (typeof value?.status === 'number' && Number.isInteger(value.status) && value.status >= 100 && value.status <= 599)
        return `${prefix}-HTTP-${value.status}`;
    if (value?.diagnostic === 'timeout') return `${prefix}-TIMEOUT`;
    if (value?.diagnostic === 'network') return `${prefix}-NETWORK`;
    if (value?.diagnostic === 'identity') return `${prefix}-IDENTITY`;
    return stage === 'storage' ? 'SAVE-UNEXPECTED' : `${prefix}-INVALID`;
}
