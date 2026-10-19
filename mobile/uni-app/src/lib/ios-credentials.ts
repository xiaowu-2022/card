// Native private file encrypted by iOS Data Protection, accessible only while
// unlocked. No credential is stored in JS preferences, WebView storage or cookies.
export type IOSBridge = { invoke(object: unknown, method: string, ...args: unknown[]): unknown; deleteObject(object: unknown): void };
export function iosCredentialVault(ios: IOSBridge, path: string, scope: string) {
    const call = (object: unknown, method: string, ...args: unknown[]) => ios.invoke(object, method, ...args);
    const valid = (value: unknown): value is string => typeof value === 'string' && /^[1-9][0-9]{0,17}\|[A-Za-z0-9]{64}$/.test(value);
    return {
        read(): string | null {
            const manager = call('NSFileManager', 'defaultManager');
            if (call(manager, 'fileExistsAtPath:', path) !== true) return null;
            const attributes = call(manager, 'attributesOfItemAtPath:error:', path, null);
            if (String(call(attributes, 'objectForKey:', 'NSFileProtectionKey')) !== 'NSFileProtectionComplete')
                throw new Error('Protected credential storage unavailable');
            const text = call('NSString', 'stringWithContentsOfFile:encoding:error:', path, 4, null);
            if (text === null || text === undefined) throw new Error('Protected credential storage locked');
            const record = JSON.parse(String(text));
            if (record.scope !== scope || !valid(record.token)) throw new Error('Invalid protected credential');
            return record.token;
        },
        write(value: string | null) {
            const manager = call('NSFileManager', 'defaultManager');
            if (value === null) {
                if (call(manager, 'fileExistsAtPath:', path) === true && call(manager, 'removeItemAtPath:error:', path, null) !== true)
                    throw new Error('Protected credential removal failed');
                return;
            }
            if (!valid(value)) throw new Error('Invalid credential');
            const record = call('NSMutableDictionary', 'dictionary');
            let data: unknown, url: unknown;
            try {
                call(record, 'setObject:forKey:', scope, 'scope');
                call(record, 'setObject:forKey:', value, 'token');
                data = call('NSJSONSerialization', 'dataWithJSONObject:options:error:', record, 0, null);
                // NSDataWritingAtomic | NSDataWritingFileProtectionComplete.
                if (call(data, 'writeToFile:options:error:', path, 0x20000001, null) !== true)
                    throw new Error('Protected credential save failed');
                const attributes = call(manager, 'attributesOfItemAtPath:error:', path, null);
                if (String(call(attributes, 'objectForKey:', 'NSFileProtectionKey')) !== 'NSFileProtectionComplete') {
                    call(manager, 'removeItemAtPath:error:', path, null);
                    throw new Error('Protected credential save unavailable');
                }
                url = call('NSURL', 'fileURLWithPath:', path);
                if (call(url, 'setResourceValue:forKey:error:', true, 'NSURLIsExcludedFromBackupKey', null) !== true) {
                    call(manager, 'removeItemAtPath:error:', path, null);
                    throw new Error('Protected credential backup exclusion failed');
                }
            } finally {
                for (const object of [record, data, url]) if (object) ios.deleteObject(object);
            }
        },
    };
}
