import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { createRequire } from 'node:module';
import { resolve, dirname } from 'node:path';
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

test('manual review remains available for current failures but never for superseded or completed evidence', () => {
    const pending = { reviewStatus: 'PENDING', processingStatus: 'FAILED', isSuperseded: false, reviewable: true, retryable: true };
    assert.equal(exports.kycDisplayStatus(pending), 'FAILED');
    assert.equal(exports.kycReviewable(pending), true);
    assert.equal(exports.kycRetryable(pending), true);
    assert.equal(exports.kycReviewable({ ...pending, reviewable: false }), false);
    assert.equal(exports.kycRetryable({ ...pending, retryable: false }), false);
    assert.equal(exports.kycReviewable({ ...pending, isSuperseded: true }), false);
    assert.equal(exports.kycRetryable({ ...pending, isSuperseded: true }), false);
    for (const reviewStatus of ['APPROVED', 'REJECTED', 'RESUBMISSION_REQUIRED']) {
        assert.equal(exports.kycReviewable({ ...pending, reviewStatus }), false);
    }
    assert.equal(exports.kycRetryable({ ...pending, reviewStatus: 'APPROVED' }), false);
    assert.equal(exports.kycRetryable({ reviewStatus: 'REJECTED', retryable: true }), true);
    assert.equal(exports.kycRetryable({ reviewStatus: 'REJECTED', retryable: true, isSuperseded: true }), false);
});

test('older DTO compatibility preserves pending review without treating a completed decision as pending', () => {
    assert.equal(exports.kycReviewable({ reviewStatus: 'PENDING', processingStatus: 'FAILED' }), true);
    assert.equal(exports.kycReviewable({ reviewStatus: 'APPROVED', processingStatus: 'FAILED' }), false);
    assert.equal(exports.kycRetryable({ reviewStatus: 'REJECTED', processingStatus: 'COMPLETE' }), true);
    assert.equal(exports.kycRetryable({ reviewStatus: 'APPROVED', processingStatus: 'FAILED' }), false);
});

test('administrator verification details explain back validity failures with approved copy', () => {
    const detail = {};
    const source = readFileSync('resources/js/components/admin/KycDetailsContent.tsx', 'utf8');
    runInNewContext(ts.transpileModule(source + '\nexport { processingErrorMessages };', {
        compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
    }).outputText, { exports: detail, require: () => ({}) });
    assert.match(detail.processingErrorMessages.KYC_BACK_VALIDITY_UNRECOGNIZED, /back validity period.*manual review/);
    assert.match(detail.processingErrorMessages.KYC_DOCUMENT_EXPIRED, /has expired/);
    assert.match(detail.processingErrorMessages.KYC_DOCUMENT_NOT_YET_VALID, /not yet valid/);
});

test('administrator review errors use their own namespace while consumer guidance stays unchanged', () => {
    const cache = new Map();
    const require = createRequire(import.meta.url);
    function load(path) {
        path = resolve(path);
        if (cache.has(path)) return cache.get(path);
        const exports = {};
        cache.set(path, exports);
        runInNewContext(ts.transpileModule(readFileSync(path, 'utf8'), {
            compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
        }).outputText, { exports, require: id => id.startsWith('.') ? load(resolve(dirname(path), id + '.ts')) : require(id) });
        return exports;
    }
    const { clientResources, clientI18n } = load('resources/js/i18n/index.ts');
    const key = 'This application has already been reviewed.';
    const admin = clientI18n.t(key, { lng: 'zh-CN', ns: 'admin' });
    const consumer = clientI18n.t(key, { lng: 'zh-CN', ns: 'translation' });
    assert.match(admin, /刷新申请状态/);
    assert.doesNotMatch(admin, /客服/);
    assert.match(consumer, /客服/);
    assert.notEqual(admin, consumer);
    assert.match(clientI18n.t(key, { lng: 'en', ns: 'admin' }), /Refresh the application status/);
    const messages = {};
    runInNewContext(ts.transpileModule(readFileSync('resources/js/components/admin/KycDetailsContent.tsx', 'utf8')
        + '\nexport { processingErrorMessages };', {
        compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
    }).outputText, { exports: messages, require: () => ({}) });
    for (const message of Object.values(messages.processingErrorMessages)) {
        assert.ok(clientResources.en.admin[message]);
        assert.ok(clientResources['zh-CN'].admin[message]);
        assert.doesNotMatch(clientResources['zh-CN'].admin[message], /联系客服|重新上传/);
    }
});
