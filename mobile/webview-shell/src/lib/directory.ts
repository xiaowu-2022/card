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
export const discoveryLimits = { probeMs: 10000, totalMs: 20000, preferredGraceMs: 750 };
export async function discover(seeds: string[], slug: string, cached: Partial<DirectoryCache> | null,
    probe: DirectoryProbe, now = Date.now,
    limits: { probeMs: number; totalMs: number; excludedOrigins?: string[]; preferredGraceMs?: number } = discoveryLimits,
    random = Math.random): Promise<DirectoryCache> {
    const deadline = now() + Math.min(discoveryLimits.totalMs, limits.totalMs);
    const excluded = clean(limits.excludedOrigins);
    const candidates = clean([...clean(seeds), ...clean(cached?.origins)]).filter(url => !excluded.includes(url));
    // Revalidate the last working host first. On a fresh install use the three
    // bootstrap hosts, so a known reachable bootstrap isn't randomly omitted.
    // Remaining slots are sampled without replacement; no cached host bypasses
    // the HTTPS/company/activity checks below.
    const previous = origin(cached?.selected);
    const preferred = previous && candidates.includes(previous) && clean(cached?.origins).includes(previous)
        ? [previous] : clean(seeds).filter(url => !excluded.includes(url)).slice(0, 3);
    const remaining = candidates.filter(value => !preferred.includes(value));
    const count = Math.min(6, candidates.length);
    const extra = Math.max(0, count - preferred.length);
    for (let index = 0; index < extra; index++) {
        const value = random();
        if (!Number.isFinite(value) || value < 0 || value >= 1) throw new Error('Invalid random source');
        const selected = index + Math.floor(value * (remaining.length - index));
        [remaining[index], remaining[selected]] = [remaining[selected], remaining[index]];
    }
    const sampled = [...preferred, ...remaining.slice(0, extra)];
    return new Promise<DirectoryCache>((resolve, reject) => {
        type Task = { settled: boolean; cancel?: () => void };
        const active = new Map<string, Task>();
        let next = 0, done = false, receivedResponse = false;
        let groupTimer: ReturnType<typeof setTimeout> | undefined;
        let preferenceTimer: ReturnType<typeof setTimeout> | undefined;
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
            clearTimeout(totalTimer); clearTimeout(groupTimer); clearTimeout(preferenceTimer);
            for (const [url, task] of active) stop(url, task, true);
            if (!directory || !selected) {
                reject(Object.assign(new Error('暂时无法连接服务器，请稍后重试。首次使用请确认已允许应用联网。'),
                    { networkUnavailable: !receivedResponse }));
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
            clearTimeout(preferenceTimer);
            if (now() >= deadline || next >= sampled.length) { finish(); return; }
            const group = sampled.slice(next, next + 3);
            next += group.length;
            const preferredHost = previous && group.includes(previous) ? previous : null;
            let fallback: { directory: Directory; selected: string } | null = null;
            const groupDeadline = Math.min(deadline, now() + Math.min(discoveryLimits.probeMs, limits.probeMs));
            const expireGroup = () => {
                for (const [url, task] of active) stop(url, task, true);
                if (fallback) finish(fallback.directory, fallback.selected);
                else startGroup();
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
                    receivedResponse = true;
                    stop(url, task, false);
                    if (now() >= deadline) { finish(); return; }
                    if (now() >= groupDeadline) { expireGroup(); return; }
                    const directory = value as Directory;
                    const origins = clean(directory?.origins);
                    if (directory?.tenant?.slug === slug && typeof directory.tenant.id === 'string' && directory.tenant.id
                        && (!cached?.tenantId || directory.tenant.id === cached.tenantId) && origins.includes(url)) {
                        const verified = { tenant: directory.tenant, origins };
                        if (!preferredHost || url === preferredHost || !active.has(preferredHost)) {
                            finish(verified, url);
                            return;
                        }
                        // Keep a validated backup ready, but do not abandon the
                        // previous working host merely because it is slightly slower.
                        if (!fallback) {
                            fallback = { directory: verified, selected: url };
                            preferenceTimer = setTimeout(() => {
                                if (!done && fallback) finish(fallback.directory, fallback.selected);
                            }, Math.min(limits.preferredGraceMs ?? discoveryLimits.preferredGraceMs, Math.max(0, groupDeadline - now())));
                        }
                    }
                    if (fallback && preferredHost && !active.has(preferredHost)) { finish(fallback.directory, fallback.selected); return; }
                    if (!active.size) startGroup();
                }, error => {
                    if (done || task.settled) return;
                    if (typeof error?.status === 'number' && error.status > 0) receivedResponse = true;
                    stop(url, task, false);
                    if (fallback && preferredHost && !active.has(preferredHost)) { finish(fallback.directory, fallback.selected); return; }
                    if (!active.size) startGroup();
                });
            }
        };
        startGroup();
    });
}
