import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

export function validateCompany(value, mode = 'debug') {
    if (!['debug', 'release'].includes(mode)) throw new Error('Mode must be debug or release.');
    const allowed = ['name', 'appId', 'dcloudAppId', 'tenantSlug', 'apiOrigin', 'version', 'buildNumber', 'developmentOnly'];
    if (Object.keys(value).some((key) => !allowed.includes(key))) throw new Error('Unknown company field. Never put signing secrets in company JSON.');
    if (typeof value.name !== 'string' || !value.name.trim() || value.name.length > 60 || /[<>\r\n]/.test(value.name)) throw new Error('Invalid app name.');
    if (!/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*){2,}$/.test(value.appId ?? '')) throw new Error('Use a reverse-domain appId.');
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value.tenantSlug ?? '')) throw new Error('Invalid tenant slug.');
    if (!/^\d+\.\d+\.\d+$/.test(value.version ?? '')) throw new Error('Use a numeric three-part version.');
    if (!Number.isSafeInteger(value.buildNumber) || value.buildNumber < 1) throw new Error('Invalid build number.');
    const origin = new URL(value.apiOrigin);
    if (!['https:', 'http:'].includes(origin.protocol) || origin.username || origin.password || origin.pathname !== '/' || origin.search || origin.hash) throw new Error('apiOrigin must be an HTTP(S) origin without credentials/path.');
    if (typeof value.developmentOnly !== 'boolean') throw new Error('Set developmentOnly explicitly.');
    if (value.dcloudAppId && !/^__UNI__[A-Z0-9]+$/i.test(value.dcloudAppId)) throw new Error('Invalid DCloud appid.');
    if (mode === 'release' && !value.dcloudAppId) throw new Error('Set the company DCloud appid before preparing a release.');
    if (mode === 'release' && (value.developmentOnly || origin.protocol !== 'https:' || /(^|\.)(localhost|test|invalid|example)$/.test(origin.hostname) || /^\d+\.\d+\.\d+\.\d+$/.test(origin.hostname))) throw new Error('Release requires a real HTTPS company domain and developmentOnly=false.');
    if (origin.protocol === 'http:' && !value.developmentOnly) throw new Error('HTTP is allowed only in development profiles.');
    return { ...value, apiOrigin: origin.origin, appId: value.appId + (mode === 'debug' ? '.debug' : ''), mode };
}

export function readCompany(root, name, mode = 'debug') {
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(name ?? '')) throw new Error('Specify --company using a company filename (without .json).');
    return validateCompany(JSON.parse(readFileSync(resolve(root, 'mobile/companies', `${name}.json`), 'utf8')), mode);
}
