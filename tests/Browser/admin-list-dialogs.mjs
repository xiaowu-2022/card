import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';

// Every request is fulfilled from synthetic fixtures, including saves. No live business writes.
const origin = 'http://admin.localhost:8000';
const manifest = JSON.parse(readFileSync('public/build/manifest.json'));
const entry = manifest['resources/js/app.tsx'];
const out = 'artifacts/uni-parity/admin-list-dialogs';
mkdirSync(out, {recursive:true});
const companies = [{id:'11111111-1111-4111-8111-111111111111',name:'公司一 · 很长的公司名称用于检查列表显示'}, {id:'22222222-2222-4222-8222-222222222222',name:'公司二'}];
const paginate = data => ({data,total:data.length,current_page:1,last_page:1,prev_page_url:null,next_page_url:null});
for (const [name, engine, launch] of [['chrome',chromium,{channel:'chrome'}],['webkit',webkit,{}]]) {
    const browser = await engine.launch({headless:true,...launch});
    try {
        const context = await browser.newContext({viewport:{width:1366,height:850}});
        const page = await context.newPage(); page.setDefaultTimeout(15000);
        const errors=[], unexpected=[]; let saves=0, fail=true, detailReads=0, commissionSaves=0;
        page.on('pageerror', e => { errors.push(e.message); console.error('PAGE ERROR:',e.message); });
        const shared = {errors:{},publicAssets:[],tenant:null,auth:{admin:{name:'测试管理员',permissions:['tenant.manage','tenants.read','commissions.adjust','users.read','wallet.read','card_product.manage','notifications.read','notifications.send']},user:null},flash:{},i18n:{locale:'zh-CN',enabledLocales:['zh-CN','en'],timezone:'Asia/Shanghai',surface:'platform'},requestId:'fixture'};
        await context.route('**/*', async route => {
            const request=route.request(), url=new URL(request.url());
            if(url.origin!==origin){unexpected.push(url.href);return route.abort();}
            if(url.pathname.startsWith('/build/'))return route.fulfill({body:readFileSync('public'+url.pathname),contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript'});
            let component, props;
            if (request.method()==='POST' && url.pathname.endsWith('/manual-commissions')) {
                const body=request.postData(); assert.match(body,/name="commission_type"\r?\n\r?\nannual/); assert.match(body,/name="direction"\r?\n\r?\nDECREASE/); commissionSaves++; return route.fulfill({json:{saved:true}});
            }
            if (request.method()==='GET' && url.pathname.endsWith('/manual-commissions')) {
                const state={component:'platform/ManualCommission',props:{...shared,account:{id:'33333333-3333-4333-8333-333333333333',companyId:companies[0].id,companyName:companies[0].name,accountId:'202610051234',email:'synthetic@example.test'},balances:[{asset:'USDT',amount:'100.00000000'}],commission:'100.00000000',categories:{activation:'80.00000000',annual:'10.00000000',legacy:'10.00000000'},history:paginate([]),filters:{}},url:url.pathname+url.search,version:'fixture'};
                return route.fulfill({headers:{'X-Inertia':'true'},json:state});
            }
            if (request.method()==='POST' && url.pathname==='/platform/tenants') {
                saves++;
                await new Promise(resolve=>setTimeout(resolve,200));
                return route.fulfill(fail?{status:422,json:{errors:{name:['The name field is required.']}}}:{json:{saved:true}});
            }
            if(url.pathname==='/platform/tenants/create') {
                detailReads++;component='platform/TenantCreate';props={locales:['en','zh-CN'],assets:['USDT'],timezones:['UTC','Asia/Shanghai']};
            } else if(url.pathname==='/platform/tenants') {
                component='platform/Tenants';props={tenants:paginate(companies.map((c)=>({...c,slug:'fixture',domain:'synthetic.example.test',status:'ACTIVE',createdAt:'2026-10-05T01:00:00Z'}))),totals:{},financialAccess:{inflow:false,outflow:false},filters:{search:url.searchParams.get('search')??'',status:url.searchParams.get('status')??''}};
            } else if(url.pathname==='/platform/notifications') {
                component='platform/Notifications';props={companies,company:null,batches:paginate([])};
            } else if(url.pathname==='/platform/card-products') {
                component='platform/CardProducts';props={products:[],cardProviders:[],pagination:{total:0,previous:null,next:null}};
            } else if(url.pathname==='/platform/company-configurations') {
                component='platform/CompanyConfigurations';props={companies,records:paginate(companies.map(c=>({...c,slug:'fixture',status:'ACTIVE',default_locale:'en',timezone:'Asia/Shanghai'}))),filters:{}};
            } else if(/\/configuration\/wealth$/.test(url.pathname)) {
                detailReads++;component='platform/WealthSettings';const c=companies.find(c=>url.pathname.includes(c.id));props={company:c,configurationCompany:{...c,slug:'fixture',status:'ACTIVE'},configurationBase:`/platform/tenants/${c.id}/configuration`,readOnly:false,settings:['USDT','USDC','ETH','BTC'].map(asset=>({asset,minimum:'100.00000000',products:[1,3,6,9,12,24,36].map(months=>({months,enabled:true,rate:'10.00000000'}))}))};
            } else {unexpected.push(request.method()+' '+url.pathname);return route.abort();}
            const state={component,props:{...shared,...props},url:url.pathname+url.search,version:'fixture'};
            if(request.headers()['x-inertia'])return route.fulfill({headers:{'X-Inertia':'true'},json:state});
            return route.fulfill({contentType:'text/html',body:`<!doctype html><html><head><meta charset="utf-8">${(entry.css??[]).map(file=>`<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state).replaceAll('<','\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`});
        });
        await page.goto(origin+'/platform/tenants?search=fixture&status=ACTIVE&page=2');
        await page.getByRole('link',{name:'创建公司',exact:true}).click();
        const dialog=page.getByRole('dialog');await dialog.waitFor();
        await dialog.locator('#name').fill('Synthetic company');
        await dialog.locator('#slug').fill('synthetic-company');
        await dialog.locator('#owner-email').fill('synthetic@example.test');
        const save=dialog.getByRole('button',{name:'创建并邀请所有者',exact:true});
        await save.click();await page.waitForFunction(()=>document.body.textContent.includes('请填写此项。'));
        assert.equal(await dialog.locator('#name').inputValue(),'Synthetic company');
        assert.equal(saves,1);assert.match(page.url(),/search=fixture/);
        page.once('dialog',d=>d.dismiss());await dialog.getByRole('button',{name:'关闭',exact:true}).last().click();
        assert.equal(await dialog.count(),1);
        fail=false;await save.click();await dialog.waitFor({state:'hidden'});
        assert.equal(saves,2);assert.match(page.url(),/search=fixture/);assert.ok(!page.url().includes('editor='));
        await page.goto(origin+'/platform/company-configurations?section=wealth');
        for(const width of [1024,1366,1920]) {
            await page.setViewportSize({width,height:850});
            await page.locator('a[href$="/configuration/wealth"]').first().click();await dialog.waitFor();
            await dialog.locator('#minimum-USDT').waitFor();
            assert.equal(await dialog.locator('aside').count(),0);
            const rect=await dialog.boundingBox();assert.ok(rect.x>=0&&rect.x+rect.width<=width+1);assert.ok(rect.y>=0&&rect.y+rect.height<=850+1);
            const body=dialog.locator('[data-admin-editor-body]');assert.ok(await body.evaluate(el=>el.scrollHeight>el.clientHeight));
            await body.evaluate(el=>el.scrollTop=el.scrollHeight);
            await page.screenshot({path:`${out}/${name}-${width}.png`});
            await dialog.getByRole('button',{name:'关闭',exact:true}).last().click();await dialog.waitFor({state:'hidden'});
            await page.waitForFunction(() => document.querySelector('a[href$="/configuration/wealth"]') === document.activeElement);
        }
        const commissionPath=`/platform/tenants/${companies[0].id}/users/33333333-3333-4333-8333-333333333333/manual-commissions`;
        await page.goto(origin+'/platform/tenants?search=fixture&editor='+encodeURIComponent(commissionPath));
        await dialog.locator('form').waitFor();
        await dialog.locator('form select').selectOption('annual');
        await dialog.getByPlaceholder('+100 / -50').fill('-11');
        await dialog.getByRole('checkbox').check();
        await page.waitForFunction(() => [...document.querySelectorAll('[role=dialog] button')].some(button => button.textContent === '确认调整' && button.getBoundingClientRect().height > 0 && button.disabled));
        await dialog.getByPlaceholder('+100 / -50').fill('-5');
        await dialog.locator('textarea').fill('Synthetic reason');
        await dialog.getByRole('checkbox').check();
        await dialog.getByRole('button',{name:'确认调整',exact:true}).click();
        await dialog.waitFor({state:'hidden'});assert.equal(commissionSaves,1);assert.match(page.url(),/search=fixture/);
        await page.goto(origin+'/platform/card-products');
        for (const width of [1024,1366,1920]) {
            await page.setViewportSize({width,height:400});
            await page.getByRole('button',{name:'新增产品',exact:true}).click();
            await dialog.locator('#create-name').waitFor();
            const bounds=await dialog.boundingBox();assert.ok(bounds.x>=0&&bounds.x+bounds.width<=width+1&&bounds.y>=0&&bounds.y+bounds.height<=401);
            const fields=dialog.locator('fieldset');assert.ok(await fields.evaluate(el=>el.scrollHeight>el.clientHeight));
            const cancel=dialog.getByRole('button',{name:'取消',exact:true});
            const before=await cancel.boundingBox();await fields.evaluate(el=>el.scrollTop=el.scrollHeight);const after=await cancel.boundingBox();assert.equal(before.y,after.y);
            await cancel.click();await dialog.waitFor({state:'hidden'});
        }
        await page.goto(origin+'/platform/notifications?page=2');
        for (const width of [1024,1366,1920]) {
            await page.setViewportSize({width,height:600});
            await page.getByRole('button',{name:'新建通知',exact:true}).click();
            await dialog.getByLabel('公司',{exact:true}).selectOption(companies[0].id);
            await dialog.locator('textarea').waitFor();
            const body=dialog.locator('section > div.overflow-y-auto').first();
            assert.ok(await body.evaluate(el=>el.scrollHeight>el.clientHeight));
            const preview=dialog.getByRole('button',{name:'预览通知',exact:true});
            const before=await preview.boundingBox();await body.evaluate(el=>el.scrollTop=el.scrollHeight);const after=await preview.boundingBox();assert.equal(before.y,after.y);assert.ok(after.y+after.height<=600);
            await dialog.getByRole('button',{name:'关闭',exact:true}).click();await dialog.waitFor({state:'hidden'});
        }
        assert.equal(detailReads>=5,true);assert.deepEqual(errors,[]);assert.deepEqual(unexpected,[]);
        await context.close();console.log(`${name}: list context, lazy DTO, validation, dirty close, save, focus and 3 widths passed`);
    } finally {await browser.close();}
}
