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
export async function discover(seeds: string[], slug: string, cached: Partial<DirectoryCache> | null,
    probe: (url: string) => Promise<unknown>, now = Date.now,
    limits = { probeMs: 4500, totalMs: 15000 }): Promise<DirectoryCache> {
    const deadline = now() + limits.totalMs;
    // Native request callbacks can be lost during DNS/TLS or runtime failures.
    // Bound each worker independently, including newly discovered/cache entries.
    async function boundedProbe(url: string): Promise<unknown> {
        const remaining = Math.min(limits.probeMs, deadline - now());
        if (remaining <= 0) throw new Error('timeout');
        let timer: ReturnType<typeof setTimeout> | undefined;
        try {
            return await Promise.race([
                Promise.resolve().then(() => probe(url)),
                new Promise<never>((_, reject) => { timer = setTimeout(() => reject(new Error('timeout')), remaining); }),
            ]);
        } finally { clearTimeout(timer); }
    }
    const trustedSeeds = clean(seeds);
    const candidates = clean([...trustedSeeds, ...clean(cached?.origins)]);
    async function measure(urls: string[], tenantId?: string) {
        const results: { url: string; elapsed: number; directory: Directory }[] = [];
        let next = 0;
        await Promise.all(Array.from({ length: Math.min(6, urls.length) }, async () => {
            while (next < urls.length && now() < deadline) {
                const url = urls[next++], start = now();
                try {
                    const d = await boundedProbe(url) as Directory;
                    if (d?.tenant?.slug !== slug || typeof d.tenant.id !== 'string' || !d.tenant.id
                        || (tenantId && d.tenant.id !== tenantId)) continue;
                    const origins = clean(d.origins);
                    if (!origins.includes(url)) continue;
                    results.push({ url, elapsed: now() - start, directory: { tenant: d.tenant, origins } });
                } catch { /* Unavailable candidates are excluded; no business requests here. */ }
            }
        }));
        return results.sort((a,b) => a.elapsed - b.elapsed);
    }
    const initial = await measure(candidates, cached?.tenantId);
    if (!initial.length) throw new Error('暂时无法连接服务器，请检查网络后重试。');
    const authoritative = initial[0].directory;
    const extra = await measure(authoritative.origins.filter(s => !candidates.includes(s)), authoritative.tenant.id);
    const valid = [...initial,...extra].filter(r => r.directory.tenant.id === authoritative.tenant.id && authoritative.origins.includes(r.url)).sort((a,b)=>a.elapsed-b.elapsed);
    const selected = valid.find(r => r.url === cached?.selected) ?? valid[0];
    if (!selected) throw new Error('暂无可用线路，请稍后重试。');
    // Preserve an available previous origin so host-only login cookies keep working.
    return { tenantId: authoritative.tenant.id, origins: authoritative.origins, selected: selected.url, fetchedAt: now() };
}
