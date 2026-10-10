import { errorMessage, t } from '@/i18n/admin';

export type OperationResult = { kind: 'success' | 'error'; messages: string[] };
let results: OperationResult[] = [];
const listeners = new Set<() => void>();
export const isPlatform = () =>
    typeof location !== 'undefined' &&
    (location.pathname === '/platform' || location.pathname.startsWith('/platform/'));
export const getOperationResults = () => results;
export function subscribeOperationResults(listener: () => void) {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}
export function showOperationResult(kind: OperationResult['kind'], message: string | string[]) {
    if (!isPlatform()) return;
    const messages = [
        ...new Set(
            (Array.isArray(message) ? message : [message])
                .map((value) => (kind === 'error' ? errorMessage(value) : t(value))?.trim())
                .filter((value): value is string => !!value),
        ),
    ];
    if (!messages.length) return;
    // A form may report the same failure through both its transport and field summary.
    // Merge pending messages; acknowledgement clears them so an identical retry is visible.
    const existing = results.find((result) => result.kind === kind);
    if (existing) {
        const generic = t('Request completed.');
        if (
            kind === 'success' &&
            messages.every((value) => value === generic) &&
            existing.messages.some((value) => value !== generic)
        )
            return;
        const previous =
            kind === 'success' && messages.some((value) => value !== generic)
                ? existing.messages.filter((value) => value !== generic)
                : existing.messages;
        const merged = [...new Set([...previous, ...messages])];
        if (
            merged.length === existing.messages.length &&
            merged.every((value, index) => value === existing.messages[index])
        )
            return;
        results = results.map((result) =>
            result === existing ? { ...result, messages: merged } : result,
        );
    } else results = [...results, { kind, messages }];
    listeners.forEach((listener) => listener());
}
export function acknowledgeOperationResult() {
    results = results.slice(1);
    listeners.forEach((listener) => listener());
}
