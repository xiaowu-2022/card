import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';

// All traffic, including configuration saves, stays in synthetic fixtures.
const origin = 'http://admin.localhost:8000';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const out = 'artifacts/uni-parity/platform-layout-settings';
mkdirSync(out, { recursive: true });
const company = { id: '11111111-1111-4111-8111-111111111111', name: 'Offline company with a deliberately long name · 配置测试公司', slug: 'offline', status: 'ACTIVE' };
const other = { ...company, id: '22222222-2222-4222-8222-222222222222', name: 'Other company' };
const sections = ['assets','settings/branding','settings/locales','settings/business','settings/articles','settings/sms','settings/email','promotion','wealth'];
const paginate = data => ({ data, total: data.length, current_page: 2, last_page: 3, prev_page_url: null, next_page_url: '/platform/company-configurations?page=3&search=offline&status=ACTIVE' });
for (const [name, engine, launch] of [['chrome',chromium,{channel:'chrome'}],['webkit',webkit,{}]]) {
    const browser = await engine.launch({headless:true,...launch});
    try {
        const context = await browser.newContext({viewport:{width:1024,height:800}});
        const page = await context.newPage(); page.setDefaultTimeout(12000);
        const errors=[], unexpected=[], reads=[], writes=[];
        let failure='', mismatch=false, saveFails=true;
        const shared={errors:{},publicAssets:[],tenant:null,auth:{admin:{name:'Fixture Admin',permissions:['tenant.manage','storage.manage']},user:null},flash:{},i18n:{locale:'en',enabledLocales:['en','zh-CN'],timezone:'Asia/Shanghai',surface:'platform'},requestId:'fixture'};
        page.on('pageerror', e=>errors.push(e.message));
        await context.route('**/*',async route=>{
            const request=route.request(), url=new URL(request.url());
            if(url.origin!==origin){unexpected.push(url.href);return route.abort();}
            if(url.pathname.startsWith('/build/'))return route.fulfill({body:readFileSync('public'+url.pathname),contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript'});
            let component, props;
            if(url.pathname==='/platform/company-configurations'){
                component='platform/CompanyConfigurations';props={companies:[company,other],records:paginate([company,other].map(c=>({...c,default_locale:'en',timezone:'Asia/Shanghai'}))),filters:Object.fromEntries(['company','search','status'].filter(k=>url.searchParams.has(k)).map(k=>[k,url.searchParams.get(k)]))};
            } else if(url.pathname==='/platform/settings/oss'){
                component='platform/OssSettings';props={configuration:null,serverStorage:true,driverRevision:0};
            } else if(url.pathname==='/platform/settings/kyc') {
                component='platform/KycSettings';props={policy:{enabled:true,maxAccountsPerIdentity:1,reviewMode:'MANUAL'}};
            } else if(url.pathname==='/admin/settings/branding'){
                component='tenant-admin/Settings';props={configurationBase:null,section:'branding',configurationReadOnly:true,settings:{branding:{brandName:'Tenant unchanged',primaryColor:'#155eef'}},auth:{admin:{name:'Tenant Admin',permissions:[]},user:null},tenant:{id:company.id,name:company.name,branding:{brandName:company.name,primaryColor:'#155eef'}},i18n:{...shared.i18n,surface:'tenant'}};
            } else {
                const section=url.pathname==='/platform/settings/assets'?'assets':url.pathname.split('/configuration/')[1];
                const id=section==='assets'?url.searchParams.get('company'):url.pathname.split('/')[3];
                if(!sections.includes(section)||id!==company.id){unexpected.push(request.method()+' '+url.pathname);return route.abort();}
                if(request.method()!=='GET') {
                    writes.push({section,url:url.href});
                    await new Promise(r=>setTimeout(r,350));
                    return route.fulfill(saveFails?{status:422,json:{errors:{brand_name:['The brand name field is required.']}}}:{json:{saved:true}});
                }
                reads.push(section);
                assert.equal(request.headers()['x-admin-dialog'],'1');
                if(failure===section){failure='';return route.fulfill({status:503,json:{message:'Synthetic load failure'}});}
                const c=mismatch?other:company; mismatch=false;
                props={configurationCompany:c,configurationBase:`/platform/tenants/${c.id}/configuration`,configurationReadOnly:false};
                if(section==='assets') {
                    component='platform/AssetSettings';props={...props,company:c.id,companies:[company,other],tronAddress:'',tronFeePercent:'1.00000000',tronMinimum:'10.00000000',market:{enabled:true,configured:false,snapshot:null},networks:[],rails:[],companyRails:[],policies:[]};
                } else if(section==='wealth'){
                    component='platform/WealthSettings';props={...props,company:c,readOnly:false,settings:['USDT','USDC','ETH','BTC'].map(asset=>({asset,minimum:'100.00000000',products:[1,3,6,9,12,24,36].map(months=>({months,enabled:true,rate:'10.00000000'}))}))};
                } else if(section==='promotion'){
                    component='platform/PaidPromotion';props={...props,posterBackground:null,paid:{tenantId:c.id,companyName:c.name,levels:[],claims:[],page:1,hasMore:false}};
                } else {
                    component='tenant-admin/Settings';const key=section.split('/')[1];
                    const fixtures={branding:{brandName:'Offline brand',primaryColor:'#155eef',supportEmail:'support@example.test'},locales:[{locale:'en',enabled:true,default:true}],business:{depositAmount:'100.00000000',depositAsset:'USDT',depositRefundWaitDays:7,withdrawalFeePercent:'1.00000000'},articles:[],sms:{profileId:null,profileName:null,available:false,profiles:[]},email:{profileId:null,profileName:null,available:false,profiles:[]}};
                    props={...props,section:key,settings:{[key]:fixtures[key],supportedLocales:['en','zh-CN'],supportedAssets:['USDT']}};
                }
            }
            const state={component,props:{...shared,...props},url:url.pathname+url.search,version:'fixture'};
            if(request.headers()['x-inertia'])return route.fulfill({headers:{'X-Inertia':'true'},json:state});
            return route.fulfill({contentType:'text/html',body:`<!doctype html><html><head><meta charset="utf-8">${(entry.css??[]).map(f=>`<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state).replaceAll('<','\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`});
        });
        const list=origin+'/platform/company-configurations?search=offline&status=ACTIVE&page=2&section=settings%2Fbranding';
        const dialog=page.getByRole('dialog');
        await page.goto(list);
        await page.getByRole('tablist').waitFor();
        assert.equal(reads.length,0);
        for(const width of [1024,1366,1920]){
            await page.setViewportSize({width,height:800});
            const chrome=await page.evaluate(()=>{const header=document.querySelector('[data-platform-header]'),main=document.querySelector('main');return {height:header.getBoundingClientRect().height,mainCount:document.querySelectorAll('main').length,titles:document.querySelectorAll('h1').length,padding:getComputedStyle(main).paddingLeft,overflow:document.documentElement.scrollWidth>innerWidth,content:main.getBoundingClientRect().width};});
            assert.equal(chrome.height,56);assert.equal(chrome.mainCount,1);assert.equal(chrome.titles,1);assert.equal(chrome.padding,'20px');assert.equal(chrome.overflow,false);assert.equal(chrome.content,width-256);
            const input=page.getByRole('textbox',{name:'Search name, slug or domain'});assert.equal((await input.boundingBox()).height,36);
            const cell=page.locator('tbody td').first();assert.equal(await cell.evaluate(n=>getComputedStyle(n).paddingTop),'10px');
            await page.screenshot({path:`${out}/${name}-list-${width}.png`});
            await page.getByRole('link',{name:'Edit',exact:true}).first().click();await dialog.locator('#brand-name').waitFor();
            assert.equal(await page.locator('h1').count(),1);assert.ok((await dialog.getByRole('heading').first().textContent()).includes(company.name));
            const rect=await dialog.boundingBox();assert.ok(rect.x>=0&&rect.x+rect.width<=width+1&&rect.y>=0&&rect.y+rect.height<=801);
            await dialog.getByRole('tab',{name:'Wealth settings',exact:true}).click();await dialog.locator('#minimum-USDT').waitFor();
            const body=dialog.locator('[data-admin-editor-body]');const footer=dialog.getByRole('button',{name:'Close',exact:true}).last();const before=await footer.boundingBox();await body.evaluate(n=>n.scrollTop=n.scrollHeight);assert.equal((await footer.boundingBox()).y,before.y);
            await page.screenshot({path:`${out}/${name}-editor-${width}.png`});
            await footer.click();await dialog.waitFor({state:'hidden'});
            assert.equal(new URL(page.url()).searchParams.get('section'),'wealth');
            await page.getByRole('tab',{name:'Branding',exact:true}).click();
        }
        await page.setViewportSize({width:1024,height:800});
        await page.getByRole('link',{name:'Edit',exact:true}).first().click();await dialog.locator('#brand-name').fill('Unsaved fixture');
        const initialReads=reads.length;
        page.once('dialog',d=>d.dismiss());await dialog.getByRole('tab',{name:'Wealth settings',exact:true}).click();
        assert.equal(await dialog.locator('#brand-name').inputValue(),'Unsaved fixture');assert.equal(reads.length,initialReads);
        page.once('dialog',d=>d.accept());await dialog.getByRole('tab',{name:'Wealth settings',exact:true}).click();await dialog.locator('#minimum-USDT').waitFor();
        const labels=['Asset settings','Branding','Locales','Business rules','About us articles','Aliyun SMS','Proton email','Promotion','Wealth settings'];
        for(let i=0;i<labels.length;i++){
            const beforeReads=reads.length;
            await dialog.getByRole('tab',{name:labels[i],exact:true}).click();
            await page.waitForFunction(()=>!document.querySelector('[data-admin-editor-body] [role=status]')&&document.querySelector('[data-admin-editor-body] form'));
            assert.equal(reads.length,beforeReads+1);assert.equal(reads.at(-1),sections[i]);
            assert.equal(await dialog.count(),1);assert.equal(new URL(page.url()).searchParams.get('section'),sections[i]);
            assert.equal(new URL(page.url()).searchParams.get('page'),'2');assert.equal(new URL(page.url()).searchParams.get('search'),'offline');
            if(i===0)assert.equal(await dialog.locator('select').count(),0,'Company is fixed in the asset editor');
        }
        assert.equal(writes.length,0,'Opening/switching config must be read-only');
        // Keyboard navigation scrolls focused tabs into view; Enter alone changes the DTO.
        const active=dialog.getByRole('tab',{name:'Wealth settings',exact:true});await active.focus();await active.press('Home');
        assert.equal(await page.evaluate(()=>document.activeElement.textContent),'Asset settings');
        await page.keyboard.press('Enter');await dialog.getByText('Save all changes',{exact:true}).last().waitFor();
        await dialog.getByRole('tab',{name:'Branding',exact:true}).click();await dialog.locator('#brand-name').fill('Save fixture');
        const save=dialog.getByRole('button',{name:'Save branding',exact:true});
        await save.click();
        assert.equal(await dialog.getByRole('tab',{name:'Wealth settings',exact:true}).isDisabled(),true);
        assert.equal(await dialog.getByRole('button',{name:'Close',exact:true}).last().isDisabled(),true);
        await dialog.getByText('This field is required.').waitFor();assert.equal(await dialog.locator('#brand-name').inputValue(),'Save fixture');assert.equal(writes.length,1);
        saveFails=false;await save.click();await dialog.waitFor({state:'hidden'});assert.equal(writes.length,2);assert.match(writes[1].url,new RegExp(company.id));
        assert.equal(new URL(page.url()).searchParams.get('page'),'2');
        failure='settings/locales';await page.getByRole('tab',{name:'Locales',exact:true}).click();await page.getByRole('link',{name:'Edit',exact:true}).first().click();await dialog.getByRole('alert').waitFor();
        await dialog.getByRole('button',{name:'Retry',exact:true}).click();await dialog.locator('#default-locale').waitFor();
        await page.reload();await dialog.locator('#default-locale').waitFor();assert.equal(new URL(page.url()).searchParams.get('section'),'settings/locales');
        await dialog.getByRole('button',{name:'Close',exact:true}).last().click();await dialog.waitFor({state:'hidden'});
        mismatch=true;await page.getByRole('link',{name:'Edit',exact:true}).first().click();await dialog.getByRole('alert').waitFor();assert.equal(await dialog.locator('form').count(),0,'Wrong company DTO must never become an editable form');
        await dialog.getByRole('button',{name:'Close',exact:true}).last().click();await dialog.waitFor({state:'hidden'});
        // Legacy editor URLs recover their section without dropping list state.
        await page.goto(list.replace(/&section=.*$/,'')+'&editor='+encodeURIComponent(`/platform/tenants/${company.id}/configuration/wealth`));
        await dialog.locator('#minimum-USDT').waitFor();assert.equal(new URL(page.url()).searchParams.get('section'),'wealth');await dialog.getByRole('button',{name:'Close',exact:true}).last().click();
        await page.goto(origin+'/platform/settings/oss');await page.getByRole('tab',{name:'OSS storage',exact:true}).waitFor();assert.equal(await page.getByRole('tab').count(),6);
        await page.getByRole('tab',{name:'Identity verification settings',exact:true}).click();await page.getByText('KYC policy',{exact:true}).waitFor();assert.equal(await page.locator('h1').count(),1);
        await page.goto(origin+'/admin/settings/branding');await page.locator('#brand-name').waitFor();assert.equal(await page.locator('[data-platform-header]').count(),0);assert.equal((await page.locator('#brand-name').boundingBox()).height,40,'Tenant input density is unchanged');
        assert.deepEqual(errors,[]);assert.deepEqual(unexpected,[]);
        await context.close();console.log(`${name}: shared layout at 3 widths, all 9 tabs, dirty guards, busy guards, retries, scoped DTO, legacy URLs, tenant isolation passed`);
    } finally { await browser.close(); }
}
