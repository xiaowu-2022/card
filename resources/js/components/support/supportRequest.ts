import { showOperationResult } from '@/components/admin/operation-result';
import { useEditor } from '@/components/admin/editor-context';
import { companyEditor } from '@/components/admin/company-settings';
export class SupportRequestError extends Error {
    constructor(
        public status: number,
        message: string,
    ) {
        super(message);
    }
}
export async function supportRequest<T>(url: string, data?: object, company?: string): Promise<T> {
    const csrf = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.slice(11) ?? '',
    );
    const response = await fetch(url, {
        method: data ? 'POST' : 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(company ? { 'X-Admin-Company': company } : {}),
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrf,
        },
        body: data ? JSON.stringify(data) : undefined,
    });
    if (!response.ok || response.redirected) {
        throw new SupportRequestError(response.status, 'Unable to save. Refresh and try again.');
    }
    const result = response.status === 204 ? (undefined as T) : ((await response.json()) as T);
    if (data) showOperationResult('success', 'Request completed.');
    return result;
}

export function useSupportRequest() {
    const editor = useEditor();
    const company = editor ? companyEditor(editor.page.url)?.company : undefined;
    return <T>(url: string, data?: object) => supportRequest<T>(url, data, company);
}
