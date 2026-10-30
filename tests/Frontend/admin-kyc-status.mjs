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

test('administrator verification details explain back validity failures with approved copy', () => {
    const detail = {};
    const source = readFileSync('resources/js/components/admin/KycDetailsContent.tsx', 'utf8');
    runInNewContext(ts.transpileModule(source + '\nexport { processingErrorMessages };', {
        compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
    }).outputText, { exports: detail, require: () => ({}) });
    assert.match(detail.processingErrorMessages.KYC_BACK_VALIDITY_UNRECOGNIZED, /validity period.*complete photo of the back/);
    assert.match(detail.processingErrorMessages.KYC_DOCUMENT_EXPIRED, /has expired/);
    assert.match(detail.processingErrorMessages.KYC_DOCUMENT_NOT_YET_VALID, /not yet valid/);
});
