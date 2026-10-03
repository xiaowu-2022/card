import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import ts from 'typescript';

test('preview tries unique URLs once, stops, and retries only on user action', () => {
    let state = 0;
    const exports = {};
    const jsx = (type, props, key) => ({ type, props, key });
    const code = ts.transpileModule(readFileSync('resources/js/components/shared/PreviewImage.tsx', 'utf8'), {
        compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
    }).outputText;
    vm.runInNewContext(code, {
        exports,
        require(name) {
            if (name === 'react') return { createElement: jsx, useState: () => [state, value => { state = typeof value === 'function' ? value(state) : value; }] };
            if (name === 'react/jsx-runtime') return { jsx, jsxs: jsx };
            return { t: value => value };
        },
    });
    const wrapper = exports.PreviewImage({ src: 'processed', sources: ['processed', 'original', 'replica', 'replica'] });
    const render = () => wrapper.type(wrapper.props);
    const first = render();
    assert.equal(first.props.src, 'processed');
    first.props.onError();
    first.props.onError(); // stale duplicate error cannot skip the original.
    assert.equal(render().props.src, 'original');
    render().props.onError();
    assert.equal(render().props.src, 'replica');
    render().props.onError();
    assert.equal(render().type, 'span');
    assert.equal(render().props.onError, undefined);
    render().props.onClick({ stopPropagation() {} });
    assert.equal(render().props.src, 'processed');
});
