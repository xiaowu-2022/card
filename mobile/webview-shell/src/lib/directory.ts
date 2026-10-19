export type DirectoryCache = { tenantId: string; origins: string[]; selected: string; fetchedAt: number };
type Directory = { tenant: { id: string; slug: string }; origins: string[] };
export function origin(value: unknown): string | null {
    if (typeof value !== 'string' || !/^https:\/\/[a-z0-9]+(?:[.-][a-z0-9]+)*(?::\d{1,5})?$/i.test(value)) return null;
    if (/zb33333\.com(?::\d+)?$/i.test(value)) return null;
    return value.toLowerCase();
}
function clean(values: unknown): string[] {
    return Array.isArray(values) ? [...new Set(values.map(origin).filter((s): s is string => !!s))] : [];
}
export type DirectoryProbe = (url: string, onAbort?: (cancel: () => void) => void) => Promise<unknown>;
export async function discover(seeds: string[], slug: string, cached: Partial<DirectoryCache> | null,
    probe: DirectoryProbe, now = Date.now,
    limits: { probeMs: number; totalMs: number } = { probeMs: 2000, totalMs: 4000 },
    random = Math.random): Promise<DirectoryCache> {
    const deadline = now() + Math.min(4000, limits.totalMs);
    const candidates = clean([...clean(seeds), ...clean(cached?.origins)]);
    // Partial Fisher-Yates: choose at most two groups without replacement. A
    // cached selection has the same chance as every other eligible candidate.
    const count = Math.min(6, candidates.length);
    for (let index = 0; index < count; index++) {
        const value = random();
        if (!Number.isFinite(value) || value < 0 || value >= 1) throw new Error('Invalid random source');
        const selected = index + Math.floor(value * (candidates.length - index));
        [candidates[index], candidates[selected]] = [candidates[selected], candidates[index]];
    }
    const sampled = candidates.slice(0, count);
    return new Promise<DirectoryCache>((resolve, reject) => {
        type Task = { settled: boolean; cancel?: () => void };
        const active = new Map<string, Task>();
        let next = 0, done = false;
        let groupTimer: ReturnType<typeof setTimeout> | undefined;
        const stop = (url: string, task: Task, abort: boolean) => {
            if (task.settled) return;
            task.settled = true;
            active.delete(url);
            if (abort) {
                try { task.cancel?.(); } catch { /* Native abort failure cannot block selection. */ }
            }
        };
        const finish = (directory?: Directory, selected?: string) => {
            if (done) return;
            done = true;
            clearTimeout(totalTimer); clearTimeout(groupTimer);
            for (const [url, task] of active) stop(url, task, true);
            if (!directory || !selected) {
                reject(new Error('暂时无法连接服务器，请检查网络后重试。'));
                return;
            }
            // Keep the fresh directory, but never probe its additional hosts.
            resolve({ tenantId: directory.tenant.id, origins: directory.origins,
                selected, fetchedAt: now() });
        };
        const totalTimer = setTimeout(() => finish(), Math.max(0, deadline - now()));
        const startGroup = () => {
            if (done) return;
            clearTimeout(groupTimer);
            if (now() >= deadline || next >= sampled.length) { finish(); return; }
            const group = sampled.slice(next, next + 3);
            next += group.length;
            const groupDeadline = Math.min(deadline, now() + Math.min(2000, limits.probeMs));
            const expireGroup = () => {
                for (const [url, task] of active) stop(url, task, true);
                startGroup();
            };
            groupTimer = setTimeout(expireGroup, Math.max(0, groupDeadline - now()));
            for (const url of group) {
                const task: Task = { settled: false };
                active.set(url, task);
                void Promise.resolve().then(() => {
                    if (done || task.settled) return;
                    return probe(url, cancel => {
                        if (task.settled) { try { cancel(); } catch { /* Already cancelled. */ } }
                        else task.cancel = cancel;
                    });
                }).then(value => {
                    if (done || task.settled) return;
                    stop(url, task, false);
                    if (now() >= deadline) { finish(); return; }
                    if (now() >= groupDeadline) { expireGroup(); return; }
                    const directory = value as Directory;
                    const origins = clean(directory?.origins);
                    if (directory?.tenant?.slug === slug && typeof directory.tenant.id === 'string' && directory.tenant.id
                        && (!cached?.tenantId || directory.tenant.id === cached.tenantId) && origins.includes(url)) {
                        finish({ tenant: directory.tenant, origins }, url);
                        return;
                    }
                    if (!active.size) startGroup();
                }, () => {
                    if (done || task.settled) return;
                    stop(url, task, false);
                    if (!active.size) startGroup();
                });
            }
        };
        startGroup();
    });
}
