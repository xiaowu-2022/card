import { t } from '@/i18n/admin';

export async function readEditorResponse(response: Response): Promise<Record<string, unknown>> {
    if (response.status === 409 && response.headers.has('X-Inertia-Location')) {
        throw new Error(t('This page has been updated. Refresh the page and try again.'));
    }
    if (response.status === 401 || response.status === 419 || response.redirected) {
        throw new Error(t('Your session has expired. Refresh the page and sign in again.'));
    }
    const text = await response.text();
    let value: unknown;
    try {
        value = JSON.parse(text);
    } catch {
        throw new Error(t('Unexpected server response. Refresh the page and try again.'));
    }
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        throw new Error(t('Unexpected server response. Refresh the page and try again.'));
    }
    return value as Record<string, unknown>;
}
