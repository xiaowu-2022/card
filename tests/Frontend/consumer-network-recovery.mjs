import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import vm from 'node:vm';
function harness({pending = false, unavailable = false} = {}) {
    const state = {reloads:0, timers:[], nodes:[], stored:pending ? 'pending' : null};
    function node() {
        return {style:{}, children:[], attributes:{}, setAttribute(k,v){this.attributes[k]=v;}, append(child){this.children.push(child);},
            get firstElementChild(){return this.children[0];}, remove(){state.nodes = state.nodes.filter(n=>n!==this);}};
    }
    const document = {hidden:false, createElement:node, body:{append(n){state.nodes.push(n);}}};
    const context = vm.createContext({document, window:{location:{reload(){state.reloads++;}}},
        sessionStorage:{getItem(){if(unavailable) throw Error();return state.stored;},setItem(k,v){if(unavailable) throw Error();state.stored=v;},removeItem(){state.stored=null;}},
        setTimeout(fn){state.timers.push(fn);},t:s=>s});
    const source = readFileSync('mobile/uni-app/src/lib/network-recovery.ts','utf8').replace(/^import .*;$/gm,'').replace('export const networkRecovery', 'globalThis.recovery');
    vm.runInContext(ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.ES2022}}).outputText,context);
    return {state, document, recovery:context.recovery, buttons:()=>state.nodes.flatMap(n=>n.children.flatMap(p=>p.children)).filter(n=>n.onclick)};
}
test('simultaneous failures show one dialog and one explicit reload, with persisted guard',()=>{
    const h=harness(); h.recovery.failure(); h.recovery.failure(true);
    assert.equal(h.state.nodes.length,1);
    const button=h.buttons()[0]; button.onclick(); button.onclick();
    assert.equal(h.state.reloads,1); assert.equal(h.state.stored,'pending');
});
test('failed reload stays on manual retry and never reloads without a click',()=>{
    const h=harness({pending:true}); h.recovery.failure(); h.recovery.failure();
    assert.equal(h.buttons()[0].textContent,'Retry refresh');
    assert.equal(h.state.nodes[0].attributes.role,'status');
    assert.equal(h.state.nodes[0].attributes['aria-modal'],undefined);
    assert.equal(h.state.reloads,0); assert.equal(h.state.nodes.length,1);
    h.buttons()[1].onclick(); h.recovery.failure();
    assert.equal(h.state.nodes.length,0); assert.equal(h.state.reloads,0);
});
test('storage unavailable disables programmatic refresh; hidden documents do not show dialogs',()=>{
    const h=harness({unavailable:true}); h.recovery.failure();
    assert.equal(h.buttons().length,1); assert.equal(h.buttons()[0].textContent,'Keep current page');
    assert.equal(h.state.reloads,0);
    const hidden=harness(); hidden.document.hidden=true; hidden.recovery.failure(); assert.equal(hidden.state.nodes.length,0);
});
test('only a fresh successful page clears recovery; concurrent fragment failure retains it',()=>{
    const h=harness({pending:true}); const before=h.recovery.checkpoint();
    h.recovery.failure(); h.recovery.success(before); assert.equal(h.state.timers.length,0);
    h.recovery.success(h.recovery.checkpoint()); h.recovery.failure(); h.state.timers.shift()();
    assert.equal(h.state.stored,'pending');
    h.recovery.success(h.recovery.checkpoint()); h.state.timers.shift()();
    assert.equal(h.state.stored,null); assert.equal(h.state.nodes.length,0);
    h.recovery.failure(); assert.equal(h.buttons()[0].textContent,'Refresh page');
});

test('a late success cannot remove the pending guard after the user requests reload',()=>{
    const h=harness(); h.recovery.failure();
    h.recovery.success(h.recovery.checkpoint()); h.buttons()[0].onclick();
    h.state.timers.forEach(fn=>fn());
    assert.equal(h.state.stored,'pending'); assert.equal(h.state.reloads,1);
});
