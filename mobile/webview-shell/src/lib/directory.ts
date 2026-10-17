export type DirectoryCache = { tenantId: string; origins: string[]; selected: string; fetchedAt: number };
type Directory = { tenant: { id: string; slug: string }; origins: string[] };
export function origin(value: unknown): string | null {
    if (typeof value !== 'string' || !/^https:\/\/[a-z0-9]+(?:[.-][a-z0-9]+)*(?::\d{1,5})?$/i.test(value)) return null;
    if (/zb33333\.com(?::\d+)?$/i.test(value)) return null;
    return value.toLowerCase();
}
function clean(values: unknown): string[] {
    return Array.isArray(values) ? [...new Set(values.map(origin).filter((s): s is string => !!s))].slice(0, 100) : [];
}
export type DirectoryProbe = (url: string, onAbort?: (cancel: () => void) => void) => Promise<unknown>;
export async function discover(seeds: string[], slug: string, cached: Partial<DirectoryCache> | null,
    probe: DirectoryProbe, now = Date.now,
    limits: { probeMs: number; totalMs: number; acceptableMs?: number } = { probeMs: 1000, totalMs: 4000, acceptableMs: 300 }): Promise<DirectoryCache> {
    const deadline = now() + limits.totalMs;
    const acceptableMs = limits.acceptableMs ?? 300;
    const cachedOrigins = clean(cached?.origins);
    const previous = origin(cached?.selected);
    const candidates = clean([
        ...(previous && cachedOrigins.includes(previous) ? [previous] : []),
        ...clean(seeds), ...cachedOrigins,
    ]);
    return new Promise<DirectoryCache>((resolve, reject) => {
        type Task = { settled: boolean; timer?: ReturnType<typeof setTimeout>; cancel?: () => void };
        const active = new Map<string, Task>();
        let next = 0, done = false;
        let authoritative: Directory | undefined;
        let best: { url: string; elapsed: number } | undefined;
        const stop = (url: string, task: Task, abort: boolean) => {
            if (task.settled) return;
            task.settled = true;
            clearTimeout(task.timer);
            active.delete(url);
            if (abort) {
                try { task.cancel?.(); } catch { /* Native abort failure cannot block selection. */ }
            }
        };
        const finish = () => {
            if (done) return;
            done = true;
            clearTimeout(totalTimer);
            for (const [url, task] of active) stop(url, task, true);
            if (!authoritative || !best) {
                reject(new Error('暂时无法连接服务器，请检查网络后重试。'));
                return;
            }
            resolve({ tenantId: authoritative.tenant.id, origins: authoritative.origins,
                selected: best.url, fetchedAt: now() });
        };
        const totalTimer = setTimeout(finish, Math.max(0, deadline - now()));
        const pump = () => {
            if (done) return;
            if (now() >= deadline) { finish(); return; }
            while (active.size < 3 && next < candidates.length) {
                const url = candidates[next++];
                if (authoritative && !authoritative.origins.includes(url)) continue;
                const start = now();
                const task: Task = { settled: false };
                active.set(url, task);
                task.timer = setTimeout(() => {
                    stop(url, task, true);
                    pump();
                }, Math.max(0, Math.min(limits.probeMs, deadline - now())));
                void Promise.resolve().then(() => {
                    if (done || task.settled) return;
                    return probe(url, cancel => {
                        if (task.settled) { try { cancel(); } catch { /* Already cancelled. */ } }
                        else task.cancel = cancel;
                    });
                }).then(value => {
                    if (done || task.settled) return;
                    stop(url, task, false);
                    const d = value as Directory;
                    const elapsed = now() - start;
                    if (now() >= deadline) { finish(); return; }
                    const origins = clean(d?.origins);
                    if (d?.tenant?.slug === slug && typeof d.tenant.id === 'string' && d.tenant.id
                        && (!cached?.tenantId || d.tenant.id === cached.tenantId)
                        && (!authoritative || (d.tenant.id === authoritative.tenant.id && authoritative.origins.includes(url)))
                        && origins.includes(url)) {
                        if (!authoritative) {
                            authoritative = { tenant: d.tenant, origins };
                            // Save the full fresh directory without waiting to probe every host.
                            for (const host of origins) if (!candidates.includes(host)) candidates.push(host);
                        }
                        if (!best || elapsed < best.elapsed) best = { url, elapsed };
                        if (elapsed <= acceptableMs) { finish(); return; }
                        for (const [host, pending] of active) {
                            if (!authoritative.origins.includes(host)) stop(host, pending, true);
                        }
                    }
                    pump();
                }, () => {
                    if (done || task.settled) return;
                    stop(url, task, false);
                    pump();
                });
            }
            // No qualifying response: retain the fastest verified fallback within the budget.
            if (!active.size && next >= candidates.length) finish();
        };
        pump();
    });
}
