import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { createHash } from 'node:crypto';
import sharp from 'sharp';

async function read(url, max, fetcher, development, redirects = 0) {
    const parsed = new URL(url);
    if (parsed.username || parsed.password || (parsed.protocol !== 'https:' && !(development && parsed.protocol === 'http:'))) throw new Error('Invalid branding URL');
    const response = await fetcher(url, { redirect: 'manual', signal: AbortSignal.timeout(12000), headers: { Accept: '*/*' } });
    if ([301, 302, 303, 307, 308].includes(response.status)) {
        if (redirects >= 3 || !response.headers.get('location')) throw new Error('Branding redirect limit');
        await response.body?.cancel();
        return read(new URL(response.headers.get('location'), url).href, max, fetcher, development, redirects + 1);
    }
    if (!response.ok || Number(response.headers.get('content-length')) > max) throw new Error('Branding response unavailable or too large');
    const chunks = []; let length = 0;
    for await (const chunk of response.body) {
        length += chunk.length;
        if (length > max) throw new Error('Branding response too large');
        chunks.push(chunk);
    }
    return Buffer.concat(chunks);
}

export async function prepareBranding(config, project, fetcher = fetch) {
    let logo, source;
    const failures = [];
    for (const origin of config.apiOrigins) {
        if (new URL(origin).hostname === 'zb33333.com') continue;
        try {
            const data = JSON.parse((await read(origin + '/api/mobile/v1/bootstrap', 1024 * 1024, fetcher, config.developmentOnly)).toString());
            if (data?.tenant?.slug !== config.tenantSlug || typeof data.tenant.id !== 'string' || !data.tenant.id || typeof data.tenant.apkLogoUrl !== 'string' || !data.tenant.apkLogoUrl) throw new Error('Company identity or logo missing');
            const url = new URL(data.tenant.apkLogoUrl, origin).href;
            const bytes = await read(url, 8 * 1024 * 1024, fetcher, config.developmentOnly);
            const metadata = await sharp(bytes, { limitInputPixels: 25000000 }).metadata();
            if (!['png', 'jpeg', 'webp'].includes(metadata.format) || metadata.pages > 1 || !metadata.width || metadata.width < 192 || metadata.width !== metadata.height) throw new Error('Logo must be a static PNG/JPEG/WebP');
            logo = bytes;
            source = { tenantSlug: config.tenantSlug, apiOrigin: origin, sha256: createHash('sha256').update(bytes).digest('hex') };
            break;
        } catch {
            // Do not expose signed image URLs or bootstrap response content.
            failures.push(origin);
        }
    }
    if (!logo) throw new Error(`Cannot load configured APK logo from API servers: ${failures.join(', ')}. Check company slug, backend logo and network. Native packaging stopped.`);
    // HBuilderX packaging resolves manifest icon/splash paths from the CLI
    // project root, not the directory containing src/manifest.json.
    const relative = 'src/static/native-branding';
    const output = resolve(project, relative);
    await mkdir(output, { recursive: true });
    const android = {};
    for (const [density, size] of Object.entries({ mdpi: 48, hdpi: 72, xhdpi: 96, xxhdpi: 144, xxxhdpi: 192 })) {
        const name = `icon-${density}.png`;
        await sharp(logo).rotate().resize(size, size, { fit: 'contain', background: '#ffffff' }).flatten({ background: '#ffffff' }).png().toFile(resolve(output, name));
        android[density] = `${relative}/${name}`;
    }
    const splash = {};
    for (const [density, [width, height]] of Object.entries({ hdpi: [480, 762], xhdpi: [720, 1242], xxhdpi: [1080, 1882] })) {
        const size = Math.round(width * 0.42);
        const center = await sharp(logo).rotate().resize(size, size, { fit: 'contain', background: '#ffffff' }).flatten({ background: '#ffffff' }).png().toBuffer();
        const name = `splash-${density}.png`;
        await sharp({ create: { width, height, channels: 3, background: '#ffffff' } }).composite([{ input: center, gravity: 'centre' }]).png().toFile(resolve(output, name));
        splash[density] = `${relative}/${name}`;
    }
    await writeFile(resolve(output, 'source.json'), JSON.stringify(source, null, 2));
    return { icons: { android }, splashscreen: { androidStyle: 'default', android: splash } };
}
