import { createSupportRealtime, type RealtimeConfig } from '../../../shared/support-realtime';

const listeners = new Set<() => void>();
let notifyTimer: ReturnType<typeof setTimeout> | undefined;
function notify() {
    clearTimeout(notifyTimer);
    if (document.hidden) {
        listeners.forEach((listener) => listener());
        return;
    }
    notifyTimer = setTimeout(() => listeners.forEach((listener) => listener()), 30);
}
function base() {
    return window.location.pathname.startsWith('/platform')
        ? '/platform/support/realtime'
        : '/admin/support/realtime';
}
async function read<T>(path: string, data?: Record<string, string>): Promise<T> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    const response = await fetch(path, {
        method: data ? 'POST' : 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            ...(data ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf ?? '' } : {}),
        },
        ...(data ? { body: JSON.stringify(data) } : {}),
    });
    if (!response.ok || response.redirected) throw new Error('Realtime access unavailable');
    return response.json() as Promise<T>;
}
const connection = createSupportRealtime({
    origin: () => window.location.origin,
    configuration: () => read<RealtimeConfig>(base()),
    authorize: (socket_id, channel_name) => read(base() + '/auth', { socket_id, channel_name }),
    connect(url, handlers) {
        const socket = new WebSocket(url);
        socket.onmessage = (event) => {
            if (typeof event.data === 'string') handlers.message(event.data);
        };
        socket.onclose = () => handlers.close();
        socket.onerror = () => socket.close();
        return { send: (data) => socket.send(data), close: () => socket.close() };
    },
    changed: notify,
    status: () => {},
});
function visibility() {
    if (listeners.size) connection.start();
    else connection.stop();
}
export function subscribeSupportRealtime(listener: () => void) {
    listeners.add(listener);
    if (listeners.size === 1) document.addEventListener('visibilitychange', visibility);
    visibility();
    if (connection.connected()) notify();
    return () => {
        listeners.delete(listener);
        if (!listeners.size) {
            document.removeEventListener('visibilitychange', visibility);
            clearTimeout(notifyTimer);
            connection.stop();
        }
    };
}
export const supportRealtimeConnected = connection.connected;
