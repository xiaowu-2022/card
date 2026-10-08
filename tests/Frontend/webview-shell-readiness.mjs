import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync,existsSync} from 'node:fs';
import ts from 'typescript';
import {chromium} from 'playwright';
const source=ts.transpileModule(readFileSync('mobile/webview-shell/src/lib/readiness.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.ES2022}}).outputText;
const {readinessScript}=await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));
test('readiness rejects missing scripts and zero-size content; accepts painted H5 and restores title',async()=>{
 const chrome='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
 const browser=await chromium.launch({headless:true,...(existsSync(chrome)?{executablePath:chrome}:{})});
 try{
  const page=await browser.newPage({viewport:{width:393,height:800}});
  await page.setContent('<title>Spec Pay</title><div id="app"></div>');
  await page.evaluate(readinessScript('missing'));await page.waitForTimeout(100);
  assert.equal(await page.title(),'Spec Pay');
  await page.setContent('<title>Spec Pay</title><uni-page-body style="display:block;height:0;overflow:hidden"><button>Sign in</button></uni-page-body>');
  await page.evaluate(readinessScript('zero'));await page.waitForTimeout(100);
  assert.equal(await page.title(),'Spec Pay');
  await page.evaluate(()=>{document.querySelector('uni-page-body').style.height='600px';window.seenTitles=[];new MutationObserver(()=>window.seenTitles.push(document.title)).observe(document.querySelector('title'),{childList:true});});
  await page.evaluate(readinessScript('painted'));
  await page.waitForFunction(()=>window.seenTitles.includes('painted'));
  await page.waitForFunction(()=>document.title==='Spec Pay');
 }finally{await browser.close();}
});

test('diagnostics redact secrets and restore console/listeners on remote disable',async()=>{
 const source=ts.transpileModule(readFileSync('mobile/webview-shell/src/lib/debug.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.ES2022}}).outputText;
 const {debugScript,debugMessages,safeAddress}=await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));
 assert.equal(safeAddress('https://27m.my/?token=SECRET#/pages/login/index?password=SECRET'),'https://27m.my/#/pages/login/index');
 assert.equal(safeAddress('https://user:SECRET@27m.my/'),'(未打开 HTTPS 网页)');
 assert.deepEqual(debugMessages(JSON.stringify([{kind:'console',level:'error',types:['SECRET','string'],message:'SECRET'}])),['console.error (string) [内容已脱敏]']);
 const chrome='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
 const browser=await chromium.launch({headless:true,...(existsSync(chrome)?{executablePath:chrome}:{})});
 try{
  const page=await browser.newPage();await page.setContent('<title>Spec Pay</title>');
  await page.evaluate(()=>{window.originalConsole=console.error;window.titles=[];new MutationObserver(()=>window.titles.push(document.title)).observe(document.querySelector('title'),{childList:true});});
  await page.evaluate(debugScript('test-debug:',true));
  await page.evaluate(()=>{console.error('password=SECRET',{token:'SECRET'});window.dispatchEvent(new ErrorEvent('error',{error:new TypeError('SECRET'),message:'SECRET',lineno:12}));});
  await page.waitForFunction(()=>window.titles.some(s=>s.startsWith('test-debug:')));
  const titles=await page.evaluate(()=>window.titles);assert.ok(!JSON.stringify(titles).includes('SECRET'));
  const messages=debugMessages(titles.find(t=>t.startsWith('test-debug:')).slice(11));
  assert.ok(messages.some(s=>s.includes('console.error')));assert.ok(messages.some(s=>s.includes('TypeError')));
  await page.evaluate(debugScript('',false));
  assert.equal(await page.evaluate(()=>console.error===window.originalConsole),true);
  assert.equal(await page.title(),'Spec Pay');
  const before=await page.evaluate(()=>window.titles.length);
  await page.evaluate(()=>console.error('SECRET'));await page.waitForTimeout(1100);
  assert.equal(await page.evaluate(()=>window.titles.length),before);
 }finally{await browser.close();}
});
