import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const exports = {};
runInNewContext(ts.transpileModule(
    readFileSync('resources/js/components/admin/user-kyc-state.ts', 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.CommonJS } },
).outputText, { exports });

test('failed processing displays failed without changing the pending review', () => {
    const application = { reviewStatus: 'PENDING', processingStatus: 'FAILED' };
    assert.equal(exports.kycDisplayStatus(application), 'FAILED');
    assert.equal(application.reviewStatus, 'PENDING');
});

test('completed decisions override processing failure and active reviews remain pending', () => {
    for (const reviewStatus of ['APPROVED', 'REJECTED', 'RESUBMISSION_REQUIRED']) {
        assert.equal(exports.kycDisplayStatus({ reviewStatus, processingStatus: 'FAILED' }), reviewStatus);
    }
    for (const processingStatus of [null, undefined, 'QUEUED', 'PROCESSING', 'WAITING_REVIEW']) {
        assert.equal(exports.kycDisplayStatus({ reviewStatus: 'PENDING', processingStatus }), 'PENDING');
    }
});
