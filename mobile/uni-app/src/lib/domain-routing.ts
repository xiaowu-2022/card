/** Public discovery only. Never pass authentication headers to a candidate origin. */
export type Directory = { tenant: { id: string; slug: string }; origins: string[] };
type Probe = (origin: string) => Promise<unknown>;
export function normalizeOrigin(value: unknown, development = false): string | null {
    if (typeof value !== 'string') return null;
    const match = /^(https?):\/\/([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(:\d{1,5})?\/?$/i.exec(value);
    if (!match || (match[1].toLowerCase() !== 'https' && !development)) return null;
    if (match[3] && (+match[3].slice(1) < 1 || +match[3].slice(1) > 65535)) return null;
    if (match[2].split('.').some((part) => !part || part.startsWith('-') || part.endsWith('-')))
        return null;
    return `${match[1].toLowerCase()}://${match[2].toLowerCase()}${match[3] ?? ''}`;
}
export class DomainRouter {
    selected: string | null = null;
    private pending: Promise<void> | null = null;
    private tenantId: string | null = null;
    private known: string[];
    constructor(
        private seeds: string[],
        private slug: string,
        private development: boolean,
        private probe: Probe,
        cached: unknown,
        private save: (origins: string[]) => void,
        private now: () => number = Date.now,
    ) {
        this.known = this.clean(cached);
    }
    private clean(values: unknown): string[] {
        return Array.isArray(values)
            ? [
                  ...new Set(
                      values
                          .map((value) => normalizeOrigin(value, this.development))
                          .filter((value): value is string => !!value),
                  ),
              ]
            : [];
    }
    private async measure(origins: string[]) {
        const results: { origin: string; elapsed: number; directory: Directory }[] = [];
        let index = 0;
        await Promise.all(
            Array.from({ length: Math.min(6, origins.length) }, async () => {
                while (index < origins.length) {
                    const origin = origins[index++];
                    const start = this.now();
                    try {
                        const data = (await this.probe(origin)) as Directory;
                        if (
                            data?.tenant?.slug !== this.slug ||
                            typeof data.tenant.id !== 'string' ||
                            !data.tenant.id ||
                            !Array.isArray(data.origins)
                        )
                            continue;
                        if (this.tenantId && data.tenant.id !== this.tenantId) continue;
                        const directory = {
                            tenant: data.tenant,
                            origins: this.clean(data.origins),
                        };
                        if (!directory.origins.includes(origin)) continue;
                        results.push({ origin, elapsed: this.now() - start, directory });
                    } catch {
                        /* An unreachable or invalid candidate is never selected. */
                    }
                }
            }),
        );
        return results.sort((a, b) => a.elapsed - b.elapsed);
    }
    ready(): Promise<void> {
        return this.pending ?? (this.selected ? Promise.resolve() : this.refresh());
    }
    refresh(): Promise<void> {
        if (this.pending) return this.pending;
        this.pending = (async () => {
            const known = this.clean([...this.seeds, ...this.known]);
            const initial = await this.measure(known);
            if (!initial.length) throw new Error('No available company domain');
            const directory = initial[0].directory;
            this.tenantId = directory.tenant.id;
            // Replace the cached list, so removed/unassigned domains stop being candidates.
            this.known = directory.origins;
            try {
                this.save(this.known);
            } catch {
                /* Storage failures cannot block networking. */
            }
            const extra = await this.measure(
                this.known.filter((origin) => !known.includes(origin)),
            );
            const best = [...initial, ...extra]
                .filter(
                    (item) =>
                        this.known.includes(item.origin) &&
                        item.directory.tenant.id === this.tenantId,
                )
                .sort((a, b) => a.elapsed - b.elapsed)[0];
            if (!best) throw new Error('No available company domain');
            this.selected = best.origin;
        })()
            .catch((error) => {
                this.selected = null;
                throw error;
            })
            .finally(() => {
                this.pending = null;
            });
        return this.pending;
    }
}
