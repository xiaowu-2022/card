// Loopback-only preview of compiled H5; Laravel continues to own API/cookies/CSRF.
import http from 'node:http';
import https from 'node:https';
import { readFileSync, statSync, createReadStream } from 'node:fs';
import { resolve, extname, sep } from 'node:path';
const root = process.cwd();
const company = JSON.parse(
    readFileSync(resolve(root, 'mobile/uni-app/src/generated/company.json'), 'utf8'),
);
const target = new URL(company.apiOrigin);
const build = resolve(root, process.env.UNI_PREVIEW_DIR ?? 'dist/clients/local/debug/h5');
const gallery = resolve(root, 'artifacts/uni-parity');
const types = {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript',
    '.css': 'text/css',
    '.json': 'application/json',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.webp': 'image/webp',
    '.woff2': 'font/woff2',
};
http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (/^\/(api\/v1|storage|images)(\/|$)/.test(url.pathname)) {
        const upstream = (target.protocol === 'https:' ? https : http).request(
            new URL(req.url, target),
            { method: req.method, headers: { ...req.headers, host: target.host } },
            (r) => {
                res.writeHead(r.statusCode ?? 502, r.headers);
                r.pipe(res);
            },
        );
        upstream.on('error', () => {
            res.writeHead(502);
            res.end('Local API unavailable');
        });
        req.pipe(upstream);
        return;
    }
    if (!['GET', 'HEAD'].includes(req.method)) {
        res.writeHead(405);
        res.end();
        return;
    }
    const isGallery = url.pathname.startsWith('/__parity/'),
        base = isGallery ? gallery : build;
    let path;
    try {
        path = resolve(
            base,
            '.' +
                decodeURIComponent(
                    isGallery ? url.pathname.slice('/__parity'.length) : url.pathname,
                ),
        );
    } catch {
        res.writeHead(400);
        res.end();
        return;
    }
    if (path !== base && !path.startsWith(base + sep)) {
        res.writeHead(404);
        res.end();
        return;
    }
    try {
        if (statSync(path).isDirectory()) path = resolve(path, 'index.html');
    } catch {
        if (!isGallery && !extname(path)) path = resolve(build, 'index.html');
        else {
            res.writeHead(404);
            res.end();
            return;
        }
    }
    try {
        const stat = statSync(path);
        res.writeHead(200, {
            'content-type': types[extname(path)] ?? 'application/octet-stream',
            'content-length': stat.size,
            'cache-control': 'no-store',
        });
        if (req.method === 'HEAD') res.end();
        else createReadStream(path).pipe(res);
    } catch {
        res.writeHead(404);
        res.end();
    }
}).listen(Number(process.env.UNI_PREVIEW_PORT ?? 5202), '127.0.0.1', () =>
    console.log(
        'Compiled H5: http://127.0.0.1:' +
            (process.env.UNI_PREVIEW_PORT ?? 5202) +
            ' · gallery: /__parity/index.html',
    ),
);
