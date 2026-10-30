const pendingStatuses = new Set(['QUEUED', 'PROCESSING', 'WAITING_REVIEW']);

// One mounted KYC page owns its reads. Never repeat an upload or review action.
export function createKycStatusRefresh(options: {
    status: () => string | null | undefined;
    busy: () => boolean;
    scope: () => string | null;
    reload: (isCurrent: () => boolean) => Promise<void>;
}) {
    const scope = options.scope();
    let visible = false, shown = false, disposed = false, epoch = 0;
    let wasBusy = options.busy(), attempts = 0, failures = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let flight: Promise<void> | null = null, resumeQueued = false;
    const pending = () => pendingStatuses.has(options.status() ?? '');
    const available = () => !disposed && visible && !!scope && scope === options.scope() && !options.busy();
    function clearTimer() {
        if (timer !== undefined) clearTimeout(timer);
        timer = undefined;
    }
    function schedule() {
        clearTimer();
        if (!available() || !pending() || flight || attempts >= 60) return;
        // About 20 minutes: 30 reads at 10s, then 30 at 30s. Failures back off to 60s.
        const delay = attempts < 30 ? 10000 : 30000;
        timer = setTimeout(() => { timer = undefined; void refresh(false); }, Math.min(60000, delay * 2 ** failures));
    }
    function sync() {
        const busy = options.busy();
        if ((busy && !wasBusy) || !pending() || scope !== options.scope()) epoch++;
        wasBusy = busy;
        schedule();
    }
    function refresh(manual = true): Promise<void> {
        if (!available()) return Promise.resolve();
        if (flight) return flight;
        if (!manual && (!pending() || attempts >= 60)) return Promise.resolve();
        clearTimer();
        if (manual) { attempts = 0; failures = 0; }
        attempts++;
        const current = epoch;
        const isCurrent = () => current === epoch && available();
        const promise = Promise.resolve().then(async () => {
            try {
                if (!isCurrent()) return;
                await options.reload(isCurrent);
                if (isCurrent()) failures = 0;
            } catch {
                // Keep the mounted page and drafts after a transient read failure.
                if (isCurrent()) failures = Math.min(failures + 1, 3);
            } finally {
                if (flight === promise) flight = null;
                if (resumeQueued && available() && pending()) {
                    resumeQueued = false;
                    void refresh(false);
                } else schedule();
            }
        });
        flight = promise;
        return promise;
    }
    function setVisible(value: boolean) {
        if (disposed || visible === value) return;
        visible = value;
        epoch++;
        clearTimer();
        if (!value) { resumeQueued = false; return; }
        attempts = 0;
        failures = 0;
        if (shown && pending()) {
            if (flight) resumeQueued = true;
            else void refresh(false);
        }
        else schedule();
        shown = true;
    }
    function dispose() { disposed = true; epoch++; clearTimer(); }
    return { refresh, sync, setVisible, dispose };
}
