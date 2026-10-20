export type IOSPackage = {file: string; title: string; version: string; build: string; size: number; downloadUrl: string};
export function iosManifestUrl(origin: string, file: string) {
    const url = new URL('/ipa/index.php', origin);
    if (url.protocol !== 'https:') throw new Error('HTTPS required');
    url.searchParams.set('manifest', file);
    return url.href;
}
export async function loadIOSPackages(signal: AbortSignal): Promise<IOSPackage[]> {
    const response = await fetch('/ipa/index.php?format=json', {credentials:'omit', cache:'no-store', signal, headers:{Accept:'application/json'}});
    if (!response.ok) throw new Error('Distribution unavailable');
    const value = await response.json();
    if (!Array.isArray(value.packages)) throw new Error('Invalid distribution');
    return value.packages.map((p: IOSPackage) => {
        if (!p || typeof p.file !== 'string' || !/^[^/\\\x00-\x1f]+\.ipa$/i.test(p.file)
            || typeof p.title !== 'string' || typeof p.version !== 'string' || typeof p.build !== 'string'
            || !Number.isSafeInteger(p.size) || p.size <= 0) throw new Error('Invalid package');
        const download = new URL(p.downloadUrl);
        if (download.protocol !== 'https:' || download.username || download.password) throw new Error('Invalid download');
        return p;
    });
}
