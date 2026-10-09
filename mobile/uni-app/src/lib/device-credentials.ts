// Only ciphertext reaches app storage. The AES key never leaves AndroidKeyStore.
// Native.js: https://www.html5plus.org/doc/zh_cn/android.html
// Android: https://developer.android.com/reference/android/security/keystore/KeyGenParameterSpec
export type AndroidBridge = {
    invoke(object: unknown, method: string, ...args: unknown[]): unknown;
    newObject(className: string, ...args: unknown[]): unknown;
};
export function androidCredentialVault(android: AndroidBridge, scope: string, storage: {
    get(): unknown; set(value: string): void; remove(): void;
}, valid: (value: string) => boolean = value => /^[1-9][0-9]*\|[A-Za-z0-9]{64}$/.test(value)) {
    const call = (object: unknown, method: string, ...args: unknown[]) => android.invoke(object, method, ...args);
    const required = (value: unknown) => {
        if (value === undefined || value === null) throw new Error('Secure credential storage unavailable');
        return value;
    };
    const alias = 'consumer-session-v1:' + scope;
    const bytes = (value: string) => required(call(android.newObject('java.lang.String', value), 'getBytes', 'UTF-8'));
    const base64 = (value: unknown) => String(required(call('android.util.Base64', 'encodeToString', value, 2)));
    const decode = (value: string) => required(call('android.util.Base64', 'decode', value, 2));
    function key(create: boolean) {
        const store = required(call('java.security.KeyStore', 'getInstance', 'AndroidKeyStore'));
        call(store, 'load', null);
        if (call(store, 'containsAlias', alias) !== true) {
            if (!create) throw new Error('Secure credential unavailable');
            const builder = required(android.newObject('android.security.keystore.KeyGenParameterSpec$Builder', alias, 3));
            required(call(builder, 'setBlockModes', ['GCM']));
            required(call(builder, 'setEncryptionPaddings', ['NoPadding']));
            required(call(builder, 'setKeySize', 256));
            const generator = required(call('javax.crypto.KeyGenerator', 'getInstance', 'AES', 'AndroidKeyStore'));
            call(generator, 'init', required(call(builder, 'build')));
            required(call(generator, 'generateKey'));
        }
        return required(call(store, 'getKey', alias, null));
    }
    function decrypt(value: string): string {
        const record = JSON.parse(value) as { v?: number; iv?: string; ciphertext?: string };
        if (record.v !== 1 || !record.iv || !record.ciphertext) throw new Error('Invalid credential');
        const cipher = required(call('javax.crypto.Cipher', 'getInstance', 'AES/GCM/NoPadding'));
        const params = required(android.newObject('javax.crypto.spec.GCMParameterSpec', 128, decode(record.iv)));
        call(cipher, 'init', 2, key(false), params);
        call(cipher, 'updateAAD', bytes(scope));
        const clear = required(call(cipher, 'doFinal', decode(record.ciphertext)));
        return String(required(call(android.newObject('java.lang.String', clear, 'UTF-8'), 'toString')));
    }
    return {
        read(): string | null {
            const value = storage.get();
            if (!value) return null;
            try {
                const result = decrypt(String(value));
                if (!valid(result)) throw new Error('Invalid credential');
                return result;
            } catch {
                storage.remove();
                return null;
            }
        },
        write(value: string | null) {
            storage.remove();
            if (value === null) return;
            if (!valid(value)) throw new Error('Invalid credential');
            const cipher = required(call('javax.crypto.Cipher', 'getInstance', 'AES/GCM/NoPadding'));
            call(cipher, 'init', 1, key(true));
            call(cipher, 'updateAAD', bytes(scope));
            const ciphertext = required(call(cipher, 'doFinal', bytes(value)));
            const record = JSON.stringify({ v: 1, iv: base64(required(call(cipher, 'getIV'))), ciphertext: base64(ciphertext) });
            // Verify the bridge on this device before promising persistent sign-in.
            if (decrypt(record) !== value) throw new Error('Secure credential storage unavailable');
            storage.set(record);
            if (storage.get() !== record) throw new Error('Secure credential storage unavailable');
        },
    };
}
