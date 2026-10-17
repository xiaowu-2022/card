import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import ts from 'typescript';
const source=readFileSync('mobile/uni-app/src/pages/assets/index.vue','utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function setup(){
 let scope='first';const requests=[],hooks={},watches=[],auth=[];
 class ApiError extends Error {constructor(status){super();this.status=status;}}
 const context={ref:value=>({value}),computed:fn=>({get value(){return fn();}}),watch:(fn,cb)=>watches.push(cb),
  onShow:fn=>hooks.show=fn,onHide:fn=>hooks.hide=fn,onBeforeUnmount:fn=>hooks.unmount=fn,
  usePagePosition(){},beginTiming:()=>()=>{},consumerSessionScope:()=>scope,
  requireUser:async options=>{auth.push(options);return true;},session:{value:{user:{id:'user'},restricted:false}},
  request:path=>new Promise((resolve,reject)=>requests.push({path,resolve,reject})),
  setCurrentPage(){},go(){},explainError:()=>({form:'Unavailable'}),ApiError,
 };
 const script=source.split('<script setup lang="ts">')[1].split('</script>')[0].replace(/^import .*;\n/gm,'');
 runInNewContext(ts.transpileModule(script+'\nglobalThis.state={data,activity,activityFailed,updating,load};',{compilerOptions:{target:ts.ScriptTarget.ES2022}}).outputText,context);
 return {...context,requests,hooks,auth,state:context.state,changeScope(){scope='second';watches.forEach(fn=>fn());}};
}
const summary={assetOverview:{estimate:null,assets:[{asset:'USDT',available:'12.34000000'}]},kycStatus:'APPROVED',wallet:null};
test('summary renders independently while history remains pending or fails, without false zero totals',async()=>{
 const h=setup();h.hooks.show();await tick();
 assert.deepEqual(h.requests.map(r=>r.path),['/home/summary','/home/activity']);
 assert.equal(h.auth[0].ensureWallet,false);assert.equal(h.state.data.value,null);assert.equal(h.state.activity.value,null);
 h.requests[0].resolve(summary);await tick();assert.equal(h.state.data.value,summary);
 assert.equal(h.state.data.value.assetOverview.estimate,null);assert.equal(h.state.activity.value,null);
 h.requests[1].reject(Error('history offline'));await tick();
 assert.equal(h.state.data.value,summary);assert.equal(h.state.activityFailed.value,true);assert.equal(h.state.activity.value,null);
});
test('a short background return retains summary/history and avoids duplicate section reloads',async()=>{
 const h=setup();h.hooks.show();await tick();h.requests[0].resolve(summary);h.requests[1].resolve({activity:[]});await tick();
 h.hooks.hide();h.hooks.show();await tick();assert.equal(h.requests.length,2);assert.equal(h.state.data.value,summary);
});
test('late responses cannot restore another session or resurrect content after authorization is lost',async()=>{
 const h=setup();h.hooks.show();await tick();h.changeScope();h.requests[0].resolve(summary);h.requests[1].resolve({activity:[]});await tick();
 assert.equal(h.state.data.value,null);assert.equal(h.state.activity.value,null);
 const revoked=setup();revoked.hooks.show();await tick();revoked.requests[1].reject(new revoked.ApiError(403));await tick();
 revoked.requests[0].resolve(summary);await tick();assert.equal(revoked.state.data.value,null);
});
