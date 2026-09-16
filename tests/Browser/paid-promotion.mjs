import assert from 'node:assert/strict';
import { mkdir, writeFile, readFile } from 'node:fs/promises';
import { chromium } from 'playwright';

// Only login writes are allowed. Financial scenarios are rendered from browser-only fixtures.
const output = process.env.PROMOTION_PREVIEW_DIR ?? '/tmp/card-paid-promotion';
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const errors = [], writes = [], checks = [];
const quick = process.env.PROMOTION_PREVIEW_FAST === '1';
const pattern = /<script[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/;
const now = new Date().toISOString();
const manifest=JSON.parse(await readFile('public/build/manifest.json','utf8'));
const entry=manifest['resources/js/app.tsx'];
const assets=(entry.css??[]).map(file=>`<link rel="stylesheet" href="/build/${file}">`).join('')+`<script type="module" src="/build/${entry.file}"></script>`;
const fees = ['1000','2000','5000','10000','20000','50000','100000','200000'];
const paid = {
    membershipStatus:'ACTIVE',previousCycle:null,availableBalance:'150000.00000000',
    teamByLevel:Array.from({length:9},(_,rank)=>({rank,direct:rank===1?2:rank===4?7:0,indirect:rank===4?5:0})),
    rank:6, percent:80, reward:'100', levels: fees.map((fee,i)=>({id:`preview-level-${i+1}`,rank:i+1,fee,percent:30+10*i,reward:String(50+10*i),target:[100,135,250,400,666,1428,2500,4000][i],revision:1,enabled:true})),
    cycle:{id:'preview-cycle',startsAt:now,endsAt:'2027-09-16T04:00:00Z',tariff:'50000'},
    totals:{ANNUAL:'46400.00000001',ACTIVATION:'8000'},legacy:'0',directPeople:9,indirectPeople:5,
    progress:{direct:1420,indirect:16,target:1428,paid:'50000',returned:'0',remaining:'50000'},pending:false,claims:[],
    tables:Object.fromEntries(['ANNUAL','ACTIVATION'].map(kind=>[kind,Array.from({length:9},(_,rank)=>({rank,direct:{count:rank===1?2:0,amount:rank===1?(kind==='ANNUAL'?'1600':'190'):'0',minimum:kind==='ANNUAL'?'80':'90',maximum:kind==='ANNUAL'?'80':'100'},indirect:{count:rank===4?3:0,amount:rank===4?'120':'0',minimum:'20',maximum:'40'}}))])),
};
const claim={id:'preview-claim',rank:6,amount:'50000',status:'PENDING',target:1428,direct:1420,indirect:16,createdAt:now,reviewedAt:null,reason:null,accountId:'202609160001',reviewer:null};
try {
    for (const admin of quick ? [false] : [false,true]) {
        const base = admin?'http://admin.localhost:8000':'http://a.localhost:8000';
        const context=await browser.newContext({permissions:['local-network-access']});
        await context.route('**/*', async route => {
            const req=route.request();
            if(req.isNavigationRequest() && req.method()==='GET' && new URL(req.url()).origin===base) {
                const response=await route.fetch();
                const html=await response.text();
                return route.fulfill({response,body:html.replace(/<script\b[^>]*type="module"[^>]*>[\s\S]*?<\/script>/g,'').replace('</head>',assets+'</head>')});
            }
            return route.continue();
        });
        const page=await context.newPage();
        page.setDefaultTimeout(30000);page.setDefaultNavigationTimeout(45000);
        page.on('pageerror',e=>errors.push(e.message));
        await page.goto(base+(admin?'/platform/login':'/login'),{waitUntil:'domcontentloaded'});
        await page.locator(admin?'input[type=email]':'#identifier').fill(admin?'owner@platform.local':'user@a.localhost');
        await page.locator(admin?'input[type=password]':'#password').fill(process.env.PROMOTION_TEST_PASSWORD??'123456');
        await page.locator(admin?'form button':'form button[type=submit]').click();
        await page.waitForURL(admin?'**/platform/tenants':'**/dashboard');
        const realUrl=admin?'/platform/tenants':'/promotion/invitations';
        const response=await page.request.get(base+realUrl);
        assert.equal(response.status(),200);
        const raw=await response.text();
        const initial=JSON.parse(raw.match(pattern)[1]);
        if(!admin){assert.equal(initial.props.promotion.paid.rank,0);await page.goto(base+'/promotion/invitations');await page.screenshot({path:output+'/actual-promotion.png',fullPage:true});}
        let fixture;
        await page.route('**/*',async route=>{
            const req=route.request(),url=new URL(req.url());
            if(url.origin!==base)return route.continue();
            if(!['GET','HEAD','OPTIONS'].includes(req.method())){writes.push(url.pathname);return route.abort();}
            if(url.pathname==='/__paid-promotion-preview')return route.fulfill({status:200,contentType:'text/html',body:raw.replace(/<script\b[^>]*type="module"[^>]*>[\s\S]*?<\/script>/g,'').replace('</head>',assets+'</head>').replace(pattern,()=>`<script data-page="app" type="application/json">${JSON.stringify(fixture).replaceAll('<','\\u003c')}</script>`)});
            return route.fallback();
        });
        for(const width of quick ? [375] : [375,768,1440])for(const locale of quick ? ['zh-CN'] : admin?['zh-CN','en']:['zh-CN','en','ms','es'])for(const screen of admin?['admin']:['overview','membership','choices','records']){
            fixture=structuredClone(initial);
            fixture.props.i18n.locale=locale;fixture.props.i18n.enabledLocales=['zh-CN','en','ms','es'];fixture.props.errors={};fixture.props.flash={};
            if(screen==='overview'){
                fixture.component='user/Promotion';fixture.url='/promotion/invitations';fixture.props.section='invitations';fixture.props.promotion={...fixture.props.promotion,myCommission:'54400.00000001',paid:structuredClone(paid)};
            }else if(screen==='membership'){
                fixture.component='user/PaidPromotion';fixture.url='/promotion/membership';fixture.props.paid=structuredClone(paid);
                fixture.props.paid.claims=[{...claim,status:'REJECTED',reason:'Preview / 预览'}];
                fixture.props.quote={id:'preview-quote',rank:7,amount:'50000',previousTariff:'50000',tariff:'100000',expiresAt:new Date(Date.now()+300000).toISOString(),status:'QUOTED',cycleId:'preview-cycle'};
            }else if(screen==='choices'){
                fixture.component='user/PaidPromotion';fixture.url='/promotion/membership';fixture.props.paid=structuredClone(paid);
                fixture.props.paid.rank=0;fixture.props.paid.percent=0;fixture.props.paid.reward='20';fixture.props.paid.cycle=null;fixture.props.paid.progress=null;fixture.props.paid.membershipStatus='NONE';fixture.props.quote=null;
            }else if(screen==='records'){
                fixture.component='user/PromotionRewardDetails';fixture.url='/promotion/rewards?kind=ACTIVATION&rank=1';fixture.props.details={kind:'ACTIVATION',rank:1,page:1,hasMore:false,items:[{id:'one',direct:true,rate:'90',amount:'90',sourceAmount:'300',occurredAt:now,accountId:'202609160001'},{id:'two',direct:true,rate:'100',amount:'100',sourceAmount:'300',occurredAt:now,accountId:'202609160002'}]};
            }else{
                fixture.component='platform/PaidPromotion';fixture.url='/platform/tenants/preview/configuration/paid-promotion';fixture.props.paid={companyName:'Preview Company',tenantId:'preview',canReview:true,levels:paid.levels,claims:[claim],page:1,hasMore:false};
            }
            await page.setViewportSize({width,height:900});await page.goto(base+'/__paid-promotion-preview',{waitUntil:'networkidle'});
            assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${screen} ${locale} ${width} overflow`);
            if(screen==='overview'){
                assert.equal(await page.locator('#team-summary table').count(),1);
                assert.equal(await page.locator('#team-summary thead th').count(),5);
                assert.equal(await page.locator('[data-reward-rank]').count(),2);
                await page.locator('[data-reward-rank="1"] button').click();
                assert.ok((await page.locator('[data-reward-rank="1"]').innerText()).includes('90–100'));
                assert.ok((await page.locator('[data-reward-rank="1"]').innerText()).includes('1,600.00'));
                assert.ok((await page.locator('[data-reward-rank="1"]').innerText()).includes('190.00'));
                assert.equal(await page.locator('[data-reward-rank="1"] > tr').first().locator('td').first().innerText(),'2');
                assert.ok(await page.locator('#team-summary table').evaluate(el=>el.scrollWidth<=el.clientWidth+1));
                await page.locator('#team-summary > div button').click();
                assert.equal(await page.locator('[data-reward-rank]').count(),9);
                await page.locator('#team-summary > div button').click();
                await page.locator('[data-reward-rank="1"] button').click();
            }
            if(screen==='choices'){
                assert.equal(await page.locator('input[type=radio]').count(),8);
                assert.equal(await page.locator('input[type=radio]:checked').count(),0);
                assert.ok(await page.locator('button[type=submit]').isDisabled());
                await page.locator('input[type=radio]').nth(2).check();
                assert.ok(await page.locator('button[type=submit]').isEnabled());
            }
            if(screen==='membership'&&locale==='zh-CN'){
                await page.getByRole('button',{name:'确认支付年费',exact:true}).click();await page.getByRole('dialog').waitFor();
                assert.ok(await page.getByRole('dialog').locator('input[type=password]').count());
                await page.screenshot({path:`${output}/${width}-confirmation.png`,fullPage:true});await page.keyboard.press('Escape');
            }
            if(screen==='admin'){await page.locator('summary').click();assert.equal(await page.locator('form').count(),9);}
            if(locale==='zh-CN')await page.screenshot({path:`${output}/${width}-${screen}.png`,fullPage:true});
            checks.push({width,locale,screen});
        }
        if(!admin){
            for(const width of quick ? [375] : [375,768,1440])for(const scenario of ['ordinary','expired','highest','pending','quote-expired','insufficient','empty','long-amounts','restricted']){
                fixture=structuredClone(initial);
                fixture.props.i18n.locale='zh-CN';fixture.props.i18n.enabledLocales=['zh-CN','en','ms','es'];
                fixture.props.errors={};fixture.props.flash={};
                const state=structuredClone(paid);
                if(['ordinary','expired','empty'].includes(scenario)){
                    state.rank=0;state.percent=0;state.reward='20';state.cycle=null;state.progress=null;
                    state.membershipStatus=scenario==='expired'?'EXPIRED':'NONE';
                    state.previousCycle=scenario==='expired'?{rank:4,endsAt:'2025-01-01T00:00:00Z'}:null;
                }
                if(scenario==='highest'){state.rank=8;state.cycle.tariff='200000';}
                if(scenario==='pending'){state.pending=true;state.claims=[claim];}
                fixture.component='user/PaidPromotion';fixture.url='/promotion/membership';fixture.props.paid=state;fixture.props.quote=null;
                if(['quote-expired','insufficient'].includes(scenario)){
                    fixture.props.quote={id:'preview-quote',rank:7,amount:'50000',previousTariff:'50000',tariff:'100000',expiresAt:new Date(Date.now()+(scenario==='quote-expired'?-60000:300000)).toISOString(),status:'QUOTED',cycleId:'preview-cycle'};
                    if(scenario==='insufficient')state.availableBalance='0';
                }
                if(scenario==='empty'){
                    state.teamByLevel=Array.from({length:9},(_,rank)=>({rank,direct:0,indirect:0}));state.directPeople=0;state.indirectPeople=0;state.tables=structuredClone(initial.props.promotion.paid.tables);state.totals={ANNUAL:'0',ACTIVATION:'0'};state.legacy='16880';
                    fixture.component='user/Promotion';fixture.url='/promotion/invitations';fixture.props.section='invitations';fixture.props.promotion={...initial.props.promotion,paid:state};
                }
                if(scenario==='restricted'){
                    fixture.component='user/Promotion';fixture.url='/promotion/invitations';fixture.props.section='invitations';fixture.props.promotion={...initial.props.promotion,paid:state,canTransfer:false,commissionRefundRestricted:true};
                }
                if(scenario==='long-amounts'){
                    state.tables=Object.fromEntries(['ANNUAL','ACTIVATION'].map(kind=>[kind,Array.from({length:9},(_,rank)=>({rank,direct:{count:rank===1?1:0,amount:rank===1?(kind==='ANNUAL'?'999999999999.99000000':'0.00000001'):'0',minimum:'0.00000001',maximum:'0.00000001'},indirect:{count:0,amount:'0',minimum:'0',maximum:'0'}}))]));
                    state.totals={ANNUAL:'999999999999.99000000',ACTIVATION:'0.00000001'};state.legacy='0';
                    fixture.component='user/Promotion';fixture.url='/promotion/invitations';fixture.props.section='invitations';fixture.props.promotion={...initial.props.promotion,myCommission:'999999999999.99000001',paid:state};
                }
                await page.setViewportSize({width,height:900});await page.goto(base+'/__paid-promotion-preview',{waitUntil:'networkidle'});
                assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),scenario+' overflow');
                if(scenario==='long-amounts'){
                    assert.ok((await page.locator('#team-summary').innerText()).includes('<0.01'));
                    assert.ok(await page.locator('#team-summary table').evaluate(el=>el.scrollWidth<=el.clientWidth+1));
                    await page.locator('[data-reward-rank="1"] button').click();
                    assert.ok((await page.locator('#reward-detail-1').innerText()).includes('0.00000001 USDT'));
                }
                if(scenario==='restricted')assert.ok(await page.getByRole('button',{name:'佣金转入余额',exact:true}).isDisabled());
                if(scenario==='ordinary')assert.ok(await page.getByRole('heading',{name:'申请推广等级',exact:true}).count());
                if(scenario==='expired')assert.ok(await page.getByRole('heading',{name:'续费推广等级',exact:true}).count());
                if(scenario==='highest')assert.equal(await page.locator('input[type=radio]').count(),0);
                if(scenario==='pending')assert.ok(await page.locator('input[type=radio]').first().isDisabled());
                if(['quote-expired','insufficient'].includes(scenario))assert.ok(await page.getByRole('button',{name:'确认支付年费',exact:true}).isDisabled());
                if(scenario==='empty'){
                    assert.equal(await page.locator('[data-reward-rank]').count(),0);
                    assert.ok((await page.locator('.promotion-commission').innerText()).includes('16,880.00 USDT'));
                    assert.equal(await page.locator('a[href="/promotion/commissions"]').count(),2);
                }
                await page.screenshot({path:output+'/'+width+'-'+scenario+'.png',fullPage:true});
                checks.push({width,scenario,locale:'zh-CN'});
            }
        }
        await context.close();
    }
    assert.deepEqual(writes,[]);assert.deepEqual(errors,[]);
    await writeFile(output+'/report.json',JSON.stringify({checks,errors,writes,fixtures:'browser-only'},null,2));
    console.log(JSON.stringify({checks:checks.length,errors,writes,output}));
} finally {await browser.close();}
