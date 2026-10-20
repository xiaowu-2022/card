// Native handles are deliberately opaque. No plaintext fallback is allowed.
export type AndroidBridge = {
    invoke(target: unknown, method: string, ...args: unknown[]): any;
    newObject(name: string, ...args: unknown[]): any;
};
export function androidCredentialVault(bridge: AndroidBridge, scope: string,
    storage: { get(): unknown; set(value: string): void; remove(): void }, valid: (value: string) => boolean) {
    const call = (target: unknown, method: string, ...args: unknown[]) => bridge.invoke(target, method, ...args);
    const alias = 'specpay:' + scope;
    function key(create: boolean) {
        const store = call('java.security.KeyStore', 'getInstance', 'AndroidKeyStore');
        call(store, 'load', null);
        if (!call(store, 'containsAlias', alias)) {
            if (!create) throw new Error('Credential key unavailable');
            const builder = bridge.newObject('android.security.keystore.KeyGenParameterSpec$Builder', alias, 3);
            call(builder, 'setBlockModes', ['GCM']);
            call(builder, 'setEncryptionPaddings', ['NoPadding']);
            const generator = call('javax.crypto.KeyGenerator', 'getInstance', 'AES', 'AndroidKeyStore');
            call(generator, 'init', call(builder, 'build'));
            if (!call(generator, 'generateKey')) throw new Error('Credential key unavailable');
        }
        const result = call(store, 'getKey', alias, null);
        if (!result) throw new Error('Credential key unavailable');
        return result;
    }
    const bytes = (value: string) => call(bridge.newObject('java.lang.String', value), 'getBytes', 'UTF-8');
    const encode = (value: unknown) => call('android.util.Base64', 'encodeToString', value, 2);
    const decode = (value: string) => call('android.util.Base64', 'decode', value, 2);
    function decrypt(raw: string) {
        const record = JSON.parse(raw);
        if (record.v !== 1 || typeof record.iv !== 'string' || typeof record.data !== 'string') throw new Error('Invalid credential record');
        const cipher = call('javax.crypto.Cipher', 'getInstance', 'AES/GCM/NoPadding');
        call(cipher, 'init', 2, key(false), bridge.newObject('javax.crypto.spec.GCMParameterSpec', 128, decode(record.iv)));
        call(cipher, 'updateAAD', bytes(scope));
        const decoded = call(cipher, 'doFinal', decode(record.data));
        if (!decoded) throw new Error('Credential decrypt failed');
        const value = String(call(bridge.newObject('java.lang.String', decoded, 'UTF-8'), 'toString'));
        if (!valid(value)) throw new Error('Invalid credential');
        return value;
    }
    return {
        read(): string | null {
            const raw = storage.get();
            if (raw === '' || raw == null) return null;
            if (typeof raw !== 'string') throw new Error('Invalid credential record');
            return decrypt(raw);
        },
        write(value: string | null) {
            if (value === null) { storage.remove(); return; }
            if (!valid(value)) throw new Error('Invalid credential');
            const cipher = call('javax.crypto.Cipher', 'getInstance', 'AES/GCM/NoPadding');
            call(cipher, 'init', 1, key(true));
            call(cipher, 'updateAAD', bytes(scope));
            const data = encode(call(cipher, 'doFinal', bytes(value)));
            const iv = encode(call(cipher, 'getIV'));
            if (typeof data !== 'string' || typeof iv !== 'string') throw new Error('Credential encrypt failed');
            const raw = JSON.stringify({ v: 1, iv, data });
            if (decrypt(raw) !== value) throw new Error('Credential verification failed');
            storage.set(raw);
            if (storage.get() !== raw) throw new Error('Credential save failed');
        },
    };
}
