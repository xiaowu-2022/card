// A failed first request can precede the OS data-access decision. We cannot
// infer that decision from Wi-Fi/unknown/none, so retry actual HTTPS requests.
export class StartupCancelled extends Error {}
export function networkStartup(options: {
    firstUse: boolean;
    waiting(value: boolean): void;
    now?: () => number;
    graceMs?: number;
    retryMs?: number[];
}) {
    const now = options.now ?? Date.now;
    let connected = false, disposed = false, pausedAt: number | null = null;
    let deadline = 0, running = false;
    let timer: ReturnType<typeof setTimeout> | undefined, resume: (() => void) | null = null;
    function wake() { resume?.(); }
    function wait(delay?: number) {
        return new Promise<void>(resolve => {
            const finish = () => { clearTimeout(timer); timer = undefined; resume = null; resolve(); };
            resume = finish;
            if (delay !== undefined) timer = setTimeout(finish, Math.max(0, delay));
            if (disposed) finish();
        });
    }
    return {
        wake,
        pause() { if (pausedAt === null) pausedAt = now(); wake(); },
        resume() {
            if (pausedAt !== null && deadline) deadline += Math.max(0, now() - pausedAt);
            pausedAt = null; wake();
        },
        dispose() { disposed = true; wake(); },
        async run<T>(attempt: () => Promise<T>): Promise<T> {
            if (running) throw new Error('Startup connection already running');
            running = true;
            let retries = 0;
            deadline = 0;
            try {
                while (!disposed) {
                    if (pausedAt !== null) { await wait(); continue; }
                    try {
                        const result = await attempt();
                        if (disposed) throw new StartupCancelled();
                        connected = true;
                        return result;
                    } catch (error) {
                        if (disposed) throw new StartupCancelled();
                        if (!options.firstUse || connected || !(error as { networkUnavailable?: boolean })?.networkUnavailable) throw error;
                        if (!deadline) deadline = now() + (options.graceMs ?? 45000);
                        if (now() >= deadline) throw error;
                        options.waiting(true);
                        const delays = options.retryMs ?? [750, 1500, 3000, 5000];
                        const delay = delays[Math.min(retries++, delays.length - 1)] ?? 5000;
                        if (pausedAt === null) await wait(Math.min(delay, deadline - now()));
                    }
                }
                throw new StartupCancelled();
            } finally { running = false; options.waiting(false); }
        },
    };
}
