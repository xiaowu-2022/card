import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

// Browser-only fixtures; every financial POST is intercepted. No provider request or live money mutation.
const browser=await chromium.launch({channel:'chrome',headless:true});
const context=await browser.newContext({locale:'zh-CN'});
const page=await context.newPage();
const errors=[];page.on('pageerror',error=>errors.push(error.message));
const base='http://a.localhost:8000';
const output='/tmp/card-management-browser';await mkdir(output,{recursive:true});
const cardId='11111111-1111-4111-8111-111111111111';
let quotedId=null;
let writes=0;
let managementEnabled=true;
const fixtureOrder={id:'22222222-2222-4222-8222-222222222222',kind:'load',state:'quoted',amount:'20.00000000',debit:'21.00000000',arrival:'20.00000000',fee:'1.00000000',expiresAt:'2026-09-11T12:00:30Z',createdAt:'2026-09-11T12:00:00Z'};
await page.route('**/*',route=>{
    const request=route.request();
    if(!['GET','HEAD','OPTIONS'].includes(request.method())&&new URL(request.url()).pathname!='/login'){
        errors.push('Unexpected live write blocked');return route.abort();
    }
    return route.continue();
});
await page.route('**/cards/*/management',async route=>{
    const input=route.request().postDataJSON();
    assert.equal(new URL(route.request().url()).pathname,`/cards/${cardId}/management`);
    writes++;
    if(input.action==='reveal'){assert.ok(input.current_password);return route.fulfill({json:{cvv:'852'}});}
    if(input.action==='quote'){assert.equal(input.amount,'20');quotedId=input.request_id;return route.fulfill({json:fixtureOrder});}
    if(input.action==='confirm'){assert.equal(input.order_id,fixtureOrder.id);assert.equal(input.confirmed,true);assert.ok(input.current_password);return route.fulfill({json:{...fixtureOrder,state:'confirming'}});}
    if(input.action==='sync')return route.fulfill({json:{...fixtureOrder,state:'completed'}});
    if(input.action==='history')return route.fulfill({json:{orders:[{...fixtureOrder,state:'completed'}]}});
    if(input.action==='refresh')return route.fulfill({json:{balance:'40.00000000',syncedAt:'2026-09-11T12:00:00Z'}});
    throw new Error('Unexpected fixture mutation');
});
await page.route('**/cards/*/transactions?*',route=>route.fulfill({json:{items:[],page:1,hasMore:false}}));
try{
    await page.goto(base+'/login');
    await page.locator('input[type=email]').fill('user@a.localhost');
    await page.locator('input[type=password]').fill('123456');
    await page.locator('button[type=submit]').click();await page.waitForURL('**/dashboard');
    await page.route('**/cards',async route=>{
        if(route.request().headers()['x-inertia']!=='true')return route.continue();
        const response=await route.fetch();const data=await response.json();
        data.props.cards=[{id:cardId,productName:'U Card',maskedPan:'•••• 1234',last4:'1234',expiry:'08/29',currency:'USD',balance:'20.00000000',minimumReload:'20.00000000',syncedAt:null,management:managementEnabled?['reveal','transactions','holder','load','return','freeze','cancel']:[]}];
        data.props.providerAvailable=true;data.props.i18n={...data.props.i18n,locale:'zh-CN'};
        await route.fulfill({response,json:data});
    });
    await page.locator('a[href="/cards"]').first().click();
    await page.getByRole('button',{name:'查看 CVV',exact:true}).waitFor();
    let responsiveChecks=0;
    for(const width of [375,768,1440]){
        await page.setViewportSize({width,height:1000});
        const primary=page.locator('[data-card-actions] button');
        assert.deepEqual(await primary.allTextContents(),['CVV','修改','充值','退回','流水','注销']);
        const boxes=await primary.evaluateAll(buttons=>buttons.map(button=>({top:button.getBoundingClientRect().top,width:button.getBoundingClientRect().width})));
        assert.equal(new Set(boxes.map(box=>box.top)).size,1);
        assert.ok(boxes.every(box=>box.width>=44));
        const shape=await page.locator('.user-card-group').first().evaluate(group=>{
            const card=group.querySelector('.user-card-visual'),controls=group.querySelector('.user-card-controls');
            return {groupWidth:group.getBoundingClientRect().width,cardWidth:card.getBoundingClientRect().width,radius:getComputedStyle(card).borderRadius,gap:controls.getBoundingClientRect().top-card.getBoundingClientRect().bottom};
        });
        assert.equal(shape.radius,'24px');
        assert.equal(shape.groupWidth,shape.cardWidth);
        assert.equal(shape.gap,0);
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        await page.screenshot({path:`${output}/actions-${width}.png`,fullPage:true});
        await page.getByRole('button',{name:'修改持卡人',exact:true}).click();
        await page.getByRole('dialog').waitFor();await page.getByRole('heading',{name:'账单地址',exact:true}).waitFor();
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        assert.ok(await page.getByRole('combobox').count()>=4);
        await page.screenshot({path:`${output}/holder-${width}.png`,fullPage:true});
        await page.keyboard.press('Escape');responsiveChecks+=2;
    }
    await page.getByRole('button',{name:'注销卡片',exact:true}).click();
    await page.getByText(/销卡后无法恢复/).waitFor();
    assert.equal(await page.getByRole('checkbox').isChecked(),false);
    await page.keyboard.press('Escape');
    await page.getByRole('button',{name:'卡片充值',exact:true}).click();
    await page.locator('#card-operation-amount').fill('20');
    await page.getByRole('button',{name:'获取充值报价',exact:true}).click();
    await page.getByText('钱包扣款: 21.00 USDT',{exact:true}).waitFor();
    await page.locator('#card-current-password').fill('fixture-password');
    await page.getByRole('checkbox').check();await page.getByRole('button',{name:'确认',exact:true}).click();
    await page.getByText('等待确认',{exact:true}).waitFor();
    await page.getByRole('button',{name:'查询结果',exact:true}).click();
    await page.getByText('已完成',{exact:true}).waitFor();
    await page.keyboard.press('Escape');
    await page.getByRole('button',{name:'查看 CVV',exact:true}).click();
    await page.locator('#card-current-password').fill('fixture-password');
    await page.getByRole('button',{name:'确认',exact:true}).click();
    await page.getByText('852',{exact:true}).waitFor();
    assert.equal(await page.locator('#card-current-password').count(),0);
    assert.ok(!(await page.evaluate(()=>JSON.stringify(history.state))).includes('"cvv"'));
    await page.keyboard.press('Escape');
    assert.equal(await page.getByText('852',{exact:true}).count(),0);
    assert.ok(quotedId);assert.equal(writes,4);assert.deepEqual(errors,[]);
    managementEnabled=false;
    await page.goto(base+'/dashboard');
    await page.locator('a[href="/cards"]').first().click();
    await page.getByText('不可用的操作已置灰。需要真实卡片、已配置的发卡服务，且卡片状态允许，才能操作。',{exact:true}).waitFor();
    for(const name of ['查看 CVV','交易流水','修改持卡人','卡片充值','注销卡片','资金退回']) {
        const button=page.getByRole('button',{name,exact:true});
        assert.equal(await button.count(),1);
        assert.equal(await button.isDisabled(),true);
    }
    for(const width of [375,768,1440]) {
        await page.setViewportSize({width,height:1000});
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        await page.screenshot({path:`${output}/unavailable-actions-${width}.png`,fullPage:true});
    }
    assert.equal(writes,4);
    assert.equal(await page.getByRole('dialog').count(),0);
    assert.deepEqual(errors,[]);
    console.log(JSON.stringify({responsiveChecks,quoteConfirmation:true,cvvClearedOnClose:true,liveFinancialWrites:0,screenshots:output}));
}catch(error){console.log(JSON.stringify({errors}));throw error;}finally{await page.unrouteAll({behavior:'ignoreErrors'});await browser.close();}
