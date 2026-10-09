// Keep the complete title below Chromium's 4096-character title limit.
const CHUNK = 3072;
const MAX = 8 * 1024 * 1024;

// A single-purpose, acknowledged channel. No remote 5+ injection, filesystem
// path, URL fetch, credential, or arbitrary native method is accepted.
export function posterBridgeScript(prefix: string): string {
    return `(function(){
        if(window.__specpayPoster) window.__specpayPoster.cancel();
        var prefix=${JSON.stringify(prefix)}, pending=null, sequence=0;
        function finish(code){
            if(!pending)return;
            var p=pending;pending=null;clearTimeout(p.timer);
            if(document.title.indexOf(prefix)===0)document.title=p.title;
            if(code==='saved')p.resolve();else p.reject(new Error(code));
        }
        function send(){
            if(!pending)return;
            var p=pending;
            document.title=prefix+JSON.stringify({id:p.id,index:p.index,total:p.total,chunk:p.data.slice(p.index*${CHUNK},(p.index+1)*${CHUNK})});
        }
        window.__specpayPoster={
            version:1,
            cancel:function(){finish('failed');},
            save:function(data){return new Promise(function(resolve,reject){
                if(pending){reject(new Error('busy'));return;}
                if(typeof data!=='string'||data.length>${MAX}||!/^data:image\\/png;base64,[A-Za-z0-9+/=]+$/.test(data)){reject(new Error('invalid'));return;}
                var title=/^specpay-(ready|debug|session|poster)-/.test(document.title)?'Spec Pay':document.title;
                pending={id:++sequence,index:0,total:Math.ceil(data.length/${CHUNK}),data:data,title:title,resolve:resolve,reject:reject};
                pending.timer=setTimeout(function(){finish('timeout');},120000);
                send();
            });},
            reply:function(id,index,result){
                if(!pending||pending.id!==id||pending.index!==index)return;
                if(result==='next'){pending.index++;setTimeout(send,0);}
                else finish(result);
            }
        };
    })();`;
}

export function posterReceiver(options: {
    prefix: string;
    trusted: () => boolean;
    evaluate: (script: string) => void;
    save: (data: string) => Promise<void>;
}) {
    let current: { id: number; next: number; total: number; chunks: string[]; size: number } | null = null;
    let lastId = 0, saving = false, disposed = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    function reply(id: number, index: number, result: string) {
        if (!disposed && options.trusted()) options.evaluate(`window.__specpayPoster&&window.__specpayPoster.reply(${id},${index},${JSON.stringify(result)});`);
    }
    function clear() { clearTimeout(timer); current = null; }
    return {
        dispose() { disposed = true; clear(); },
        async receive(title: string) {
            if (disposed || !options.trusted() || !title.startsWith(options.prefix)) return;
            if (title.length > options.prefix.length + CHUNK + 200) return;
            let message;
            try { message = JSON.parse(title.slice(options.prefix.length)); } catch { return; }
            const { id, index, total, chunk } = message ?? {};
            if (!Number.isSafeInteger(id) || id < 1 || !Number.isInteger(index) || index < 0
                || !Number.isInteger(total) || total < 1 || total > Math.ceil(MAX / CHUNK)
                || index >= total || typeof chunk !== 'string' || chunk.length > CHUNK) return;
            if (index === 0 && id > lastId) {
                if (saving || current) { reply(id, index, 'busy'); return; }
                lastId = id; current = { id, next: 0, total, chunks: [], size: 0 };
                timer = setTimeout(() => { if (current) reply(current.id, current.next, 'timeout'); clear(); }, 60000);
            }
            if (!current || id !== current.id || index !== current.next || total !== current.total || saving) return;
            current.chunks.push(chunk); current.size += chunk.length;
            if (current.size > MAX) { reply(id, index, 'invalid'); clear(); return; }
            if (index + 1 < total) { current.next++; reply(id, index, 'next'); return; }
            const data = current.chunks.join(''); clear();
            if (!validPoster(data)) { reply(id, index, 'invalid'); return; }
            saving = true;
            try { await options.save(data); reply(id, index, 'saved'); }
            catch (error) { reply(id, index, error instanceof Error && error.message === 'timeout' ? 'timeout' : 'failed'); }
            finally { saving = false; }
        },
    };
}

export function validPoster(data: string): boolean {
    if (data.length > MAX || !/^data:image\/png;base64,iVBORw0KGgo[A-Za-z0-9+/]*={0,2}$/.test(data)) return false;
    const base = data.slice(22), alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    if (base.length < 44 || base.length % 4) return false;
    const bytes: number[] = [];
    for (let i = 0; i < 44; i += 4) {
        const n = (alphabet.indexOf(base[i]) << 18) | (alphabet.indexOf(base[i + 1]) << 12)
            | (alphabet.indexOf(base[i + 2]) << 6) | alphabet.indexOf(base[i + 3]);
        bytes.push((n >>> 16) & 255, (n >>> 8) & 255, n & 255);
    }
    if (String.fromCharCode(...bytes.slice(12, 16)) !== 'IHDR') return false;
    const size = (offset: number) => bytes.slice(offset, offset + 4).reduce((n, value) => n * 256 + value, 0);
    const width = size(16), height = size(20);
    return width > 0 && height > 0 && width <= 4096 && height <= 4096 && width * height <= 12000000;
}

export type PosterRuntime = {
    nativeObj: { Bitmap: new (id: string) => {
        loadBase64Data(data: string, success: () => void, failure: () => void): void;
        save(path: string, options: { format: string; overwrite: boolean }, success: () => void, failure: () => void): void;
        clear(): void;
    } };
    gallery: { save(path: string, success: () => void, failure: () => void): void };
    io: { resolveLocalFileSystemURL(path: string, success: (entry: { remove(success: () => void, failure: () => void): void }) => void, failure: () => void): void };
};
let nativeBusy = false;
export async function savePoster(runtime: PosterRuntime, data: string, valid: () => boolean): Promise<void> {
    if (nativeBusy || !valid() || !validPoster(data)) throw new Error('invalid');
    nativeBusy = true;
    const name = `invitation-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const path = `_doc/${name}.png`;
    let bitmap: InstanceType<PosterRuntime['nativeObj']['Bitmap']> | undefined;
    const remove = () => runtime.io.resolveLocalFileSystemURL(path, entry => entry.remove(() => {}, () => {}), () => {});
    const deadline = Date.now() + 60000;
    const step = (run: (ok: () => void, fail: () => void) => void) => new Promise<void>((resolve, reject) => {
        let expired = false;
        const timer = setTimeout(() => { expired = true; reject(new Error('timeout')); }, Math.max(1, deadline - Date.now()));
        try {
            run(() => { clearTimeout(timer); if (expired) { remove(); return; } resolve(); },
                () => { clearTimeout(timer); reject(new Error('failed')); });
        } catch { clearTimeout(timer); reject(new Error('failed')); }
    });
    try {
        bitmap = new runtime.nativeObj.Bitmap(name);
        await step((ok, fail) => bitmap!.loadBase64Data(data, ok, fail));
        if (!valid()) throw new Error('stale');
        await step((ok, fail) => bitmap!.save(path, { format: 'png', overwrite: false }, ok, fail));
        if (!valid()) throw new Error('stale');
        await step((ok, fail) => runtime.gallery.save(path, ok, fail));
    } finally {
        try { bitmap?.clear(); } catch { /* Already released by the runtime. */ }
        try { remove(); } catch { /* The temporary file may not have been created. */ }
        nativeBusy = false;
    }
}
