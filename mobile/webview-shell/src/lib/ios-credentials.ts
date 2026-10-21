import { validToken } from './token-session';
export type IOSBridge = {
    invoke(target: unknown, selector: string, ...args: unknown[]): any;
    newObject(name: string, ...args: unknown[]): any;
    deleteObject(object: unknown): void;
};
// Foundation protected file storage; never use uni storage for plaintext tokens.
export function iosCredentialVault(bridge: IOSBridge, path: string, scope: string) {
    const fail = (code: string, message: string): never => {
        throw Object.assign(new Error(message), { storageCode: code });
    };
    const call = (target: unknown, selector: string, ...args: unknown[]) => {
        try { return bridge.invoke(target, selector, ...args); }
        catch {
            const steps: Record<string, string> = { defaultManager: 'MANAGER', 'fileExistsAtPath:': 'EXISTS', 'removeItemAtPath:error:': 'DELETE',
                'fileURLWithPath:': 'URL', 'numberWithBool:': 'BOOL', dictionary: 'CREATE', 'setObject:forKey:': 'CREATE',
                'dataWithJSONObject:options:error:': 'ENCODE', 'writeToFile:options:error:': 'WRITE',
                'attributesOfItemAtPath:error:': 'ATTRIBUTES', 'objectForKey:': 'ATTRIBUTES',
                'isEqualToString:': 'COMPARE', 'stringWithContentsOfFile:encoding:error:': 'READ',
                'setResourceValue:forKey:error:': 'BACKUP' };
            throw Object.assign(new Error('Protected credential native bridge failed'), { iosStep: steps[selector] });
        }
    };
    const manager = () => {
        const fs = call('NSFileManager', 'defaultManager');
        if (!fs) fail('IOS-MANAGER-EMPTY', 'Protected credential manager unavailable');
        return fs;
    };
    const truth = (value: unknown) => value === true || value === 1;
    const exists = (fs: unknown) => {
        const result = call(fs, 'fileExistsAtPath:', path);
        if (![true, false, 0, 1].includes(result)) fail('IOS-EXISTS-INVALID', 'Protected credential existence unavailable');
        return truth(result);
    };
    const protection = 'NSFileProtectionCompleteUntilFirstUserAuthentication';
    function read(): string | null {
        if (!exists(manager())) return null;
        const attrs = call(manager(), 'attributesOfItemAtPath:error:', path, null);
        if (!attrs) fail('IOS-ATTRIBUTES-EMPTY', 'Protected credential attributes unavailable');
        const actual = call(attrs, 'objectForKey:', 'NSFileProtectionKey');
        if (actual == null) fail('IOS-PROTECTION-MISSING', 'Protected credential protection missing');
        // Native.js may return an NSString handle instead of a JS string.
        const equal = typeof actual === 'string' ? actual === protection
            : actual != null && call(actual, 'isEqualToString:', protection);
        const matches = equal === true || equal === 1;
        if (!matches) fail('IOS-PROTECTION-MISMATCH', 'Protected credential storage unavailable');
        const text = call('NSString', 'stringWithContentsOfFile:encoding:error:', path, 4, null);
        if (text == null) fail('IOS-READ-EMPTY', 'Protected credential read unavailable');
        if (typeof text !== 'string') fail('IOS-READ-TYPE', 'Protected credential read type invalid');
        if (text === '') fail('IOS-JSON-EMPTY', 'Protected credential record decode failed');
        let record: {scope?: unknown; token?: unknown} = {};
        try { record = JSON.parse(text); }
        catch { fail('IOS-JSON-PARSE', 'Protected credential record decode failed'); }
        if (!record || typeof record !== 'object' || Array.isArray(record)) fail('IOS-RECORD-SHAPE', 'Invalid protected credential');
        if (record.scope !== scope) fail('IOS-SCOPE-MISMATCH', 'Invalid protected credential');
        if (typeof record.token !== 'string' || !validToken(record.token)) fail('IOS-TOKEN-INVALID', 'Invalid protected credential');
        return record.token as string;
    }
    return {
        read,
        write(value: string | null) {
            const fs = manager();
            if (value === null) {
                if (exists(fs) && !truth(call(fs, 'removeItemAtPath:error:', path, null))) fail('IOS-DELETE-FAILED', 'Protected credential save failed');
                return;
            }
            if (!validToken(value)) fail('IOS-INPUT-TOKEN', 'Invalid protected credential');
            // newObject('NSString', text) does not specify an Objective-C
            // initializer. Build an explicit Foundation record instead, so a
            // default/empty NSString cannot become the saved JSON document.
            const record = call('NSMutableDictionary', 'dictionary');
            if (!record) fail('IOS-CREATE-EMPTY', 'Protected credential record creation failed');
            call(record, 'setObject:forKey:', scope, 'scope');
            call(record, 'setObject:forKey:', value, 'token');
            const data = call('NSJSONSerialization', 'dataWithJSONObject:options:error:', record, 0, null);
            if (!data) fail('IOS-ENCODE-EMPTY', 'Protected credential record encoding failed');
            // Atomic write plus complete-until-first-authentication protection (0x40000000).
            if (!truth(call(data, 'writeToFile:options:error:', path, 0x40000001, null))) fail('IOS-WRITE-FAILED', 'Protected credential save failed');
            const url = call('NSURL', 'fileURLWithPath:', path);
            if (!url) fail('IOS-URL-EMPTY', 'Protected credential URL unavailable');
            const yes = call('NSNumber', 'numberWithBool:', true);
            if (yes == null) fail('IOS-BOOL-EMPTY', 'Protected credential flag unavailable');
            if (!truth(call(url, 'setResourceValue:forKey:error:', yes, 'NSURLIsExcludedFromBackupKey', null))) {
                try {
                    if (!truth(call(fs, 'removeItemAtPath:error:', path, null))) fail('IOS-BACKUP-CLEANUP', 'Protected credential backup exclusion failed');
                } catch { fail('IOS-BACKUP-CLEANUP', 'Protected credential backup exclusion failed'); }
                fail('IOS-BACKUP-FAILED', 'Protected credential backup exclusion failed');
            }
            const restored = read();
            if (restored === null) fail('IOS-READBACK-MISSING', 'Protected credential readback missing');
            if (restored !== value) fail('IOS-READBACK-MISMATCH', 'Protected credential readback mismatch');
        },
    };
}
