import { createSupportRealtime, type RealtimeConfig } from '../../../../shared/support-realtime';
import { request } from './api';
import { companyOrigin } from './origin';

const listeners = new Set<() => void>();
let timer: ReturnType<typeof setTimeout> | undefined;
function notify() {
    clearTimeout(timer);
    timer = setTimeout(() => listeners.forEach((listener) => listener()), 30);
}
const connection = createSupportRealtime({
    origin: companyOrigin,
    configuration: () => request<RealtimeConfig>('/support/realtime'),
    authorize: (socket_id, channel_name) =>
        request('/support/realtime/auth', 'POST', { socket_id, channel_name }),
    connect(url, handlers) {
        const socket = uni.connectSocket({
            url,
            header: { Origin: companyOrigin() },
            complete: () => {},
        });
        socket.onMessage((event) => {
            if (typeof event.data === 'string') handlers.message(event.data);
        });
        socket.onClose(handlers.close);
        socket.onError(() => {
            socket.close({});
            handlers.close();
        });
        return {
            send: (data) => {
                socket.send({ data, fail: handlers.close });
            },
            close: () => {
                socket.close({});
            },
        };
    },
    changed: notify,
    status: () => {},
});
export function onSupportRealtime(listener: () => void) {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}
export function resumeSupportRealtime() {
    connection.start();
}
export function pauseSupportRealtime() {
    connection.stop();
    clearTimeout(timer);
}
export function restartSupportRealtime() {
    connection.restart();
}
export const supportRealtimeConnected = connection.connected;
