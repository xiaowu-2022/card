<?php

use App\Domain\Card\Services\CardholderGeography;
use App\Http\Requests\ManageCardRequest;
use App\Http\Requests\SubmitCardSetupRequest;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Validator;

it('validates actual country state city relationships without accepting free text or paths', function (): void {
    $geo = app(CardholderGeography::class);
    expect($geo->countryCodes())->toContain('CN', 'HK', 'MY', 'US')
        ->and($geo->states('CN'))->toContain('Anhui')
        ->and($geo->cities('CN', 'Anhui'))->toContain('Fuyang')
        ->and($geo->cities('MY', 'Kuala Lumpur'))->toContain('Kuala Lumpur')
        ->and($geo->cities('CN', 'California'))->toBe([])
        ->and($geo->regions('../LICENSE'))->toBe([]);
    $valid = ['nationality_country_code' => 'CN', 'document_country' => 'MY', 'residential_country_code' => 'CN', 'residential_state' => 'Anhui', 'residential_city' => 'Fuyang'];
    $geo->assertValid($valid);
    foreach (['nationality_country_code' => 'ZZ', 'document_country' => 'ZZ', 'residential_country_code' => 'MY', 'residential_state' => 'California', 'residential_city' => 'Arbitrary city'] as $field => $value) {
        expect(fn () => $geo->assertValid(array_replace($valid, [$field => $value])))->toThrow(DomainException::class);
    }
});

it('rejects forged dropdown values in HTTP validation before any provider or financial work', function (): void {
    $payload = ['nationality_country_code' => 'ZZ', 'document_country' => 'ZZ', 'residential_country_code' => 'CN', 'residential_state' => 'California', 'residential_city' => 'Fuyang', 'mobile_prefix' => '999'];
    $request = SubmitCardSetupRequest::create('/cards/cardholder', 'POST', $payload);
    $errors = Validator::make($payload, $request->rules())->errors();
    foreach (['nationality_country_code', 'document_country', 'residential_state', 'residential_city', 'mobile_prefix'] as $field) {
        expect($errors->has($field))->toBeTrue();
    }
});

it('derives calling prefixes and normalizes optional phone without changing account contact', function (): void {
    $geo = app(CardholderGeography::class);
    expect($geo->phone(null, null))->toBe(['mobile' => null, 'mobile_prefix' => null])
        ->and($geo->phone('202-555-0123', 'US'))->toBe(['mobile' => '2025550123', 'mobile_prefix' => '1'])
        ->and($geo->phone('13800138000', 'CN'))->toBe(['mobile' => '13800138000', 'mobile_prefix' => '86'])
        ->and(fn () => $geo->phone('123', 'CN'))->toThrow(DomainException::class)
        ->and(fn () => $geo->phone('2025550123', 'ZZ'))->toThrow(DomainException::class);
});

it('provides validated locality input for every country and every empty subdivision without bypassing existing options', function (): void {
    $geo = app(CardholderGeography::class);
    foreach ($geo->countryCodes() as $country) {
        $regions = $geo->regions($country);
        if ($regions === []) {
            expect($geo->validState($country, 'Test Region'))->toBeTrue($country);
            expect($geo->validCity($country, 'Test Region', 'Test City'))->toBeTrue($country);
        } else {
            foreach ($regions as $region) {
                if ($region['cities'] === [] && mb_strlen($region['value']) <= 50) {
                    expect($geo->validCity($country, $region['value'], 'Test City'))->toBeTrue($country.' '.$region['value']);
                }
            }
            expect($geo->validState($country, 'Not A Listed Region'))->toBeFalse($country);
        }
    }
    expect($geo->validCity('TW', 'New Taipei', 'Banqiao'))->toBeTrue()
        ->and($geo->validCity('TW', 'Keelung', 'Zhongzheng'))->toBeTrue()
        ->and($geo->validCity('CN', 'Anhui', 'Not A Listed City'))->toBeFalse()
        ->and($geo->validCity('ZZ', 'Test Region', 'Test City'))->toBeFalse()
        ->and($geo->validCity('TW', 'Made Up Region', 'Test City'))->toBeFalse();
    foreach (['', '   ', '../file', '<script>', "Name\nName", str_repeat('x', 51), '---'] as $invalid) {
        expect($geo->validState('MO', $invalid))->toBeFalse()
            ->and($geo->validCity('TW', 'Keelung', $invalid))->toBeFalse();
    }
});

it('uses the same manual fallback and strict parent checks for application and existing-holder requests', function (): void {
    foreach ([SubmitCardSetupRequest::class, ManageCardRequest::class] as $type) {
        foreach ([['MO', 'Macao', 'Taipa'], ['TW', 'New Taipei', 'Banqiao'], ['TW', 'Keelung', 'Zhongzheng']] as [$country, $state, $city]) {
            $data = ['residential_country_code' => $country, 'residential_state' => $state, 'residential_city' => $city,
                'residential_address' => '1 Test Street', 'residential_postal_code' => '100'];
            $request = $type::create('/', 'POST', $data);
            $errors = Validator::make($data, array_intersect_key($request->rules(), $data))->errors();
            expect($errors->isEmpty())->toBeTrue($type.' '.$country);
        }
        $data = ['residential_country_code' => 'CN', 'residential_state' => 'Fake Region', 'residential_city' => 'Fake City'];
        $request = $type::create('/', 'POST', $data);
        $errors = Validator::make($data, array_intersect_key($request->rules(), $data))->errors();
        expect($errors->has('residential_state'))->toBeTrue()->and($errors->has('residential_city'))->toBeTrue();
    }
});

it('requires email mobile and calling country for card applications', function (): void {
    $request = SubmitCardSetupRequest::create('/cards/cardholder', 'POST', []);
    $validator = \Illuminate\Support\Facades\Validator::make([], $request->rules());
    expect($validator->fails())->toBeTrue();
    foreach (['email', 'mobile', 'mobile_country_code'] as $field) {
        expect($validator->errors()->has($field))->toBeTrue();
    }
});
