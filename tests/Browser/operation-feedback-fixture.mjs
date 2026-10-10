// Offline UI fixture. Build and serve, then inspect /platform/feedback in the browser.
// No app cookies, provider calls, or business writes are used.
import { build } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { resolve, extname } from 'node:path';
const out = resolve('artifacts/uni-parity/operation-feedback-fixture');
await build({
    configFile: false,
    root: resolve('tests/Browser/fixtures/operation-feedback'),
    base: '/',
    plugins: [react(), tailwindcss()],
    resolve: { alias: { '@': resolve('resources/js') } },
    build: { outDir: out, emptyOutDir: true },
});
if (!process.argv.includes('--build-only'))
    createServer(async (req, res) => {
        try {
            const url = new URL(req.url, 'http://localhost');
            const path = resolve(
                out,
                '.' + (url.pathname.startsWith('/platform/') ? '/index.html' : url.pathname),
            );
            if (!path.startsWith(out + '/')) {
                res.writeHead(403).end();
                return;
            }
            const body = await readFile(path);
            res.setHeader(
                'Content-Type',
                { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' }[
                    extname(path)
                ] ?? 'application/octet-stream',
            );
            res.end(body);
        } catch {
            res.writeHead(404).end();
        }
    }).listen(5188, '127.0.0.1', () =>
        console.log('Offline fixture: http://127.0.0.1:5188/platform/feedback'),
    );
