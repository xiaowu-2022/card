import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import ts from 'typescript';
const compile=path=>ts.transpileModule(readFileSync(path,'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText;
test('startup measurements are bounded numeric-only memory records with no credentials or URLs',()=>{
 const exports={},context={exports};runInNewContext(compile('mobile/uni-app/src/lib/startup-timing.ts'),context);
 let clock=0;const end=exports.beginTiming('bootstrap',()=>clock);clock=125;end();end();
 assert.equal(exports.timingSnapshot().length,1);assert.equal(exports.timingSnapshot()[0].durationMs,125);
 for(let i=0;i<50;i++)exports.beginTiming('homeSummary',()=>clock)();
 exports.beginTiming('https://secret.invalid/token',()=>clock)();
 const rows=exports.timingSnapshot();assert.equal(rows.length,40);
 for(const row of rows)assert.deepEqual(Object.keys(row).sort(),['durationMs','outcome','sequence','stage']);
 assert.doesNotMatch(JSON.stringify(context.__consumerStartupTimings),/secret|token|https|user|cookie/i);
 rows[0].stage='tampered';assert.notEqual(exports.timingSnapshot()[0].stage,'tampered');
});
test('opt-in diagnostics accept only known timing phases and bounded numeric durations',()=>{
 const exports={};runInNewContext(compile('mobile/webview-shell/src/lib/debug.ts'),{exports});
 const rows=exports.debugMessages(JSON.stringify([{kind:'timing',stage:'homeSummary',durationMs:120},{kind:'timing',stage:'password',durationMs:123},{kind:'timing',stage:'bootstrap',durationMs:700000}]));
 assert.equal(rows.length,1);assert.match(rows[0],/homeSummary: 120ms/);
});
