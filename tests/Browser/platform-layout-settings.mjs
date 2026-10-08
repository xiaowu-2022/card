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
const sections = ['onboarding','domains','card-products','team','support/hours','support/replies','support/bot','assets','settings/branding','settings/locales','settings/business','settings/articles','settings/sms','settings/email','promotion','wealth'];
const paginate = data => ({ data, total: data.length, current_page: 2, last_page: 3, prev_page_url: null, next_page_url: '/platform/tenants?page=3&search=offline&status=ACTIVE' });
for (const [name, engine, launch] of [['chrome',chromium,{channel:'chrome'}],['webkit',webkit,{}]]) {
    const browser = await engine.launch({headless:true,...launch});
    try {
        const context = await browser.newContext({viewport:{width:1024,height:800}});
        const page = await context.newPage(); page.setDefaultTimeout(12000);
        const errors=[], unexpected=[], reads=[], writes=[];
        let failure='', mismatch=false, saveFails=true, slow='', savedBrand='Offline brand';
        const shared={errors:{},publicAssets:[],tenant:null,auth:{admin:{name:'Fixture Admin',permissions:['tenant.manage','tenant.read','storage.manage','support.read','support.hours.manage','support.replies.manage','support.bot.manage']},user:null},flash:{},i18n:{locale:'en',enabledLocales:['en','zh-CN'],timezone:'Asia/Shanghai',surface:'platform'},requestId:'fixture'};
        page.on('pageerror', e=>errors.push(e.message));
        await context.route('**/*',async route=>{
            const request=route.request(), url=new URL(request.url());
            if(url.origin!==origin){unexpected.push(url.href);return route.abort();}
            if(url.pathname.startsWith('/build/'))return route.fulfill({body:readFileSync('public'+url.pathname),contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript'});
            let component, props;
            if(url.pathname==='/platform/tenants'){
                component='platform/Tenants';props={financialAccess:{inflow:false,outflow:false},totals:{},tenants:paginate([company,other].map(c=>({...c,domain:'offline.test',createdAt:'2026-10-08T00:00:00Z'}))),filters:Object.fromEntries(['company','search','status'].filter(k=>url.searchParams.has(k)).map(k=>[k,url.searchParams.get(k)]))};
            } else if(url.pathname==='/platform/settings/oss'){
                component='platform/OssSettings';props={configuration:null,serverStorage:true,driverRevision:0};
            } else if(url.pathname==='/platform/settings/kyc') {
                component='platform/KycSettings';props={policy:{enabled:true,maxAccountsPerIdentity:1,reviewMode:'MANUAL'}};
            } else if(url.pathname==='/admin/settings/branding'){
                component='tenant-admin/Settings';props={configurationBase:null,section:'branding',configurationReadOnly:true,settings:{branding:{brandName:'Tenant unchanged',primaryColor:'#155eef'}},auth:{admin:{name:'Tenant Admin',permissions:[]},user:null},tenant:{id:company.id,name:company.name,branding:{brandName:company.name,primaryColor:'#155eef'}},i18n:{...shared.i18n,surface:'tenant'}};
            } else {
                const section=url.pathname==='/platform/settings/assets'?'assets':url.pathname.startsWith('/platform/support/')?url.pathname.slice('/platform/'.length):url.pathname.split('/configuration/')[1];
                const id=section==='assets'||section.startsWith('support/')?url.searchParams.get('company'):url.pathname.split('/')[3];
                if(!sections.includes(section)||id!==company.id){unexpected.push(request.method()+' '+url.pathname);return route.abort();}
                if(request.method()!=='GET') {
                    writes.push({section,url:url.href});
                    if(!saveFails && section==='settings/branding') savedBrand='Save fixture';
                    await new Promise(r=>setTimeout(r,350));
                    return route.fulfill(saveFails?{status:422,json:{errors:{brand_name:['The brand name field is required.']}}}:{json:{saved:true}});
                }
                reads.push(section);
                if(slow===section) {slow='';await new Promise(r=>setTimeout(r,350));}
                assert.equal(request.headers()['x-admin-dialog'],'1');
                if(failure===section){failure='';return route.fulfill({status:503,json:{message:'Synthetic load failure'}});}
                const c=mismatch?other:company; mismatch=false;
                props={configurationCompany:c,configurationBase:`/platform/tenants/${c.id}/configuration`,configurationReadOnly:false};
                if(section==='onboarding') {
                    component='tenant-admin/Onboarding'; props={...props,tenantRecord:c,onboarding:{foundation_ready:true,business_ready:false,items:[]}};
                } else if(section==='domains') {
                    component='platform/Domains';props={...props,company:c,domains:[]};
                } else if(section==='card-products') {
                    component='tenant-admin/CardProducts';props={...props,products:[]};
                } else if(section==='team') {
                    component='tenant-admin/Team';props={...props,team:{members:[],invitations:[],roles:['TENANT_ADMIN']}};
                } else if(section==='support/hours') {
                    component='platform/SupportHours';props={...props,companies:[c],filters:{company:c.id},configuration:{timezone:'UTC',revision:0,weekly:Array.from({length:7},()=>[{start:'00:00',end:'24:00'}])}};
                } else if(section==='support/replies') {
                    component='platform/SupportReplies';props={...props,companies:[c],filters:{company:c.id},replies:paginate([])};
                } else if(section==='support/bot') {
                    component='platform/SupportBot';props={...props,companies:[c],filters:{company:c.id},settings:{enabled:false,revision:0},faqs:paginate([])};
                } else if(section==='assets') {
                    component='platform/AssetSettings';props={...props,company:c.id,companies:[company,other],tronAddress:'',tronFeePercent:'1.00000000',tronMinimum:'10.00000000',market:{enabled:true,configured:false,snapshot:null},networks:[],rails:[],companyRails:[],policies:[]};
                } else if(section==='wealth'){
                    component='platform/WealthSettings';props={...props,company:c,readOnly:false,settings:['USDT','USDC','ETH','BTC'].map(asset=>({asset,minimum:'100.00000000',products:[1,3,6,9,12,24,36].map(months=>({months,enabled:true,rate:'10.00000000'}))}))};
                } else if(section==='promotion'){
                    component='platform/PaidPromotion';props={...props,posterBackground:null,paid:{tenantId:c.id,companyName:c.name,levels:[],claims:[],page:1,hasMore:false}};
                } else {
                    component='tenant-admin/Settings';const key=section.split('/')[1];
                    const fixtures={branding:{brandName:savedBrand,primaryColor:'#155eef',supportEmail:'support@example.test'},locales:[{locale:'en',enabled:true,default:true}],business:{depositAmount:'100.00000000',depositAsset:'USDT',depositRefundWaitDays:7,withdrawalFeePercent:'1.00000000'},articles:[],sms:{profileId:null,profileName:null,available:false,profiles:[]},email:{profileId:null,profileName:null,available:false,profiles:[]}};
                    props={...props,section:key,settings:{[key]:fixtures[key],...(key==='branding'?{androidRelease:{revision:'fixture-revision',available:true,current:{appId:'__UNI__FIXTURE',versionName:'1.0.0',versionCode:100},downloadUrl:'https://download.example.test/app.apk',androidDownloadUrl:'https://download.example.test/app.apk',iosDistributionUrl:null}}:{}),supportedLocales:['en','zh-CN'],supportedAssets:['USDT']}};
                }
            }
            const state={component,props:{...shared,...props},url:url.pathname+url.search,version:'fixture'};
            if(request.headers()['x-inertia'])return route.fulfill({headers:{'X-Inertia':'true'},json:state});
            return route.fulfill({contentType:'text/html',body:`<!doctype html><html><head><meta charset="utf-8">${(entry.css??[]).map(f=>`<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state).replaceAll('<','\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`});
        });
        const list=origin+'/platform/tenants?search=offline&status=ACTIVE&page=2';
        const dialog=page.getByRole('dialog');
        await page.goto(list);
        await page.getByRole('link',{name:'Configure',exact:true}).first().waitFor();
        assert.equal(reads.length,0);
        assert.equal(await page.getByRole('link',{name:'Company configuration',exact:true}).count(),0);
        for(const width of [375,768,1440,1920]) {
            await page.setViewportSize({width,height:850});
            await page.getByRole('link',{name:'Configure',exact:true}).first().click();
            await dialog.getByText('Required foundation',{exact:true}).waitFor();
            const rect=await dialog.boundingBox();
            assert.ok(Math.abs(rect.x+rect.width-width)<2); assert.ok(rect.width<=1201); assert.equal(rect.y,0);
            assert.equal(await dialog.getByRole('tab').count(),16);
            await page.screenshot({path:`${out}/${name}-drawer-${width}.png`});
            await dialog.getByRole('button',{name:'Close',exact:true}).last().click();
            await dialog.waitFor({state:'hidden'});
        }
        await page.setViewportSize({width:1440,height:850});
        await page.getByRole('link',{name:'Configure',exact:true}).first().click();
        await dialog.getByText('Required foundation',{exact:true}).waitFor();
        const labels=['Domains','Brand and App','Locales','Business rules','Asset settings','Card products','Promotion','Wealth settings','About us articles','Aliyun SMS','Proton email','Admin team','Service hours','Quick replies','Bot and FAQ'];
        for(const label of labels) {
            const before=reads.length;
            await dialog.getByRole('tab',{name:label,exact:true}).click();
            await page.waitForFunction(()=>!document.querySelector('[data-admin-editor-body] [role=status]') && document.querySelector('[data-admin-editor-body]')?.textContent.trim().length>0);
            assert.equal(reads.length,before+1);
            assert.equal(await dialog.count(),1);
            assert.equal(new URL(page.url()).searchParams.get('page'),'2');
            assert.equal(new URL(page.url()).searchParams.get('search'),'offline');
            if(label==='Admin team') {
                await dialog.getByRole('button',{name:'Add administrator',exact:true}).click();
                assert.equal(await dialog.count(),1,'Inline team editor must not open another dialog');
                await dialog.getByRole('button',{name:'Cancel',exact:true}).click();
            }
            if(label==='Quick replies') {
                await dialog.getByRole('button',{name:'Add quick reply',exact:true}).click();
                assert.equal(await dialog.count(),1);
                assert.equal(await dialog.locator('select').count(),0);
                await dialog.getByRole('button',{name:'Cancel',exact:true}).click();
            }
        }
        assert.equal(writes.length,0);
        const beforeKeyboard=reads.length;
        await dialog.getByRole('tab',{name:'Bot and FAQ',exact:true}).focus();
        await page.keyboard.press('Home');
        assert.equal(await page.evaluate(()=>document.activeElement.textContent),'Basic information');
        assert.equal(reads.length,beforeKeyboard);
        await page.keyboard.press('Enter');await dialog.getByText('Required foundation',{exact:true}).waitFor();
        await dialog.getByRole('tab',{name:'Brand and App',exact:true}).click();
        await dialog.locator('#brand-name').fill('Unsaved fixture');
        const count=reads.length;
        page.once('dialog',d=>d.dismiss());
        await dialog.getByRole('tab',{name:'Wealth settings',exact:true}).click();
        assert.equal(reads.length,count);assert.equal(await dialog.locator('#brand-name').inputValue(),'Unsaved fixture');
        page.once('dialog',d=>d.accept());
        await dialog.getByRole('tab',{name:'Wealth settings',exact:true}).click();
        await dialog.locator('#minimum-USDT').waitFor();
        await dialog.getByRole('tab',{name:'Brand and App',exact:true}).click();
        await dialog.locator('#brand-name').fill('Save fixture');
        await dialog.locator('#android-version-name').fill('2.0.0');
        const save=dialog.getByRole('button',{name:'Save branding',exact:true});
        await save.click();assert.equal(await dialog.getByRole('tab',{name:'Wealth settings',exact:true}).isDisabled(),true);
        await dialog.getByText('This field is required.').waitFor();
        assert.equal(await dialog.locator('#brand-name').inputValue(),'Save fixture');
        saveFails=false;await save.click();await dialog.getByText('Saved successfully.',{exact:true}).waitFor();
        assert.equal(await dialog.count(),1,'Save keeps drawer open');assert.equal(writes.length,2);
        assert.equal(await dialog.locator('#android-version-name').inputValue(),'2.0.0','Saving branding preserves unsaved release fields');
        page.once('dialog',d=>d.accept());
        await dialog.getByRole('tab',{name:'Locales',exact:true}).click();await dialog.locator('#default-locale').waitFor();
        await page.goBack();await dialog.locator('#brand-name').waitFor();
        await page.goForward();await dialog.locator('#default-locale').waitFor();
        await page.reload();await dialog.locator('#default-locale').waitFor();
        failure='wealth';await dialog.getByRole('tab',{name:'Wealth settings',exact:true}).click();await dialog.getByRole('alert').waitFor();
        await dialog.getByRole('button',{name:'Retry',exact:true}).click();await dialog.locator('#minimum-USDT').waitFor();
        slow='settings/locales';
        await dialog.getByRole('tab',{name:'Locales',exact:true}).click();
        await dialog.getByRole('tab',{name:'Wealth settings',exact:true}).click();
        await dialog.locator('#minimum-USDT').waitFor();
        await page.waitForTimeout(450);
        assert.equal(await dialog.locator('#default-locale').count(),0,'Late DTO cannot replace selected category');
        mismatch=true;await dialog.getByRole('tab',{name:'Locales',exact:true}).click();await dialog.getByRole('alert').waitFor();
        assert.equal(await dialog.locator('form').count(),0);
        await dialog.getByRole('button',{name:'Close',exact:true}).last().click();await dialog.waitFor({state:'hidden'});
        await page.goto(list+'&editor='+encodeURIComponent(`/platform/tenants/${company.id}/configuration/wealth`));
        await dialog.locator('#minimum-USDT').waitFor();
        await page.goto(origin+'/admin/settings/branding');await page.locator('#brand-name').waitFor();
        assert.equal(await page.locator('[data-platform-header]').count(),0);
        assert.equal((await page.locator('#brand-name').boundingBox()).height,40);
        shared.auth.admin.permissions=['support.read','support.hours.manage'];
        await page.goto(list);
        await page.getByRole('link',{name:'Configure',exact:true}).first().click();
        await dialog.getByText('Company timezone',{exact:false}).waitFor();
        assert.equal(await dialog.getByRole('tab').count(),1);
        assert.equal(await dialog.getByRole('tab',{name:'Brand and App',exact:true}).count(),0);
        assert.deepEqual(errors,[]);assert.deepEqual(unexpected,[]);
        await context.close();console.log(`${name}: 16 sections, 4 widths, inline edits, save/dirty/busy guards, history, retries, company isolation passed`);
    } finally { await browser.close(); }
}
