<?php

use App\Http\Requests\ChangeUserPasswordRequest;
use App\Http\Requests\CompleteRegistrationRequest;
use App\Http\Requests\CompleteUserPasswordResetRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

it('enforces the consumer password minimum and confirmation across all entry points', function (string $requestClass) {
    foreach (['123456', 'abcdef', 'ABCDEF', 'longer password'] as $password) {
        $validator = Validator::make([
            'password' => $password,
            'password_confirmation' => $password,
            'current_password' => 'old-password',
            'code' => '123456',
            'confirmed' => true,
        ], (new $requestClass)->rules());

        expect($validator->passes())->toBeTrue();
    }

    foreach ([['12345', '12345'], ['', ''], ['123456', '654321']] as [$password, $confirmation]) {
        $validator = Validator::make([
            'password' => $password,
            'password_confirmation' => $confirmation,
            'current_password' => 'old-password',
            'code' => '123456',
            'confirmed' => true,
        ], (new $requestClass)->rules());

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->has('password'))->toBeTrue();
    }
})->with([
    CompleteRegistrationRequest::class,
    CompleteUserPasswordResetRequest::class,
    ChangeUserPasswordRequest::class,
]);
