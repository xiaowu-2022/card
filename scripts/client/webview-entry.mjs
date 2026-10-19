import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, copyFileSync, existsSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

// Retain the last cookie-compatible entry for already installed native shells.
// Selection happens before either application runs; transports are never mixed.
export const legacyEntry = 'index-CjVTTRnC.js';
export function selectWebviewEntry(search) {
    const params = new URLSearchParams(search);
    return params.get('app_webview') === '1' && params.get('native_auth') !== 'token-v1';
}
export function prepareWebviewEntry(build, retained) {
    const entryPath = resolve(build, 'index.html');
    let html = readFileSync(entryPath, 'utf8');
    const match = html.match(/<script type="module" crossorigin src="([^"<>]+)"><\/script>/);
    if (!match) throw new Error('Missing H5 module entry');
    // Retain the complete previous module graph, including lazy pages. Missing
    // files must fail the build rather than publish a partially working old app.
    const pending = [legacyEntry], seen = new Set();
    mkdirSync(resolve(build, 'assets'), { recursive: true });
    while (pending.length) {
        const name = pending.pop();
        if (seen.has(name)) continue;
        seen.add(name);
        const source = resolve(retained, 'assets', name);
        if (!existsSync(source)) throw new Error('Missing compatible WebView asset: ' + name);
        const body = readFileSync(source, 'utf8');
        for (const dependency of body.matchAll(/["']\.\/([^"'/?#]+\.(?:js|css))["']/g)) pending.push(dependency[1]);
        const destination = resolve(build, 'assets', name);
        if (source !== destination && !existsSync(destination)) copyFileSync(source, destination);
    }
    const current = match[1].split('/').at(-1);
    if (/^webview-entry-/.test(current)) return;
    const script = `const legacy=(${selectWebviewEntry.toString()})(location.search);\nimport(legacy?'./${legacyEntry}':${JSON.stringify('./' + current)});\n`;
    const name = 'webview-entry-' + createHash('sha256').update(script).digest('hex').slice(0, 12) + '.js';
    writeFileSync(resolve(build, 'assets', name), script);
    html = html.replace(match[0], match[0].replace(match[1], match[1].replace(current, name)));
    writeFileSync(entryPath, html);
}
