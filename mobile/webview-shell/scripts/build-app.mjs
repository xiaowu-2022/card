import { mkdirSync, existsSync, readFileSync, renameSync, writeFileSync, openSync, closeSync, unlinkSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const read = (path) => JSON.parse(readFileSync(resolve(root, path), 'utf8'));
const source = read('src/manifest.json');
const version = String(source.versionName ?? '');
const code = String(source.versionCode ?? '');
if (!/^\d+\.\d+\.\d+$/.test(version) || !/^[1-9]\d*$/.test(code) || !source.appid) {
    throw new Error('Set a valid versionName, positive versionCode and appid in src/manifest.json.');
}
if (Number(process.versions.node.split('.')[0]) < 22) throw new Error('Use Node.js 22 or later.');
mkdirSync(resolve(root, 'dist'), { recursive: true });
const lock = resolve(root, 'dist/.resource-build.lock');
let handle;
try { handle = openSync(lock, 'wx'); }
catch { throw new Error('Another resource build is running; do not build or cloud-package concurrently.'); }
try {
    // Keep recoverable artifacts, including any cached signing material. Never print their content.
    const archive = resolve(root, 'dist/archive', new Date().toISOString().replaceAll(':', '-') + '-' + process.pid);
    for (const relative of ['build/app', 'build/app-plus', 'cache/ipa', 'cache/apk', 'cache/wgt']) {
        const old = resolve(root, 'dist', relative);
        if (!existsSync(old)) continue;
        mkdirSync(archive, { recursive: true, mode: 0o700 });
        renameSync(old, resolve(archive, relative.replaceAll('/', '-')));
        console.log(`Archived dist/${relative}`);
    }
    const output = resolve(root, 'dist/build/app-plus');
    // Explicit output wins over inherited HBuilder/terminal output settings.
    const result = spawnSync(process.execPath, [resolve(root, 'node_modules/@dcloudio/vite-plugin-uni/bin/uni.js'), 'build', '-p', 'app', '--outDir', output], {
        cwd: root,
        env: { ...process.env, UNI_INPUT_DIR: resolve(root, 'src'), UNI_OUTPUT_DIR: output, NODE_ENV: 'production' },
        stdio: 'inherit',
    });
    if (result.error || result.status !== 0) throw new Error('Resource compilation failed; do not cloud-package this build.');
    const latest = read('src/manifest.json');
    const built = read('dist/build/app-plus/manifest.json');
    if (latest.appid !== source.appid || String(latest.versionName) !== version || String(latest.versionCode) !== code
        || built.id !== source.appid || String(built.version?.name) !== version || String(built.version?.code) !== code) {
        throw new Error('Source and compiled versions differ; do not cloud-package.');
    }
    writeFileSync(resolve(root, 'dist/resource-build.json'), JSON.stringify({ appId: source.appid, version, code, output: 'dist/build/app-plus', builtAt: new Date().toISOString() }, null, 2) + '\n');
    console.log(`Verified App resources: ${version} (${code}), ${source.appid}`);
    console.log('Cloud-package mobile/webview-shell in HBuilderX. Then run npm run check:ipa -- <IPA path>.');
    console.log('This command compiles resources only; no signed IPA/APK was created.');
} finally {
    closeSync(handle);
    unlinkSync(lock);
}
