import { origin, type DirectoryCache } from './directory';
import { LoginRestoreUnavailable } from './shared-session';

export const validToken = (value: string) => /^[1-9][0-9]{0,17}\|[A-Za-z0-9]{64}$/.test(value);
export type Identity = { tenantId: string; signedIn: boolean; restricted: boolean };
export function tokenSession(ports: {
    vault: { read(): string | null; write(value: string | null): void };
    metadata: { get(): { signedIn: boolean; recovering?: boolean } | null; set(value: { signedIn: boolean; recovering?: boolean }): void };
    verify(url: string, token: string | null): Promise<Identity>;
    open(url: string): Promise<string>;
    claim(url: string, channel: string, revision: number): Promise<string>;
}) {
    let directory: DirectoryCache | null = null, credential: string | null = null;
    let initialized = false, epoch = 0;
    let captureStage: 'claim' | 'verify' | 'storage' | 'ready' = 'claim';
    let mailbox: { url: string; channel: string; savedRevision: number } | null = null;
    let captureTask: { url: string; channel: string; revision: number; generation: number;
        current: () => boolean; promise: Promise<boolean> } | null = null;
    const readMetadata = () => {
        try { return ports.metadata.get(); }
        catch { throw Object.assign(new LoginRestoreUnavailable(), { storageCode: 'SAVE-META-READ' }); }
    };
    const saveMetadata = (value: { signedIn: boolean; recovering?: boolean }, code: string) => {
        try {
            ports.metadata.set(value);
            const restored = ports.metadata.get();
            if (restored?.signedIn !== value.signedIn || !!restored?.recovering !== !!value.recovering) throw new Error('Metadata readback mismatch');
        }
        catch { throw Object.assign(new Error('Login metadata storage failed'), { storageCode: code }); }
    };
    const trusted = (url: string) => !!directory && directory.origins.includes(url) && origin(url) === url;
    function check(value: Identity, token: string | null) {
        if (value.tenantId !== directory?.tenantId || typeof value.signedIn !== 'boolean'
            || typeof value.restricted !== 'boolean' || (value.signedIn && !token)) throw Object.assign(new LoginRestoreUnavailable(), { diagnostic: 'identity' });
        return value;
    }
    function clear() {
        epoch++;
        credential = null;
        // Mark explicit logout first; no later in-flight capture may resurrect it.
        saveMetadata({ signedIn: false }, 'SAVE-META-LOGOUT');
        ports.vault.write(null);
    }
    return {
        async configure(value: DirectoryCache) {
            if (directory && directory.tenantId !== value.tenantId) throw new Error('Company changed');
            directory = value;
            if (initialized) return;
            const meta = readMetadata();
            if (meta?.recovering) throw Object.assign(new LoginRestoreUnavailable(), { storageCode: 'SAVE-RECOVERY-PENDING' });
            credential = meta?.signedIn === false ? null : ports.vault.read();
            if (credential && !validToken(credential)) throw new LoginRestoreUnavailable();
            if (!credential && meta?.signedIn) throw Object.assign(new LoginRestoreUnavailable(), { storageCode: 'SAVE-CREDENTIAL-MISSING' });
            initialized = true;
        },
        expectedSignedIn: () => !!credential || readMetadata()?.signedIn === true,
        pending() {
            epoch++;
            credential = null;
            captureStage = 'storage';
            saveMetadata({ signedIn: true, recovering: true }, 'SAVE-META-PENDING');
        },
        clear,
        captureStage: () => captureStage,
        async restore(url: string) {
            if (!trusted(url)) throw new Error('Unverified login destination');
            if (!credential && readMetadata()?.signedIn) throw new LoginRestoreUnavailable();
            const generation = ++epoch;
            // Both requests are scoped to the verified host and are independent.
            const [verified, channel] = await Promise.all([ports.verify(url, credential), ports.open(url)]);
            const identity = check(verified, credential);
            if (generation !== epoch) throw new LoginRestoreUnavailable();
            if (!identity.signedIn && credential) clear();
            if (!/^[A-Za-z0-9]{64}$/.test(channel)) throw new LoginRestoreUnavailable();
            mailbox = { url, channel, savedRevision: 0 };
            return identity;
        },
        script(url: string, documentId: string) {
            if (!trusted(url) || mailbox?.url !== url) throw new LoginRestoreUnavailable();
            // A login/account switch is still handing off its new token. Never
            // overwrite that page's bridge with a guest token during this gap.
            if (!credential && readMetadata()?.recovering) return '';
            // The credential travels in a trusted evalJS call only, never a URL,
            // title, diagnostics, page persistence or arbitrary native bridge.
            return `(function(){if(location.origin!==${JSON.stringify(url)}||window.__consumerTokenDocument===${JSON.stringify(documentId)})return;window.__consumerTokenDocument=${JSON.stringify(documentId)};window.__consumerTokenBridge=${JSON.stringify({ channel: mailbox.channel, token: credential, tenantId: directory!.tenantId })};window.dispatchEvent(new Event('consumer-token-bridge'));})();`;
        },
        async capture(url: string, revision: number, current: () => boolean) {
            if (!trusted(url) || mailbox?.url !== url || !Number.isSafeInteger(revision) || revision < 1) return false;
            if (revision === mailbox.savedRevision && credential) return current();
            const generation = epoch, channel = mailbox.channel;
            if (captureTask && captureTask.url === url && captureTask.channel === channel
                && captureTask.revision === revision && captureTask.generation === generation) {
                captureTask.current = current;
                return captureTask.promise;
            }
            const task = { url, channel, revision, generation, current, promise: null as unknown as Promise<boolean> };
            const savedCredential = credential;
            captureTask = task;
            task.promise = (async () => {
                // A newer publication may be a different account. Retain encrypted
                // bytes for retry, but prohibit restoring the preceding identity.
                credential = null;
                captureStage = 'storage';
                saveMetadata({ signedIn: true, recovering: true }, 'SAVE-META-PENDING');
                captureStage = 'claim';
                const value = await ports.claim(url, channel, revision);
                if (!task.current() || generation !== epoch || mailbox?.channel !== channel) return false;
                if (!validToken(value)) throw new LoginRestoreUnavailable();
                // An expired/revoked token must not be saved as authenticated.
                captureStage = 'verify';
                const identity = check(await ports.verify(url, value), value);
                if (!identity.signedIn) throw Object.assign(new LoginRestoreUnavailable(), { diagnostic: 'identity' });
                if (!task.current() || generation !== epoch || mailbox?.channel !== channel) return false;
                credential = null;
                captureStage = 'storage';
                saveMetadata({ signedIn: true, recovering: true }, 'SAVE-META-PENDING');
                // An unchanged, already-protected credential still gets a fresh
                // identity check above, but does not need another encryption/write.
                if (value !== savedCredential) {
                    try { ports.vault.write(value); }
                    catch (error) {
                        // Preserve fixed native diagnoses; never surface arbitrary exception text.
                        if (error instanceof Error && ('storageCode' in error || 'iosStep' in error || error.message.startsWith('Protected credential') || error.message === 'Invalid protected credential')) throw error;
                        throw Object.assign(new Error('Credential vault failed'), { storageCode: 'SAVE-VAULT-UNEXPECTED' });
                    }
                }
                saveMetadata({ signedIn: true }, 'SAVE-META-COMMIT');
                credential = value;
                mailbox.savedRevision = revision;
                captureStage = 'ready';
                return true;
            })();
            try { return await task.promise; }
            finally { if (captureTask === task) captureTask = null; }
        },
    };
}
