// Offline-only UI acceptance: compiled applications + synthetic DTOs; no upstream.
import http from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { resolve, extname, sep } from 'node:path';
const root = process.cwd();
const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const entry = manifest['resources/js/app.tsx'];
const langs = ['zh-CN','en','ms','es'];
const chat = { id: 'synthetic-conversation', before: 0, olderCursor: null, messages: [
    { id:'test1', sequence:1, fromSupport:false, supportName:null, text:'Synthetic customer question', imageUrl:null, createdAt:'2026-09-27T02:00:00+08:00' },
    { id:'test2', sequence:2, fromSupport:true, supportName:null, text:'Historical reply · default support name', imageUrl:null, createdAt:'2026-09-27T02:01:00+08:00' },
    { id:'test3', sequence:3, fromSupport:true, supportName:'<b>客服小林</b>', text:'Synthetic nickname reply · 纯文本昵称', imageUrl:null, createdAt:'2026-09-27T02:02:00+08:00' },
] };
const mime = {'.html':'text/html; charset=utf-8','.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png','.jpg':'image/jpeg','.woff2':'font/woff2'};
function legacy(locale) { return { component:'user/Support', url:'/support?lang='+locale, version:'synthetic', clearHistory:false, encryptHistory:false, props:{chat, auth:{admin:null,user:{id:'synthetic',accountId:'202600000001',displayName:'Synthetic customer',email:'synthetic@example.test',status:'ACTIVE'}}, tenant:{id:'synthetic',name:'Support UI fixture',branding:{brandName:'Spec Pay',primaryColor:'#39ad8d',logoUrl:null},locales:langs}, i18n:{locale,enabledLocales:langs,timezone:'Asia/Kuala_Lumpur',surface:'user'}, unreadMessages:2,unreadSupport:2,flash:{success:null},errors:{},requestId:'synthetic'} }; }
http.createServer((req,res)=>{
    const url = new URL(req.url,'http://127.0.0.1');
    const referer = new URL(req.headers.referer || 'http://127.0.0.1');
    const lang = url.searchParams.get('lang') || referer.searchParams.get('lang') || 'zh-CN';
    const locale=langs.includes(lang)?lang:'zh-CN';
    res.setHeader('Cache-Control','no-store');
    const json=(data,status=200)=>{res.writeHead(status,{'Content-Type':'application/json'});res.end(JSON.stringify(data));};
    if(req.method==='POST') {
        if(['/support/read','/api/v1/support/read'].includes(url.pathname)) return json({});
        return json({errors:{support_message:'Synthetic send blocked: offline fixture only.'}},422);
    }
    if(url.pathname==='/api/v1/bootstrap') return json({apiVersion:1,tenant:{id:'synthetic',slug:'tenant-a',name:'Spec Pay',logoUrl:null,primaryColor:'#39ad8d'}, user:{id:'synthetic',accountId:'202600000001',displayName:'Synthetic',email:'synthetic@example.test'},restricted:false,locale,locales:langs,timezone:'Asia/Kuala_Lumpur',csrfToken:'synthetic',unread:{messages:2,support:2}});
    if(url.pathname==='/api/v1/support')return json(chat);
    if(url.pathname==='/api/v1/unread')return json({messages:2,support:0});
    if(url.pathname==='/messages/unread-count')return json({count:2,supportCount:0});
    if(url.pathname.startsWith('/platform/')){
        const page=legacy(locale);
        const target=url.pathname.endsWith('/customer-b')?'customer-b':'customer-a';
        page.component='platform/Support'; page.url=url.pathname+'?lang='+locale;
        page.props.auth={user:null,admin:{id:'synthetic-admin',name:'Synthetic agent',email:'agent@example.test',scope:'PLATFORM',permissions:['support.read','support.send','support.agents.manage']}};
        page.props.i18n.surface='platform'; page.props.companies=[{id:'synthetic-company',name:'Synthetic company'}];
        page.props.filters={};page.props.supportName='Synthetic agent';
        page.props.inbox={data:['customer-a','customer-b'].map((userId,i)=>({id:userId,tenantId:'synthetic-company',userId,company:'Synthetic company',accountId:'20260000000'+(i+1),email:userId+'@example.test',awaitingReply:true,updatedAt:'2026-09-27T02:00:00+08:00'})),total:2,current_page:1,last_page:1,prev_page_url:null,next_page_url:null};
        page.props.chat={...chat,tenantId:'synthetic-company',userId:target,company:'Synthetic company',accountId:target==='customer-a'?'202600000001':'202600000002',email:target+'@example.test'};
        if(req.headers['x-inertia']) {res.setHeader('X-Inertia','true');return json(page);}
        res.setHeader('Content-Type','text/html; charset=utf-8');
        return res.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${(entry.css||[]).map(css=>`<link rel="stylesheet" href="/build/${css}">`).join('')}</head><body><div id="app"></div><script data-page="app" type="application/json">${JSON.stringify(page).replaceAll('<','\\u003c')}</script><script type="module" src="/build/${entry.file}"></script></body></html>`);
    }
    if(url.pathname==='/support'){
        const page=legacy(locale);
        if(req.headers['x-inertia']) {res.setHeader('X-Inertia','true');return json(page);}
        res.setHeader('Content-Type','text/html; charset=utf-8');
        return res.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${(entry.css||[]).map(css=>`<link rel="stylesheet" href="/build/${css}">`).join('')}</head><body><div id="app" ></div><script data-page="app" type="application/json">${JSON.stringify(page).replaceAll('<','\\u003c')}</script><script type="module" src="/build/${entry.file}"></script></body></html>`);
    }
    const base=url.pathname.startsWith('/build/')?resolve(root,'public'):resolve(root,'mobile/uni-app/dist/build/h5');
    const path=resolve(base,'.'+url.pathname+(url.pathname.endsWith('/')?'index.html':''));
    if(!path.startsWith(base+sep)||!existsSync(path)) {res.writeHead(404);return res.end();}
    res.setHeader('Content-Type',mime[extname(path)]||'application/octet-stream');res.end(readFileSync(path));
}).listen(5255,'127.0.0.1',()=>console.log('Offline synthetic support UI: http://127.0.0.1:5255/support?lang=zh-CN and /?lang=zh-CN#/pages/support/index'));
