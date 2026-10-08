import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';

// Synthetic offline traffic only: no real company publication.
const origin = 'http://admin.localhost:8000';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const out = 'artifacts/uni-parity/platform-android-release';
mkdirSync(out, { recursive: true });
const company = { id: '11111111-1111-4111-8111-111111111111', name: 'Offline APK Company', slug: 'offline', status: 'ACTIVE' };
const base = `/platform/tenants/${company.id}/configuration`;
for (const [name, engine, launch] of [['chrome', chromium, {channel:'chrome'}], ['webkit', webkit, {}]]) {
    const browser = await engine.launch({headless:true, ...launch});
    try {
        const context = await browser.newContext({viewport:{width:1440,height:900}});
        const page = await context.newPage(); page.setDefaultTimeout(12000);
        const errors = [], unexpected = [], writes = [];
        let published = false, fail = true;
        const shared = {errors:{},publicAssets:[],tenant:null,auth:{admin:{name:'Fixture Admin',permissions:['tenant.manage']},user:null},flash:{},i18n:{locale:'zh-CN',enabledLocales:['en','zh-CN'],timezone:'Asia/Shanghai',surface:'platform'},requestId:'fixture'};
        page.on('pageerror', e => errors.push(e.message));
        await context.route('**/*', async route => {
            const request=route.request(), url=new URL(request.url());
            if (url.origin!==origin) { unexpected.push(url.href); return route.abort(); }
            if (url.pathname.startsWith('/build/')) return route.fulfill({body:readFileSync('public'+url.pathname),contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript'});
            if (request.method()==='POST' && url.pathname===base+'/settings/android-release') {
                writes.push(request.postData());
                await new Promise(r=>setTimeout(r,350));
                if(fail) return route.fulfill({status:422,json:{errors:{revision:['The app release changed. Reload this editor and try again.']}}});
                published=true;return route.fulfill({json:{saved:true}});
            }
            let component, props;
            if(url.pathname==='/platform/company-configurations') {
                component='platform/CompanyConfigurations';props={companies:[company],records:{data:[{...company,default_locale:'zh-CN',timezone:'Asia/Shanghai'}],total:1,current_page:2,last_page:2,prev_page_url:null,next_page_url:null},filters:{}};
            } else if(url.pathname===base+'/settings/branding') {
                component='tenant-admin/Settings';props={configurationCompany:company,configurationBase:base,configurationReadOnly:false,section:'branding',settings:{branding:{brandName:'Offline',primaryColor:'#155eef'},androidRelease:{current:published?{appId:'__UNI__TEST',versionName:'2.3.59',versionCode:2359}:null,revision:(published?'b':'a').repeat(64),available:published,downloadUrl:'http://zb33333.com/specpay.apk'}}};
            } else {unexpected.push(request.method()+' '+url.href);return route.abort();}
            const state={component,props:{...shared,...props},url:url.pathname+url.search,version:'fixture'};
            if(request.headers()['x-inertia'])return route.fulfill({json:state,headers:{'X-Inertia':'true'}});
            return route.fulfill({contentType:'text/html',body:`<!doctype html><html><head><meta charset="utf-8">${(entry.css??[]).map(f=>`<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state).replaceAll('<','\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`});
        });
        await page.goto(origin+'/platform/company-configurations?page=2&section=settings%2Fbranding&editor='+encodeURIComponent(base+'/settings/branding'));
        const dialog=page.getByRole('dialog');
        await dialog.getByText('App 版本发布',{exact:true}).waitFor();
        assert.equal(writes.length,0);
        for(const width of [375,768,1440]) {
            await page.setViewportSize({width,height:900});
            await dialog.locator('#android-appid').scrollIntoViewIfNeeded();
            assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
            await page.screenshot({path:`${out}/${name}-${width}.png`});
        }
        const publish=dialog.getByRole('button',{name:'发布 App 版本',exact:true});
        assert.equal(await publish.isDisabled(),true);
        assert.equal(await dialog.locator('input[type=file][accept=".apk"]').count(),0);
        await dialog.locator('#android-appid').fill('__UNI__TEST');
        await dialog.locator('#android-version-name').fill('2.3.59');
        await dialog.locator('#android-version-code').fill('2359');
        await dialog.locator('#androidDownloadUrl').fill('https://download.example/app.apk');
        await dialog.locator('#iosDistributionUrl').fill('https://install.example/ios');
        assert.equal(await publish.isDisabled(),true);
        await dialog.locator('#android-confirmed').check();
        await publish.click();
        assert.equal(await dialog.locator('#android-appid').isDisabled(),true);
        await dialog.getByText('App 版本已被其他人更新，请重新打开编辑器后重试。',{exact:true}).waitFor();
        assert.equal(await dialog.locator('#android-version-name').inputValue(),'2.3.59');
        assert.match(writes[0],/name="revision"/);assert.match(writes[0],/https:\/\/download.example\/app.apk/);assert.match(writes[0],/https:\/\/install.example\/ios/);assert.doesNotMatch(writes[0],/name="apk"|filename=/);
        fail=false;await publish.click();await dialog.waitFor({state:'hidden'});
        assert.equal(writes.length,2);assert.equal(new URL(page.url()).searchParams.get('page'),'2');
        await page.goto(origin+'/platform/company-configurations?page=2&section=settings%2Fbranding&editor='+encodeURIComponent(base+'/settings/branding'));
        await dialog.locator('#android-appid').waitFor();
        assert.equal(await dialog.locator('#android-appid').getAttribute('readonly'),'');
        assert.match(await dialog.textContent(),/2\.3\.59 \(2359\)/);
        assert.deepEqual(errors,[]);assert.deepEqual(unexpected,[]);
        console.log(`${name}: Android release form at 3 widths, confirmation, metadata-only publication, stale error, busy guards, refresh and scoped list state passed`);
    } finally {await browser.close();}
}
