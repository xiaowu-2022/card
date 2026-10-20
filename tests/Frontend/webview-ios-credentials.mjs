import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import ts from 'typescript';
const token='12|'+'A'.repeat(64), scope='test-app:company';
const exports={};
runInNewContext(ts.transpileModule(readFileSync('mobile/webview-shell/src/lib/ios-credentials.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS}}).outputText,{
 exports,require:()=>({validToken:value=>/^[1-9][0-9]*\|[A-Za-z0-9]{64}$/.test(value)})
});
function setup(nativeString=false, numericBool=false){
 const state={file:null,protection:null,locked:false,backup:true,flags:[],released:[]};
 const classes=new Map([[0x20000000,'NSFileProtectionComplete'],[0x30000000,'NSFileProtectionCompleteUnlessOpen'],[0x40000000,'NSFileProtectionCompleteUntilFirstUserAuthentication']]);
 const bridge={
  newObject:(_name,value)=>({value}),deleteObject:object=>state.released.push(object),
  invoke(object,selector,...args){
   if(object==='NSFileManager')return {kind:'manager'};
   if(selector==='fileExistsAtPath:')return state.file!==null;
   if(selector==='attributesOfItemAtPath:error:')return {kind:'attributes'};
   if(selector==='objectForKey:')return nativeString?{kind:'string',value:state.protection}:state.protection;
   if(selector==='isEqualToString:')return numericBool ? Number(object.value===args[0]) : object.value===args[0];
   if(selector==='stringWithContentsOfFile:encoding:error:')return state.locked?null:state.file;
   if(selector==='dataUsingEncoding:')return {value:object.value};
   if(selector==='writeToFile:options:error:'){
    state.flags.push(args[1]);if(state.locked)return false;
    state.file=object.value;state.protection=classes.get(args[1]&0xf0000000);return true;
   }
   if(object==='NSURL')return {kind:'url'};
   if(object==='NSNumber')return true;
   if(selector==='setResourceValue:forKey:error:')return state.backup;
   if(selector==='removeItemAtPath:error:'){state.file=null;return true;}
   throw Error('Unexpected selector '+selector);
  }
 };
 return {state,vault:exports.iosCredentialVault(bridge,'/private/test-token.json',scope)};
}
for(const nativeString of [false,true])test('shell writes the same Data Protection class it verifies, including native NSString attributes: '+nativeString,()=>{
 const {state,vault}=setup(nativeString);
 vault.write(token);assert.equal(state.flags[0],0x40000001);assert.equal(vault.read(),token);
 state.file=JSON.stringify({scope:'another-company',token});assert.throws(()=>vault.read(),/Invalid/);
 vault.write(null);assert.equal(state.file,null);
});
test('shell rejects an unprotected file and retains valid protected credentials when locked',()=>{
 const {state,vault}=setup();vault.write(token);
 const saved=state.file;state.locked=true;assert.throws(()=>vault.read(),/locked/);assert.equal(state.file,saved);
 state.locked=false;state.protection='NSFileProtectionNone';assert.throws(()=>vault.read(),/unavailable/);
});
test('shell never accepts a credential when backup exclusion fails',()=>{
 const {state,vault}=setup();state.backup=false;assert.throws(()=>vault.write(token),/backup exclusion/);assert.equal(state.file,null);
});

test('native BOOL comparison may be returned as a numeric value',()=>{
 const {vault}=setup(true,true);vault.write(token);assert.equal(vault.read(),token);
});
