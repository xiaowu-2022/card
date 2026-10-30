<?php

use App\Domain\Kyc\DTOs\KycDocumentValidityDTO;
use App\Domain\Kyc\Services\DocumentValidity;
use App\Infrastructure\Providers\Kyc\NationalIdValidityParser;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;

it('uses the current Beijing calendar date inclusively for both validity boundaries', function (string $clock, ?string $error): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse($clock));
    try {
        $validity = new KycDocumentValidityDTO('2026-10-30', '2026-10-30', false);
        if ($error === null) {
            (new DocumentValidity)->assertCurrent($validity);
            expect(true)->toBeTrue();
        } else {
            try {
                (new DocumentValidity)->assertCurrent($validity);
                $this->fail('The current Beijing date must fall inside the validity period.');
            } catch (DomainException $exception) {
                expect($exception->errorCode)->toBe($error);
            }
        }
    } finally {
        CarbonImmutable::setTestNow();
    }
})->with([
    'before start in Beijing' => ['2026-10-29T15:59:59Z', 'KYC_DOCUMENT_NOT_YET_VALID'],
    'first second in Beijing' => ['2026-10-29T16:00:00Z', null],
    'last second in Beijing' => ['2026-10-30T15:59:59Z', null],
    'past end in Beijing' => ['2026-10-30T16:00:00Z', 'KYC_DOCUMENT_EXPIRED'],
]);

it('never treats malformed validity structures as a permanent document', function (string $from, ?string $until, bool $longTerm): void {
    try {
        (new DocumentValidity)->assertStructure(new KycDocumentValidityDTO($from, $until, $longTerm));
        $this->fail('Malformed validity must fail closed.');
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe('KYC_BACK_VALIDITY_UNRECOGNIZED');
    }
})->with([
    'empty start' => ['', null, true],
    'non-normalized start' => ['2020-1-1', null, true],
    'impossible leap date' => ['2023-02-29', '2033-02-28', false],
    'impossible end date' => ['2020-01-01', '2030-04-31', false],
    'reversed dates' => ['2030-01-01', '2020-01-01', false],
    'missing end' => ['2020-01-01', null, false],
    'long term contradicts end' => ['2020-01-01', '2030-01-01', true],
]);

it('normalizes clear labelled back validity periods without retaining other printed fields', function (string $text, string $from, ?string $until, bool $longTerm): void {
    $validity = (new NationalIdValidityParser)->parse($text);
    expect($validity)->toBeInstanceOf(KycDocumentValidityDTO::class)
        ->and($validity->validFrom)->toBe($from)->and($validity->validUntil)->toBe($until)
        ->and($validity->longTerm)->toBe($longTerm);
})->with([
    'fullwidth' => ['有效期限：２０２０．０１．０１－２０３０．０１．０１', '2020-01-01', '2030-01-01', false],
    'compact dates' => ['有效期20200101~20300101', '2020-01-01', '2030-01-01', false],
    'leap date' => ['有效期 2020/2/29 至 2040/2/29', '2020-02-29', '2040-02-29', false],
    'line breaks' => ["签发机关：合成测试机关\n有效期限\n2020年1月1日 至 2030年1月1日", '2020-01-01', '2030-01-01', false],
    'repeated same evidence' => ["有效期2020.01.01-2030.01.01\n有效期限2020.01.01-2030.01.01", '2020-01-01', '2030-01-01', false],
    'explicit long term' => ['有效期限2020.01.01-长期', '2020-01-01', null, true],
]);

it('does not guess back validity from unlabelled ambiguous or incomplete OCR text', function (string $text): void {
    expect((new NationalIdValidityParser)->parse($text))->toBeNull();
})->with([
    '', '2020.01.01-2030.01.01', '出生日期1990.01.01', '有效期限长期',
    '有效期限2020.01.01', '有效期限2020.01.01-2030.02.30',
    "有效期2020.01.01-2030.01.01\n有效期限2021.01.01-2031.01.01",
    '有效期限2020.01.01-2030.01.01 另一个日期2031.01.01',
    '有效期限2020.01.01-2030.01.01 长期',
    '有效期限2020.01.01-长期限',
    '有效期限2020.01.01-2030.01.01.2020',
]);
