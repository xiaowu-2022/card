import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import ts from 'typescript';
function setup(){
 const hooks={},events={},intervals=new Map(),checks=[],calls={resume:0,unread:0,version:0};let id=0;
 const source=readFileSync('mobile/uni-app/src/App.vue','utf8').match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1];
 runInNewContext(ts.transpileModule(source,{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,{
 exports:{},Date,document:{hidden:false,addEventListener:(name,fn)=>events[name]=fn},window:{addEventListener:(name,fn)=>events[name]=fn},uni:{hideTabBar(){}},
 setInterval:fn=>{intervals.set(++id,fn);return id;},clearInterval:id=>intervals.delete(id),
 require:name=>{
 if(name==='@dcloudio/uni-app')return {onShow:fn=>hooks.show=fn,onHide:fn=>hooks.hide=fn};
 if(name==='vue')return {watch(){}};
 if(name==='./lib/session')return {session:{value:{user:{id:'u'}}},confirmSession:()=>new Promise((resolve,reject)=>checks.push({resolve,reject})),refreshUnread:async()=>{calls.unread++;}};
 if(name==='./lib/app-update')return {checkAppUpdate:()=>{calls.version++;}};
 if(name==='./lib/support-reminder')return {pauseSupportReminder(){},resumeSupportReminder(){}};
 if(name==='./lib/support-realtime')return {onSupportRealtime(){},pauseSupportRealtime(){},resumeSupportRealtime(){calls.resume++;},restartSupportRealtime(){},supportRealtimeConnected:()=>true};
 throw Error(name);
 }});
 return {hooks,events,checks,calls,intervals};
}
const flush=()=>new Promise(resolve=>setImmediate(resolve));
test('native show, visibility and focus cause one background check and retain mounted route',async()=>{
 const s=setup();s.hooks.show();s.events.focus();s.events.visibilitychange();assert.equal(s.checks.length,1);
 s.checks[0].resolve(true);await flush();assert.equal(s.calls.resume,1);assert.equal(s.intervals.size,1);
 s.hooks.hide();assert.equal(s.intervals.size,0);s.hooks.show();assert.equal(s.checks.length,2);assert.equal(s.calls.version,1);
 s.checks[1].resolve(true);await flush();assert.equal(s.calls.resume,2);
});
test('hidden completion cannot restart sockets and network failures retry quietly',async()=>{
 const s=setup();s.hooks.show();s.hooks.hide();s.checks[0].resolve(true);await flush();assert.equal(s.calls.resume,0);assert.equal(s.intervals.size,0);
 s.hooks.show();s.checks[1].reject(Error('offline'));await flush();assert.equal(s.intervals.size,1);assert.equal(s.calls.resume,0);
 [...s.intervals.values()][0]();s.checks[2].resolve(true);await flush();assert.equal(s.calls.resume,1);assert.equal(s.intervals.size,1);
});
