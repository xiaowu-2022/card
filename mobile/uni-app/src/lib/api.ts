import { networkRecovery } from './network-recovery';
import company from '../generated/company.json';
import { companyOrigin, ensureCompanyOrigin, refreshCompanyOrigins } from './origin';
import { androidCredentialVault, type AndroidBridge } from './device-credentials';
import { loadBrowserToken, saveBrowserToken, publishBrowserToken } from './browser-credentials';
export const native = import.meta.env.UNI_PLATFORM === 'app';
let token: string | null = null;
let tokenLoaded = false;
function credentialVault() {
    const runtime = (globalThis as typeof globalThis & { plus?: { os: { name: string }; android: AndroidBridge } }).plus;
    if (!runtime) throw new Error('Secure credential storage unavailable');
    // iOS retains the existing memory-only policy until a Keychain bridge exists.
    if (runtime.os.name !== 'Android') return null;
    const scope = `${company.appId}:${company.tenantSlug}:${company.apiOrigin}`;
    const key = `encrypted-session:${scope}`;
    return androidCredentialVault(runtime.android, scope, {
        get: () => uni.getStorageSync(key),
        set: value => uni.setStorageSync(key, value),
        remove: () => uni.removeStorageSync(key),
    });
}
let flow: string | null = null;
let flowUpdatedAt = 0;
let language = '';
let page = '/';
export let sessionGeneration = 0;
export function setToken(value: string | null) {
    if (native) credentialVault()?.write(value);
    else saveBrowserToken(value);
    token = value;
    tokenLoaded = true;
    sessionGeneration++;
}
let loadingToken: Promise<void> | null = null;
async function ensureToken() {
    if (tokenLoaded) return;
    if (!loadingToken) {
        const generation = sessionGeneration;
        loadingToken = (async () => {
            const value = native ? credentialVault()?.read() ?? null : await loadBrowserToken();
            if (generation !== sessionGeneration) return;
            token = value; tokenLoaded = true;
        })().finally(() => { loadingToken = null; });
    }
    await loadingToken;
}
export function hasConsumerToken() { return tokenLoaded && !!token; }
export function persistWebviewToken(tenantId: string) { return publishBrowserToken(token, tenantId); }
export function setCsrf(value: string | null) {
    // Retained for shared action DTOs; bearer requests never use browser CSRF.
    void value;
}
export function setLanguage(value: string) {
    language = value;
}
export function setCurrentPage(value: string) {
    page = value;
}
export function clearFlow() {
    flow = null;
    flowUpdatedAt = 0;
}
export class ApiError extends Error {
    constructor(
        public status: number,
        public payload?: {
            error?: { code?: string; message?: string; request_id?: string };
            errors?: Record<string, string[]>;
        },
        public requestId?: string,
    ) {
        super('Request failed');
    }
}
export type UploadStage = 'read' | 'prepare' | 'upload' | 'verify' | 'submit';
export class UploadError extends ApiError {
    constructor(public stage: UploadStage, public field: string | null, error: unknown) {
        super(error instanceof ApiError ? error.status : 0, error instanceof ApiError ? error.payload : undefined);
    }
}
async function uploadStep<T>(stage: UploadStage, field: string | null, run: () => Promise<T>): Promise<T> {
    if (stage !== 'read' && stage !== 'prepare') {
        try { return await run(); }
        catch (error) { throw error instanceof UploadError ? error : new UploadError(stage, field, error); }
    }
    let timer: ReturnType<typeof setTimeout> | undefined;
    try {
        return await Promise.race([run(), new Promise<never>((_, reject) => {
            timer = setTimeout(() => reject(transportError({ errMsg: 'timeout' })),
                stage === 'read' ? 15000 : 30000);
        })]);
    }
    catch (error) { throw error instanceof UploadError ? error : new UploadError(stage, field, error); }
    finally { if (timer !== undefined) clearTimeout(timer); }
}
function transportError(error?: { errMsg?: string }) {
    return new ApiError(0, /timeout/i.test(error?.errMsg ?? '')
        ? { error: { code: 'CLIENT_REQUEST_TIMEOUT' } } : { error: { code: 'CLIENT_NETWORK_UNAVAILABLE' } });
}
// Only same-origin API upload responses are parsed. Raw storage/provider bodies
// remain private; presentation still uses the consumer translation allowlist.
function uploadResponseError(response: { statusCode: number; data: string }) {
    let payload: ApiError['payload'];
    try { payload = JSON.parse(response.data); } catch { /* A proxy may return HTML. */ }
    return new ApiError(response.statusCode, payload);
}
function url(path: string) {
    if (!/^\/[a-z0-9/?=&_%+.,:-]+$/i.test(path) || path.startsWith('//') || path.includes('..'))
        throw new Error('Invalid API path');
    return `${native ? companyOrigin() : ''}/api/mobile/v1${path}`;
}
function headers(authenticated = true) {
    const header: Record<string, string> = { Accept: 'application/json', 'X-Consumer-Page': page };
    if (language || native) header['Accept-Language'] = language || uni.getLocale();
    if (authenticated && token) header.Authorization = `Bearer ${token}`;
    if (flow) header['X-Consumer-Flow'] = flow;
    return header;
}
function capture(header: Record<string, unknown> | undefined) {
    const entry = Object.entries(header ?? {}).find(
        ([key]) => key.toLowerCase() === 'x-consumer-flow',
    );
    if (typeof entry?.[1] === 'string') { flow = entry[1]; flowUpdatedAt = Date.now(); }
}
function stableReadData(value: unknown): unknown {
    if (Array.isArray(value)) return value.map(stableReadData);
    if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).sort(([a], [b]) => a.localeCompare(b)).map(([key, entry]) => [key, stableReadData(entry)]));
    return value;
}
type ReadFlight = { promise: Promise<unknown>; listeners: Set<(status: number, requestId: string) => void> };
const readFlights = new Map<string, ReadFlight>();
function recoveryFailure(error: unknown, mutation: boolean) {
    if (!native && typeof networkRecovery !== 'undefined' && error instanceof ApiError
        && (error.status === 0 || error.status >= 500)) networkRecovery.failure(mutation);
}
export async function request<T>(
    path: string,
    method: 'GET' | 'POST' = 'GET',
    data?: Record<string, unknown>,
    onResponse?: (status: number, requestId: string) => void,
    anonymous = false,
    options: { background?: boolean } = {},
): Promise<T> {
    const generation = sessionGeneration, context = page, requestLanguage = language;
    await ensureToken();
    if (generation !== sessionGeneration) throw new ApiError(409);
    const recoveryRevision = !native && typeof networkRecovery !== 'undefined' ? networkRecovery.checkpoint() : 0;
    const background = options.background === true || /^\/(?:unread|presence|session|support\/realtime(?:\/auth)?)(?:\?|$)/.test(path);
    const key = JSON.stringify([generation, native ? [company.appId, company.tenantSlug, company.apiOrigin] : companyOrigin(), requestLanguage, context, path, stableReadData(data ?? null), anonymous]);
    const scope: { generation: number; header: Record<string, string> } = { generation, header: { ...headers(!anonymous), 'X-Consumer-Page': context } };
    if (requestLanguage || native) scope.header['Accept-Language'] = requestLanguage || uni.getLocale();
    else delete scope.header['Accept-Language'];
    const run = () => requestOnce<T>(path, method, data, onResponse, anonymous, scope);
    let flight: Promise<T>;
    if (method === 'GET') {
        let shared = readFlights.get(key);
        if (!shared) {
            const entry: ReadFlight = { promise: Promise.resolve(), listeners: new Set() };
            if (onResponse) entry.listeners.add(onResponse);
            entry.promise = requestOnce<T>(path, method, data, (status, requestId) => {
                for (const listener of entry.listeners) listener(status, requestId);
            }, anonymous, scope).finally(() => { if (readFlights.get(key) === entry) readFlights.delete(key); });
            readFlights.set(key, entry);
            shared = entry;
        } else if (onResponse) shared.listeners.add(onResponse);
        flight = shared.promise as Promise<T>;
    } else flight = run();
    try {
        const result = await flight;
        if (generation !== sessionGeneration) throw new ApiError(409);
        if (!native && typeof networkRecovery !== 'undefined' && method === 'GET'
            && !background && (path.startsWith('/client/') || ['/home/summary', '/account', '/cards', '/support', '/bootstrap?defer_unread=1'].includes(path) || path.startsWith('/messages?')) && context === page)
            networkRecovery.success(recoveryRevision);
        return result;
    } catch (error) {
        if (!background && generation === sessionGeneration && context === page) recoveryFailure(error, method === 'POST');
        throw error;
    }
}
async function requestOnce<T>(
    path: string,
    method: 'GET' | 'POST' = 'GET',
    data?: Record<string, unknown>,
    onResponse?: (status: number, requestId: string) => void,
    anonymous = false,
    scope?: { generation: number; header: Record<string, string> },
): Promise<T> {
    await ensureToken();
    const generation = scope?.generation ?? sessionGeneration;
    if (generation !== sessionGeneration) throw new ApiError(409);
    // Refresh an idle form proof before sending, without replaying a mutation.
    if (method === 'POST' && flow && Date.now() - flowUpdatedAt >= 60 * 60 * 1000)
        await request('/bootstrap?defer_unread=1');
    if (generation !== sessionGeneration) throw new ApiError(409);
    const requestHeaders = { ...(scope?.header ?? headers(!anonymous)) };
    delete requestHeaders['X-Consumer-Flow'];
    if (flow) requestHeaders['X-Consumer-Flow'] = flow;
    await ensureCompanyOrigin().catch(() => {
        throw new ApiError(0, { error: { code: 'CLIENT_ORIGIN_UNAVAILABLE' } });
    });
    if (generation !== sessionGeneration) throw new ApiError(409);
    const reference = globalThis.crypto?.randomUUID?.() ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const value = Math.floor(Math.random() * 16);
        return (c === 'x' ? value : (value & 3) | 8).toString(16);
    });
    const timeout = ['/client/kyc/applications', '/client/kyc/recognize-front'].includes(path.split('?')[0]) ? 300000
        : path.split('?')[0] === '/client/cards/cardholder' ? 180000
        : path.split('?')[0] === '/support/messages' ? 60000
        : /^\/images\/direct\/[^/]+\/complete$/.test(path) ? 150000 : 20000;
    const complete = (status: number, data: unknown, header: Record<string, unknown> | undefined): T => {
        if (generation !== sessionGeneration) throw new ApiError(409);
        capture(header);
        const requestId = String(Object.entries(header ?? {}).find(([key]) => key.toLowerCase() === 'x-request-id')?.[1] ?? reference);
        onResponse?.(status, requestId);
        if (status >= 200 && status < 300) return data as T;
        throw new ApiError(status, data as ApiError['payload'], requestId);
    };
    const send = () => !native ? browserFetch(url(path), {
        method, headers: { ...requestHeaders, 'X-Request-ID': reference, ...(method === 'POST' ? { 'Content-Type': 'application/json' } : {}) },
        ...(method === 'POST' ? { body: JSON.stringify(data ?? {}) } : {}),
    }, timeout).then(async response => {
        let result: unknown = null;
        const text = await response.text();
        try { result = text ? JSON.parse(text) : null; }
        catch { throw new ApiError(response.ok ? 502 : response.status); }
        if (response.ok && response.status !== 204 && (result === null || typeof result !== 'object')) throw new ApiError(502);
        const responseHeaders: Record<string, string> = {};
        response.headers.forEach((value, key) => { responseHeaders[key] = value; });
        return complete(response.status, result, responseHeaders);
    }) : new Promise<T>((resolve, reject) =>
        uni.request({
            url: url(path),
            method,
            data,
            header: { ...requestHeaders, 'X-Request-ID': reference },
            withCredentials: false,
            timeout,
            success(response) {
                try { resolve(complete(response.statusCode, response.data, response.header)); }
                catch (error) { reject(error); }
            },
            fail(error) {
                reject(transportError(error));
            },
        }),
    );
    try {
        return await send();
    } catch (error) {
        // Retry reads once after rediscovery. Never replay financial writes/uploads.
        if (!native || method !== 'GET' || !(error instanceof ApiError)
            || ![0, 502, 503, 504].includes(error.status) || generation !== sessionGeneration) throw error;
        await refreshCompanyOrigins().catch(() => { throw new ApiError(0); });
        if (generation !== sessionGeneration) throw error;
        return send();
    }
}
async function browserFetch(url: string, options: RequestInit, timeout: number): Promise<Response> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    try {
        const response = await fetch(url, { ...options, credentials: 'omit', cache: 'no-store', signal: controller.signal });
        // Keep the deadline through body receipt, including private images.
        const body = await response.arrayBuffer();
        return new Response(body.byteLength ? body : null, { status: response.status, statusText: response.statusText, headers: response.headers });
    } catch { throw transportError({ errMsg: controller.signal.aborted ? 'timeout' : 'network' }); }
    finally { clearTimeout(timer); }
}
function uploadTransport(options: UniApp.UploadFileOption) {
    // Some runtimes omit failure callbacks; settle once and abort on our deadline.
    const success = options.success;
    const fail = options.fail;
    let settled = false;
    let task: UniApp.UploadTask | undefined;
    const timer = setTimeout(() => {
        if (settled) return;
        settled = true;
        task?.abort?.();
        fail?.({ errMsg: 'uploadFile:fail timeout' });
    }, options.timeout ?? 60000);
    options = { ...options,
        success: response => { if (!settled) { settled = true; clearTimeout(timer); success?.(response); } },
        fail: error => { if (!settled) { settled = true; clearTimeout(timer); fail?.(error); } },
    };
    if (native) {
        try { task = uni.uploadFile(options) as unknown as UniApp.UploadTask; }
        catch { options.fail?.({ errMsg: 'uploadFile:fail' }); }
        return;
    }
    const generation = sessionGeneration;
    void (async () => {
        const form = new FormData();
        for (const [key, value] of Object.entries(options.formData ?? {})) form.append(key, String(value));
        const files = options.files ?? [{ name: options.name, uri: options.filePath }];
        for (const file of files) {
            const path = file.uri;
            if (!path) throw new ApiError(422);
            const blob = await (await browserFetch(path, {}, 15000)).blob();
            form.append(file.name ?? 'file', blob, 'image');
        }
        if (settled) return;
        const response = await browserFetch(options.url, { method: 'POST', headers: options.header, body: form }, options.timeout ?? 60000);
        if (generation !== sessionGeneration) throw new ApiError(409);
        options.success?.({ statusCode: response.status, data: await response.text(), errMsg: 'uploadFile:ok' });
    })().catch(error => options.fail?.({ errMsg: error instanceof ApiError && error.status === 0 ? error.payload?.error?.code ?? 'network' : 'uploadFile:fail' }));
}
type UploadTicket = { id: string; mode?: string; url: string; imageUrl?: string; fields: Record<string, string> };
export type UploadProgress = { stage: 'uploading' | 'submitting'; completed: number; total: number };
export type Upload = { name: string; path: string };
export async function upload<T>(
    path: string, data: Record<string, unknown>, files: Upload[],
    onProgress?: (progress: UploadProgress) => void,
): Promise<T> {
    const generation = sessionGeneration;
    try { return await uploadOnce<T>(path, data, files, onProgress, generation); }
    catch (error) { if (generation === sessionGeneration) recoveryFailure(error, true); throw error; }
}
async function uploadOnce<T>(
    path: string,
    data: Record<string, unknown>,
    files: Upload[],
    onProgress?: (progress: UploadProgress) => void,
    generation = sessionGeneration,
): Promise<T> {
    await ensureToken();
    if (generation !== sessionGeneration) throw new ApiError(409);
    await uploadStep('prepare', null, () => ensureCompanyOrigin().catch(() => {
        throw new ApiError(0);
    }));
    if (generation !== sessionGeneration) throw new ApiError(409);
    const purpose = ({
        '/client/kyc/applications': 'kyc',
        '/client/kyc/recognize-front': 'kyc',
        '/client/cards/cardholder': 'card',
        '/support/messages': 'support',
    } as Record<string, string>)[path.split('?')[0]] ?? (/^\/support-workspace\/conversations\/[a-f0-9-]{36}\/messages$/.test(path) ? 'support' : undefined);
    if (purpose && files.length) {
        const payload: Record<string, unknown> = { ...data };
        let completed = 0;
        onProgress?.({ stage: 'uploading', completed, total: files.length });
        const prepared: { file: Upload; ticket: UploadTicket }[] = [];
        for (const file of files) {
            const mime = await uploadStep('read', file.name, async () => {
                const info = await new Promise<UniApp.GetImageInfoSuccessData>((resolve, reject) =>
                    uni.getImageInfo({ src: file.path, success: resolve, fail: () => reject(new ApiError(422)) }),
                );
                const kind = info.type?.toLowerCase();
                let mime = kind === 'jpg' || kind === 'jpeg' ? 'image/jpeg'
                    : kind === 'png' ? 'image/png' : kind === 'webp' ? 'image/webp' : '';
                // H5 getImageInfo supplies dimensions but no type; selected images are local blobs.
                if (!mime && !native && /^(blob:|data:image\/)/i.test(file.path)) {
                    const blob = await browserFetch(file.path, {}, 15000).then((response) => response.blob()).catch(() => {
                        throw new ApiError(422);
                    });
                    mime = blob.type.toLowerCase();
                }
                if (!['image/jpeg', 'image/png', 'image/webp'].includes(mime))
                    throw new ApiError(422, { error: { code: 'CLIENT_IMAGE_FORMAT' } });
                return mime;
            });
            const ticket = await uploadStep('prepare', file.name, async () => {
                const ticket = await request<UploadTicket>(
                    '/images/direct', 'POST', { purpose, field: file.name, mime,
                        ...(path.split('?')[0] === '/client/kyc/recognize-front' ? { recognize_front: true } : {}),
                    },
                );
                if (ticket.mode !== 'server' && !/^https:\/\/[a-z0-9.-]+\/?$/i.test(ticket.url)) throw new ApiError(502);
                return ticket;
            });
            prepared.push({ file, ticket });
        }
        const send = ({ file, ticket }: typeof prepared[number]) => uploadStep('upload', file.name, async () => {
            if (ticket.mode === 'kyc_url') {
                if (purpose !== 'kyc' || !ticket.imageUrl || !/^https:\/\//i.test(ticket.imageUrl)) throw new ApiError(502);
                await new Promise<void>((resolve, reject) => uploadTransport({
                    url: ticket.url, filePath: file.path, name: 'file', formData: ticket.fields,
                    header: {}, timeout: 120000,
                    success: response => response.statusCode >= 200 && response.statusCode < 300
                        ? resolve() : reject(new ApiError(502)),
                    fail: error => reject(transportError(error)),
                }));
                payload[file.name + '_upload_id'] = ticket.id;
                payload[file.name + '_url'] = ticket.imageUrl;
                completed++;
                onProgress?.({ stage: 'uploading', completed, total: files.length });
                return;
            }
            // The private same-origin copy is validated before any business binding.
            await new Promise<void>((resolve, reject) => uploadTransport({
                url: url('/images/direct/' + ticket.id + '/backup'),
                filePath: file.path, name: 'file', header: headers(), timeout: 120000,
                success: response => response.statusCode >= 200 && response.statusCode < 300
                    ? resolve() : reject(uploadResponseError(response)),
                fail: error => reject(transportError(error)),
            }));
            if (ticket.mode !== 'server') await new Promise<void>((resolve, reject) => uploadTransport({
                url: ticket.url,
                filePath: file.path,
                name: 'file',
                formData: ticket.fields,
                // Never forward API bearer tokens, session cookies or business fields to OSS.
                header: {},
                timeout: 120000,
                success: (response) => response.statusCode >= 200 && response.statusCode < 300
                    ? resolve() : reject(new ApiError(502)),
                fail: error => reject(transportError(error)),
            })).catch(() => { /* Server copy remains available; completion records OSS retry. */ });
            await uploadStep('verify', file.name, () => request('/images/direct/' + ticket.id + '/complete', 'POST'));
            payload[file.name + '_upload_id'] = ticket.id;
            completed++;
            onProgress?.({ stage: 'uploading', completed, total: files.length });
        });
        const concurrency = purpose === 'kyc' && prepared.every(({ ticket }) => ticket.mode === 'kyc_url') ? 2 : 1;
        for (let index = 0; index < prepared.length; index += concurrency) {
            const results = await Promise.allSettled(prepared.slice(index, index + concurrency).map(send));
            const failed = results.find((result): result is PromiseRejectedResult => result.status === 'rejected');
            if (failed) throw failed.reason;
        }
        onProgress?.({ stage: 'submitting', completed, total: files.length });
        // Business submission is still authenticated and never replayed automatically.
        return uploadStep('submit', null, () => request<T>(path, 'POST', payload));
    }
    return new Promise((resolve, reject) =>
        uploadTransport({
            url: url(path),
            header: headers(),
            timeout: ['/client/kyc/applications', '/client/kyc/recognize-front'].includes(path.split('?')[0]) ? 300000 : 60000,
            formData: Object.fromEntries(Object.entries(data).map(([key, value]) => [key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value ?? '')])),
            files: files.map((file) => ({ name: file.name, uri: file.path })),
            success(response) {
                let result;
                try {
                    result = JSON.parse(response.data);
                } catch {
                    reject(new ApiError(502));
                    return;
                }
                if (response.statusCode >= 200 && response.statusCode < 300) resolve(result as T);
                else reject(new ApiError(response.statusCode, result));
            },
            fail(error) {
                reject(transportError(error));
            },
        }),
    );
}
export function photoUrl(value: string | null) {
    if (!value) return '';
    const origin = companyOrigin();
    if (/[\\\r\n]/.test(value)) return '';
    // These URLs come only from the server's branding DTO. OSS images are public,
    // rendered without API Authorization or flow headers.
    if (/^https:\/\/[a-z0-9.-]+(?::\d+)?\/[^\s]*$/i.test(value) && !value.startsWith(origin + '/')) return value;
    const path = value.startsWith(origin + '/') ? value.slice(origin.length) : value;
    if (!path.startsWith('/') || path.startsWith('//')) return '';
    return native ? origin + path : path;
}
export async function privateImage(path: string): Promise<string> {
    const generation = sessionGeneration, context = page;
    try { return await privateImageOnce(path, generation); }
    catch (error) {
        if (generation === sessionGeneration && context === page) recoveryFailure(error, false);
        throw error;
    }
}
async function privateImageOnce(path: string, expectedGeneration: number): Promise<string> {
    if (
        !/^\/(?:support|support-workspace)\/images\/[a-f0-9-]{36}$/i.test(path) &&
        path !== '/client/promotion/poster-background'
    )
        return Promise.reject(new ApiError(404));
    await ensureCompanyOrigin().catch(() => {
        throw new ApiError(0);
    });
    await ensureToken();
    if (expectedGeneration !== sessionGeneration) throw new ApiError(409);
    const url = (native ? companyOrigin() : '') + '/api/mobile/v1' + path;
    const header = headers();
    if (!native) {
        const generation = sessionGeneration;
        const response = await browserFetch(url, { headers: header }, 60000);
        if (generation !== sessionGeneration) throw new ApiError(409);
        if (!response.ok) throw new ApiError(response.status);
        return URL.createObjectURL(await response.blob());
    }
    return new Promise((resolve, reject) =>
        uni.downloadFile({
            url,
            header,
            success(result) {
                if (result.statusCode === 200) resolve(result.tempFilePath);
                else reject(new ApiError(result.statusCode));
            },
            fail(error) {
                reject(transportError(error));
            },
        }),
    );
}
export { company };
