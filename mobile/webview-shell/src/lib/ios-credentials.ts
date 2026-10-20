import { validToken } from './token-session';
export type IOSBridge = {
    invoke(target: unknown, selector: string, ...args: unknown[]): any;
    newObject(name: string, ...args: unknown[]): any;
    deleteObject(object: unknown): void;
};
// Foundation protected file storage; never use uni storage for plaintext tokens.
export function iosCredentialVault(bridge: IOSBridge, path: string, scope: string) {
    const call = (target: unknown, selector: string, ...args: unknown[]) => bridge.invoke(target, selector, ...args);
    const manager = () => call('NSFileManager', 'defaultManager');
    const protection = 'NSFileProtectionCompleteUntilFirstUserAuthentication';
    function read(): string | null {
        if (!call(manager(), 'fileExistsAtPath:', path)) return null;
        const attrs = call(manager(), 'attributesOfItemAtPath:error:', path, null);
        const actual = call(attrs, 'objectForKey:', 'NSFileProtectionKey');
        // Native.js may return an NSString handle instead of a JS string.
        const equal = typeof actual === 'string' ? actual === protection
            : actual != null && call(actual, 'isEqualToString:', protection);
        const matches = equal === true || equal === 1;
        if (!matches) throw new Error('Protected credential storage unavailable');
        const text = call('NSString', 'stringWithContentsOfFile:encoding:error:', path, 4, null);
        if (typeof text !== 'string') throw new Error('Protected credential storage locked');
        const record = JSON.parse(text);
        if (record.scope !== scope || !validToken(record.token)) throw new Error('Invalid protected credential');
        return record.token as string;
    }
    return {
        read,
        write(value: string | null) {
            const fs = manager();
            if (value === null) {
                if (call(fs, 'fileExistsAtPath:', path) && !call(fs, 'removeItemAtPath:error:', path, null)) throw new Error('Protected credential save failed');
                return;
            }
            if (!validToken(value)) throw new Error('Invalid protected credential');
            const text = bridge.newObject('NSString', JSON.stringify({ scope, token: value }));
            const data = call(text, 'dataUsingEncoding:', 4);
            bridge.deleteObject(text);
            // Atomic write plus complete-until-first-authentication protection (0x40000000).
            if (!data || !call(data, 'writeToFile:options:error:', path, 0x40000001, null)) throw new Error('Protected credential save failed');
            const url = call('NSURL', 'fileURLWithPath:', path);
            const yes = call('NSNumber', 'numberWithBool:', true);
            if (!call(url, 'setResourceValue:forKey:error:', yes, 'NSURLIsExcludedFromBackupKey', null)) {
                call(fs, 'removeItemAtPath:error:', path, null);
                throw new Error('Protected credential backup exclusion failed');
            }
            if (read() !== value) throw new Error('Protected credential save failed');
        },
    };
}
