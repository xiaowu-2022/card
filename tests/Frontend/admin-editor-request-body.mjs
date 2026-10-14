import test from 'node:test';
import assert from 'node:assert/strict';
import { editorRequestBody } from '../../resources/js/components/admin/editor-request-body.ts';

test('first domain assignment and unassign-all preserve explicit empty arrays', () => {
    for (const data of [
        { domain_ids: ['DOMAIN'], original_ids: [], confirmed: true },
        { domain_ids: [], original_ids: ['DOMAIN'], confirmed: true },
    ]) {
        const request = editorRequestBody(data, 'post');
        assert.equal(request.headers['Content-Type'], 'application/json');
        assert.deepEqual(JSON.parse(request.body), data);
    }
});
test('file uploads retain multipart and method override without forcing a boundary', () => {
    const request = editorRequestBody({ logo: new Blob(['offline']), confirmed: true }, 'put');
    assert.ok(request.body instanceof FormData);
    assert.equal(request.body.get('_method'), 'PUT');
    assert.equal(request.body.get('confirmed'), '1');
    assert.ok(request.body.get('logo') instanceof Blob);
    assert.deepEqual(request.headers, {});
});
