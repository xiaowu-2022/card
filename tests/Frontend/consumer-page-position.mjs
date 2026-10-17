import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import ts from 'typescript';
test('warm returns restore scroll while changed identities discard the saved position',async()=>{
 const hooks={},watchers=[],scrolls=[],exports={};let scope='tenant:user',ready=true;
 runInNewContext(ts.transpileModule(readFileSync('mobile/uni-app/src/lib/page-position.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,{exports,uni:{pageScrollTo:v=>scrolls.push(v.scrollTop)},require:name=>{
 if(name==='vue')return {nextTick:async()=>{},watch:(source,fn)=>watchers.push(fn)};
 if(name==='./session')return {consumerSessionScope:()=>scope};
 if(name==='@dcloudio/uni-app')return {onHide:fn=>hooks.hide=fn,onShow:fn=>hooks.show=fn,onUnload:fn=>hooks.unload=fn,onPageScroll:fn=>hooks.scroll=fn};
 throw Error(name);
 }});
 exports.usePagePosition(()=>'/dashboard',()=>ready);
 await hooks.show();hooks.scroll({scrollTop:620});hooks.hide();await hooks.show();assert.equal(scrolls.at(-1),620);
 scope='tenant:other';watchers[0](scope);await hooks.show();assert.equal(scrolls.at(-1),0);
 hooks.scroll({scrollTop:400});hooks.hide();ready=false;const count=scrolls.length;await hooks.show();assert.equal(scrolls.length,count);
 ready=true;await watchers[2](true);assert.equal(scrolls.at(-1),400);
});
