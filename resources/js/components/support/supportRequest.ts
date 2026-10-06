export class SupportRequestError extends Error {
    constructor(
        public status: number,
        message: string,
    ) {
        super(message);
    }
}
export async function supportRequest<T>(url: string, data?: object): Promise<T> {
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
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrf,
        },
        body: data ? JSON.stringify(data) : undefined,
    });
    if (!response.ok || response.redirected) {
        throw new SupportRequestError(response.status, 'Unable to save. Refresh and try again.');
    }
    return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}
