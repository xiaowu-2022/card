// Shared Pusher/Reverb protocol for browsers and uni-app's native SocketTask.
// Credentials go only to the existing same-origin HTTP auth endpoint.
export type RealtimeConfig = {
    enabled: boolean;
    path?: string;
    port?: number | null;
    channels?: string[];
};
export type SocketTransport = { send(data: string): void; close(): void };
export type SocketHandlers = { message(data: string): void; close(): void };
export type RealtimeOptions = {
    origin(): string;
    configuration(): Promise<RealtimeConfig>;
    authorize(socketId: string, channel: string): Promise<{ auth: string }>;
    connect(url: string, handlers: SocketHandlers): SocketTransport;
    changed(): void;
    status(connected: boolean): void;
};
export function supportSocketUrl(origin: string, config: RealtimeConfig): string {
    // Native JS runtimes need not provide the browser URL constructor.
    const match = /^(https?):\/\/([a-z0-9.-]+|\[[a-f0-9:]+\])(?::([0-9]{1,5}))?\/?$/i.exec(origin);
    if (!match || !/^\/app\/[A-Za-z0-9_%.-]+$/.test(config.path ?? ''))
        throw new Error('Invalid realtime configuration');
    const port = config.port ?? (match[3] ? Number(match[3]) : null);
    if (port != null && (!Number.isInteger(port) || port < 1 || port > 65535))
        throw new Error('Invalid realtime port');
    return `${match[1]?.toLowerCase() === 'https' ? 'wss' : 'ws'}://${match[2]}${port == null ? '' : ':' + port}${config.path}?protocol=7&client=card-support&version=1.0&flash=false`;
}
export function createSupportRealtime(options: RealtimeOptions) {
    let active = false,
        connected = false,
        epoch = 0,
        attempts = 0;
    let socket: SocketTransport | undefined;
    let retry: ReturnType<typeof setTimeout> | undefined;
    let deadline: ReturnType<typeof setTimeout> | undefined;
    let heartbeat: ReturnType<typeof setInterval> | undefined;
    let renew: ReturnType<typeof setTimeout> | undefined;
    const state = (value: boolean) => {
        connected = value;
        options.status(value);
    };
    const dispose = () => {
        epoch++;
        clearTimeout(retry);
        clearTimeout(deadline);
        clearInterval(heartbeat);
        clearTimeout(renew);
        const previous = socket;
        socket = undefined;
        previous?.close();
        state(false);
    };
    const fail = () => {
        if (!active) return;
        dispose();
        const delay = Math.min(30000, 1000 * 2 ** Math.min(attempts++, 5)) + Math.random() * 500;
        retry = setTimeout(() => void open(), delay);
    };
    async function open() {
        if (!active) return;
        dispose();
        const run = epoch;
        const current = () => active && run === epoch;
        deadline = setTimeout(fail, 15000);
        try {
            const config = await options.configuration();
            if (!current()) return;
            if (!config.enabled) {
                clearTimeout(deadline);
                retry = setTimeout(() => void open(), 60000);
                return;
            }
            const channels = config.channels ?? [];
            if (!channels.length || channels.some((c) => !/^private-support\.[a-z0-9.]+$/.test(c)))
                throw new Error();
            const subscribed = new Set<string>();
            let authenticated = false,
                lastMessage = Date.now(),
                pingAt = 0;
            const send = (event: string, data: unknown) => {
                try {
                    socket?.send(JSON.stringify({ event, data }));
                } catch {
                    if (current()) fail();
                }
            };
            const authorize = async (socketId: string) => {
                if (authenticated) return;
                authenticated = true;
                for (const channel of channels) {
                    const signature = await options.authorize(socketId, channel);
                    if (!current()) return;
                    if (typeof signature.auth !== 'string') throw new Error();
                    send('pusher:subscribe', { channel, auth: signature.auth });
                }
            };
            socket = options.connect(supportSocketUrl(options.origin(), config), {
                close: () => {
                    if (current()) fail();
                },
                message: (raw) => {
                    if (!current()) return;
                    try {
                        const frame = JSON.parse(raw);
                        const data =
                            typeof frame.data === 'string' ? JSON.parse(frame.data) : frame.data;
                        lastMessage = Date.now();
                        pingAt = 0;
                        if (frame.event === 'pusher:connection_established') {
                            if (!/^[0-9]{1,20}\.[0-9]{1,20}$/.test(data.socket_id))
                                throw new Error();
                            void authorize(data.socket_id).catch(() => {
                                if (current()) fail();
                            });
                        } else if (frame.event === 'pusher:ping') send('pusher:pong', {});
                        else if (
                            frame.event === 'pusher:error' ||
                            frame.event === 'pusher:subscription_error'
                        )
                            fail();
                        else if (
                            frame.event === 'pusher_internal:subscription_succeeded' &&
                            channels.includes(frame.channel)
                        ) {
                            subscribed.add(frame.channel);
                            if (subscribed.size === channels.length && !connected) {
                                attempts = 0;
                                clearTimeout(deadline);
                                state(true);
                                // Recover messages missed during sleep, outage or subscription setup.
                                options.changed();
                            }
                        } else if (
                            frame.event === 'support.changed' &&
                            subscribed.has(frame.channel)
                        )
                            options.changed();
                    } catch {
                        fail();
                    }
                },
            });
            heartbeat = setInterval(() => {
                if (!current()) return;
                if (pingAt && Date.now() - pingAt > 10000) fail();
                else if (!pingAt && Date.now() - lastMessage > 25000) {
                    pingAt = Date.now();
                    send('pusher:ping', {});
                }
            }, 5000);
            // Re-open through current scoped HTTP authorization, also discovering changed agent grants.
            renew = setTimeout(() => void open(), 60000);
        } catch {
            if (current()) fail();
        }
    }
    return {
        start() {
            if (!active) {
                active = true;
                attempts = 0;
                void open();
            }
        },
        stop() {
            active = false;
            dispose();
        },
        restart() {
            if (active) void open();
        },
        connected: () => connected,
    };
}
