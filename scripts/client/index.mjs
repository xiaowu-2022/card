import { spawnSync } from 'node:child_process';
import {
    cpSync,
    existsSync,
    mkdirSync,
    readdirSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { basename, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { readCompany } from './config.mjs';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { icons } from 'lucide-react';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const project = resolve(root, 'mobile/uni-app');
const args = process.argv.slice(2);
const command = args.shift();
const flags = {};
function run(bin, args, cwd = project) {
    const result = spawnSync(bin, args, { cwd, stdio: 'inherit', shell: false });
    if (result.error || result.status !== 0) throw new Error(`${bin} failed; see output above.`);
}
try {
    for (let i = 0; i < args.length; i += 2) {
        if (
            !['--company', '--mode', '--platform'].includes(args[i]) ||
            !args[i + 1] ||
            flags[args[i]]
        )
            throw new Error('Use --company NAME --mode debug|release --platform h5|app.');
        flags[args[i]] = args[i + 1];
    }
    if (Number(process.versions.node.split('.')[0]) < 22)
        throw new Error('Use Node.js 22 or newer.');
    if (!['doctor', 'prepare', 'dev', 'build'].includes(command))
        throw new Error('Commands: doctor, prepare, dev, build.');
    if (command === 'doctor') {
        console.log(`Node ${process.versions.node}`);
        console.log(
            `${existsSync(resolve(project, 'node_modules/@dcloudio/uni-app')) ? 'OK' : 'MISSING'} uni-app dependencies (npm ci --prefix mobile/uni-app)`,
        );
        console.log(
            `${existsSync('/Applications/HBuilderX.app') ? 'FOUND' : 'CHECK'} HBuilderX; cloud packaging requires your DCloud appid and signing certificates.`,
        );
    } else {
        const company = flags['--company'] ?? 'local';
        const mode = flags['--mode'] ?? 'debug';
        const platform = flags['--platform'] ?? 'h5';
        if (!['h5', 'app'].includes(platform)) throw new Error('Platform must be h5 or app.');
        const config = readCompany(root, company, mode);
        const generated = resolve(project, 'src/generated');
        mkdirSync(generated, { recursive: true });
        writeFileSync(resolve(generated, 'company.json'), JSON.stringify(config, null, 2));
        cpSync(
            resolve(root, 'public/data/card-geography/countries.json'),
            resolve(generated, 'countries.json'),
        );
        cpSync(resolve(root, 'public/data/card-geography'), resolve(generated, 'card-geography'), {
            recursive: true,
        });
        // Bundle the exact approved consumer artwork for both offline App assets and H5.
        cpSync(resolve(root, 'public/images'), resolve(project, 'src/static/images'), {
            recursive: true,
        });
        const iconDirectory = resolve(project, 'src/static/icons');
        mkdirSync(iconDirectory, { recursive: true });
        for (const name of [
            'Aperture',
            'CreditCard',
            'Menu',
            'Fingerprint',
            'UserRound',
            'Undo2',
            'Unlock',
            'MoreHorizontal',
            'ImagePlus',
            'SlidersHorizontal',
            'ReceiptText',
            'UserPlus',
            'List',
            'CalendarDays',
            'Minus',
            'Clock3',
            'Coins',
            'ArrowDown',
            'ArrowUp',
            'ArrowDownLeft',
            'Mail',
            'MonitorX',
            'ArrowLeft',
            'ArrowRight',
            'ArrowUpRight',
            'Eye',
            'EyeOff',
            'Globe',
            'LockKeyhole',
            'ShieldCheck',
            'ScanFace',
            'Users',
            'BookOpen',
            'ChartNoAxesCombined',
            'Settings',
            'Copy',
            'ChevronRight',
            'ChevronDown',
            'Plus',
            'X',
            'Check',
            'Search',
            'Download',
            'RefreshCw',
            'CircleAlert',
            'CircleCheck',
            'Send',
            'Image',
            'Wallet',
            'ArrowDownToLine',
            'ArrowUpFromLine',
            'ArrowLeftRight',
            'History',
            'PiggyBank',
            'QrCode',
        ]) {
            const filename = name.replace(
                /[A-Z]/g,
                (letter, index) => `${index ? '-' : ''}${letter.toLowerCase()}`,
            );
            writeFileSync(
                resolve(iconDirectory, filename + '.svg'),
                renderToStaticMarkup(
                    createElement(
                        icons[{ Unlock: 'LockOpen', MoreHorizontal: 'Ellipsis' }[name] ?? name],
                        { color: '#25241f', size: 24, strokeWidth: 1.75 },
                    ),
                ),
            );
        }
        for (const [alias, name] of Object.entries({
            assets: 'Aperture',
            cards: 'CreditCard',
            account: 'UserRound',
            support: 'MessageSquare',
            bell: 'Bell',
        })) {
            writeFileSync(
                resolve(iconDirectory, alias + '.svg'),
                renderToStaticMarkup(
                    createElement(icons[name], { color: '#25241f', size: 24, strokeWidth: 2.2 }),
                ),
            );
        }
        writeFileSync(
            resolve(generated, 'icons.json'),
            JSON.stringify(
                Object.fromEntries(
                    readdirSync(iconDirectory)
                        .filter((name) => name.endsWith('.svg'))
                        .map((name) => [
                            name.slice(0, -4),
                            readFileSync(resolve(iconDirectory, name), 'utf8'),
                        ]),
                ),
            ),
        );
        // Shared pure-data catalogs only. Backend files and secrets never enter this project.
        rmSync(resolve(generated, 'i18n'), { recursive: true, force: true });
        cpSync(resolve(root, 'resources/js/i18n'), resolve(generated, 'i18n'), {
            recursive: true,
            filter: (source) =>
                basename(source) === 'i18n' ||
                basename(source) === 'catalog.ts' ||
                (source.endsWith('-catalog.ts') && !basename(source).startsWith('admin')),
        });
        for (const file of [
            'card-transactions.ts',
            'cardholder-changes.ts',
            'system-money.ts',
            'inbox-templates.ts',
            'exact-amount.ts',
            'academy-registration.ts',
            'academy-guide.ts',
            'academy-features.ts',
            'academy-rewards.ts',
            'tenant-articles.ts',
            'asset-network.ts',
        ]) {
            const source = resolve(root, 'resources/js/lib', file);
            if (existsSync(source)) {
                let content = readFileSync(source, 'utf8');
                if (file === 'card-transactions.ts')
                    content = content.replaceAll(
                        'Object.hasOwn(',
                        'Object.prototype.hasOwnProperty.call(',
                    );
                writeFileSync(resolve(generated, file), content);
            }
        }
        writeFileSync(
            resolve(project, 'src/manifest.json'),
            JSON.stringify(
                {
                    name: config.name,
                    appid: config.dcloudAppId,
                    versionName: config.version,
                    versionCode: String(config.buildNumber),
                    vueVersion: '3',
                    transformPx: false,
                    uniStatistics: { enable: false },
                    h5: { router: { mode: 'hash', base: './' }, title: config.name },
                    'app-plus': {
                        usingComponents: true,
                        compilerVersion: 3,
                        splashscreen: {
                            alwaysShowBeforeRender: true,
                            waiting: true,
                            autoclose: true,
                            delay: 0,
                        },
                        modules: {},
                        distribute: {
                            android: {
                                packagename: config.appId,
                                permissions: [
                                    '<uses-permission android:name="android.permission.INTERNET"/>',
                                    '<uses-permission android:name="android.permission.ACCESS_NETWORK_STATE"/>',
                                ],
                            },
                            ios: { appid: config.appId },
                            sdkConfigs: {},
                        },
                    },
                },
                null,
                2,
            ),
        );
        console.log(`Prepared ${config.name} / ${company} / ${mode} (${config.appId})`);
        if (command === 'dev') run('npm', ['run', 'dev:h5']);
        if (command === 'build') {
            run('npm', ['run', platform === 'app' ? 'build:app' : 'build:h5']);
            const output = resolve(root, 'dist/clients', company, mode, platform);
            rmSync(output, { recursive: true, force: true });
            mkdirSync(output, { recursive: true });
            cpSync(resolve(project, 'dist/build', platform === 'app' ? 'app' : 'h5'), output, {
                recursive: true,
            });
            writeFileSync(
                resolve(output, 'build-manifest.json'),
                JSON.stringify(
                    { ...config, company, platform, builtAt: new Date().toISOString() },
                    null,
                    2,
                ),
            );
            console.log(
                `Output: ${output}${platform === 'app' ? ' (App resources, not APK/IPA; use HBuilderX cloud packaging)' : ''}`,
            );
        }
        if (command === 'prepare')
            console.log(
                `Open ${project} in HBuilderX. Review src/manifest.json, then use Release > App cloud packaging. No cloud submission was made.`,
            );
    }
} catch (error) {
    console.error(error.message);
    process.exitCode = 1;
}
