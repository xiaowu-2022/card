import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const js = ts.transpileModule(readFileSync('mobile/webview-shell/src/lib/directory.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.ES2022,target:ts.ScriptTarget.ES2022}}).outputText;
const {discover,origin}=await import('data:text/javascript;base64,'+Buffer.from(js).toString('base64'));
const a='https://a.example.com',b='https://b.example.com',c='https://c.example.com';
const reply=(origins,id='tenant',slug='company')=>({tenant:{id,slug},origins});
test('validates HTTPS candidates and excludes the static distribution host',()=>{
 for(const bad of ['http://a.example.com','https://a.example.com/x','https://u:p@a.example.com','javascript:alert(1)','https://zb33333.com'])assert.equal(origin(bad),null);
 assert.equal(origin(a),a);
});
test('downloads the authoritative list, probes newly discovered hosts and replaces old cache',async()=>{
 const calls=[];
 const result=await discover([a],'company',{tenantId:'tenant',origins:[a,b],selected:b},async host=>{
  calls.push(host);return reply([a,c]);
 });
 assert.deepEqual(result.origins,[a,c]);assert.ok([a,c].includes(result.selected));assert.ok(calls.includes(c));
});
test('keeps a verified previously selected host to preserve login cookies',async()=>{
 const result=await discover([a,b],'company',{tenantId:'tenant',selected:b,origins:[a,b]},async host=>{
  if(host===b)await new Promise(r=>setTimeout(r,20));return reply([a,b]);
 });assert.equal(result.selected,b);
});
test('recovers unavailable seeds using a verified cached domain',async()=>{
 const result=await discover([a],'company',{tenantId:'tenant',origins:[b],selected:b},async host=>{
  if(host===a)throw Error('offline');return reply([b]);
 });assert.equal(result.selected,b);
});
test('rejects a different tenant, wrong company and malformed directories',async()=>{
 for(const data of [reply([a],'other'),reply([a],'tenant','other'),reply([b]),{}])
  await assert.rejects(discover([a],'company',{tenantId:'tenant'},async()=>data));
});
test('all-offline fails visibly instead of loading an unverified cached host',async()=>{
 await assert.rejects(discover([a],'company',{tenantId:'tenant',origins:[b],selected:b},async()=>{throw Error('offline')}),/连接服务器/);
});
