export function safeAddress(value: string): string {
    const host = value.match(/^https:\/\/[a-z0-9.-]+(?::\d+)?(?=\/|$)/i)?.[0];
    if (!host) return '(未打开 HTTPS 网页)';
    const rest = value.slice(host.length), path = rest.split(/[?#]/)[0];
    const route = rest.match(/#(\/pages\/[a-z-]+\/[a-z-]+)(?:[?&]|$)/)?.[1];
    return host + (path === '/' || !path ? '/' : '/[路径已隐藏]') + (route ? '#' + route : '');
}

export function debugScript(prefix: string, enabled: boolean): string {
    return `(function () {
        var key = '__specpayDiagnostics';
        if (window[key]) window[key]();
        if (!${enabled}) return;
        var queue = [], originals = {}, wrappers = {}, originalTitle = null, restoreTimer;
        var allowedNames = ['Error','TypeError','ReferenceError','SyntaxError','RangeError','URIError','EvalError'];
        function push(value) { if (queue.length < 20) queue.push(value); }
        ['log','info','warn','error','debug'].forEach(function (level) {
            originals[level] = console[level];
            wrappers[level] = function () {
                var types = Array.prototype.slice.call(arguments, 0, 5).map(function (value) { return value === null ? 'null' : typeof value; });
                push({kind:'console', level:level, types:types});
                return originals[level].apply(console, arguments);
            };
            console[level] = wrappers[level];
        });
        function error(event) {
            var name = event.error && event.error.name;
            push({kind:event.target && event.target !== window ? 'resource' : 'exception',
                name:allowedNames.indexOf(name) >= 0 ? name : 'Error',
                line:Number(event.lineno) || 0, column:Number(event.colno) || 0});
        }
        function rejection() { push({kind:'rejection'}); }
        window.addEventListener('error', error, true);
        window.addEventListener('unhandledrejection', rejection);
        function restore() { if (originalTitle !== null && document.title.indexOf(${JSON.stringify(prefix)}) === 0) document.title = originalTitle; originalTitle = null; }
        var timer = window.setInterval(function () {
            if (!queue.length || /^specpay-ready-/.test(document.title)) return;
            restore(); originalTitle = document.title;
            document.title = ${JSON.stringify(prefix)} + JSON.stringify(queue.splice(0, 20));
            restoreTimer = window.setTimeout(restore, 100);
        }, 1000);
        window[key] = function () {
            window.clearInterval(timer); window.clearTimeout(restoreTimer); restore(); queue = [];
            window.removeEventListener('error', error, true);
            window.removeEventListener('unhandledrejection', rejection);
            Object.keys(originals).forEach(function (level) { if (console[level] === wrappers[level]) console[level] = originals[level]; });
            delete window[key];
        };
    })();`;
}

// Treat title-event payloads as untrusted. Only structural diagnostics are shown;
// never stringify arbitrary console arguments, exception messages or HTTP bodies.
export function debugMessages(payload: string): string[] {
    if (payload.length > 6000) return [];
    try {
        const rows: unknown = JSON.parse(payload);
        if (!Array.isArray(rows)) return [];
        return rows.slice(0, 20).flatMap(row => {
            if (!row || typeof row !== 'object') return [];
            if (row.kind === 'console' && ['log','info','warn','error','debug'].includes(row.level)) {
                const types = Array.isArray(row.types) ? row.types.slice(0, 5).filter((t: unknown) =>
                    ['string','number','boolean','object','undefined','function','symbol','bigint','null'].includes(String(t))) : [];
                return [`console.${row.level} (${types.join(', ')}) [内容已脱敏]`];
            }
            if (row.kind === 'resource') return ['网页资源加载失败'];
            if (row.kind === 'rejection') return ['未处理的 Promise 异常 [内容已脱敏]'];
            if (row.kind === 'exception') {
                const name = ['Error','TypeError','ReferenceError','SyntaxError','RangeError','URIError','EvalError'].includes(row.name) ? row.name : 'Error';
                const line = Number.isSafeInteger(row.line) && row.line >= 0 ? row.line : 0;
                const column = Number.isSafeInteger(row.column) && row.column >= 0 ? row.column : 0;
                return [`${name} 行 ${line}:${column} [内容已脱敏]`];
            }
            return [];
        });
    } catch { return []; }
}
