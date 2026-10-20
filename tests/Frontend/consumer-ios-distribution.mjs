import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import ts from 'typescript';
const exports={};runInNewContext(ts.transpileModule(readFileSync('mobile/uni-app/src/lib/ios-distribution.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS}}).outputText,{exports,URL});
test('installation manifest uses current HTTPS origin and the explicit PHP file',()=>{
 const url=new URL(exports.iosManifestUrl('https://current.example','U 卡.ipa'));
 assert.equal(url.origin,'https://current.example');assert.equal(url.pathname,'/ipa/index.php');assert.equal(url.searchParams.get('manifest'),'U 卡.ipa');assert.equal(url.port,'');
 assert.throws(()=>exports.iosManifestUrl('http://current.example','app.ipa'),/HTTPS/);
});
