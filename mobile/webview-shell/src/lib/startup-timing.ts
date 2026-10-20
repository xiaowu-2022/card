type Timing = { sequence: number; stage: string; durationMs: number; outcome: string };
const rows: Timing[] = [];
let sequence = 0;
export function beginTiming(stage: string) {
    const start = Date.now();
    let finished = false;
    return (outcome = 'ok') => {
        if (finished) return;
        finished = true;
        const row = { sequence: ++sequence, stage, durationMs: Date.now() - start, outcome };
        rows.push(row);
        if (import.meta.env.DEV) console.info(`[shell] ${stage}: ${row.durationMs}ms (${outcome})`);
        if (rows.length > 60) rows.shift();
    };
}
export const timingSnapshot = () => rows.map(row => ({ ...row }));
