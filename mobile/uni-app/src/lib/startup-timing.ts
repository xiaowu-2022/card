export type StartupStage = 'startup' | 'lineDiscovery' | 'loginRestore' | 'bootstrap' | 'document' | 'identity' | 'webContent' | 'homeSummary' | 'homeActivity' | 'resumeCheck';
export type StartupTiming = { sequence: number; stage: StartupStage; durationMs: number; outcome: 'ok' | 'error' };
const stages = new Set<StartupStage>(['startup', 'lineDiscovery', 'loginRestore', 'bootstrap', 'document', 'identity', 'webContent', 'homeSummary', 'homeActivity', 'resumeCheck']);
const entries: StartupTiming[] = [];
let sequence = 0;
/** Bounded memory only: fixed stage names and numeric durations, never URLs, users or credentials. */
export function beginTiming(stage: StartupStage, now = Date.now) {
    const started = now();
    let finished = false;
    return (outcome: 'ok' | 'error' = 'ok') => {
        if (finished || !stages.has(stage)) return;
        finished = true;
        const durationMs = Math.min(600000, Math.max(0, Math.round(now() - started)));
        if (!Number.isFinite(durationMs)) return;
        entries.push({ sequence: ++sequence, stage, durationMs, outcome: outcome === 'error' ? 'error' : 'ok' });
        if (entries.length > 40) entries.shift();
        // Read by the existing opt-in WebView diagnostics; nothing is uploaded or persisted.
        (globalThis as typeof globalThis & { __consumerStartupTimings?: StartupTiming[] }).__consumerStartupTimings = timingSnapshot();
    };
}
export function timingSnapshot(): StartupTiming[] { return entries.map(row => ({ ...row })); }
