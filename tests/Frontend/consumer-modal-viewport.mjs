import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
function harness() {
    const exports = {}, scrolls = [];
    const style = { position: 'relative', top: '', left: '', width: '95%', overflow: '' };
    const window = { scrollX: 0, scrollY: 850, innerWidth: 390, innerHeight: 750, visualViewport: { width: 390, height: 330, offsetTop: 70, offsetLeft: 0 }, scrollTo: (...p) => scrolls.push(p) };
    runInNewContext(ts.transpileModule(readFileSync('mobile/uni-app/src/lib/modal-viewport.ts', 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, { exports, window, document: { body: { style } } });
    return { api: exports, style, window, scrolls };
}
test('nested dialogs keep the background locked until the last closes and restore its position once', () => {
    const h = harness();
    const first = h.api.lockModalPage(), second = h.api.lockModalPage();
    assert.equal(h.style.position, 'fixed');
    assert.equal(h.style.top, '-850px');
    first(); first();
    assert.equal(h.style.position, 'fixed');
    assert.equal(h.scrolls.length, 0);
    second();
    assert.equal(h.style.position, 'relative');
    assert.equal(h.style.width, '95%');
    assert.deepEqual(h.scrolls, [[0, 850]]);
});
test('dialog placement uses the keyboard-visible viewport and falls back to the window', () => {
    const h = harness();
    assert.equal(h.api.modalViewportStyle()['--modal-height'], '330px');
    assert.equal(h.api.modalViewportStyle()['--modal-top'], '70px');
    h.window.visualViewport = null;
    assert.equal(h.api.modalViewportStyle()['--modal-height'], '750px');
    assert.equal(h.api.modalViewportStyle()['--modal-top'], '0px');
});
