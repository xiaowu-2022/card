<?php

use App\Support\Errors\DomainException;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->failureEntries = [];
    $manager = Mockery::mock(LogManager::class, [app()])->makePartial();
    $manager->shouldReceive('log')->andReturnUsing(function ($level, $message, $context) {
        if ($message === 'HTTP request failed') {
            $this->failureEntries[] = $context;
        }
    });
    Log::swap($manager);
    Route::post('/__failure-test/business/{tenant}', fn () => throw new DomainException('DOMAIN_UNAVAILABLE', 'A selected domain is unavailable.'))
        ->name('test.domain.business');
    Route::post('/__failure-test/validation', function (Request $request) {
        $request->validate(['hostname' => ['required']]);
    })->middleware('web')->name('test.domain.validation');
    Route::post('/__failure-test/forbidden', fn () => response()->json(['message' => 'Forbidden'], 403));
    Route::post('/__failure-test/server', fn () => throw new RuntimeException('Offline server failure'));
    Route::get('/__failure-test/success', fn () => response()->json(['ok' => true]));
});

it('logs handled business errors once with scoped IDs but no submitted secrets', function (): void {
    $tenant = '01a09996-8c3d-727d-8f0a-6b96544dc07a';
    $domain = '01a09996-8c36-7288-bf98-889103088ba6';
    $this->postJson('/__failure-test/business/'.$tenant, ['domain_ids' => [$domain], 'api_key' => 'DO-NOT-LOG-SECRET', 'otp' => '987654'])
        ->assertUnprocessable();
    expect($this->failureEntries)->toHaveCount(1)
        ->and($this->failureEntries[0]['error_code'])->toBe('DOMAIN_UNAVAILABLE')
        ->and($this->failureEntries[0]['tenant_id'])->toBe($tenant)
        ->and($this->failureEntries[0]['domain_ids'])->toBe([$domain])
        ->and($this->failureEntries[0]['request_id'])->not->toBeEmpty()
        ->and(json_encode($this->failureEntries))->not->toContain('DO-NOT-LOG-SECRET', '987654');
});

it('logs validation redirects and JSON failures without validation messages or values', function (bool $json): void {
    $response = $json ? $this->postJson('/__failure-test/validation', ['api_key' => 'PRIVATE-VALUE'])
        : $this->from('/')->post('/__failure-test/validation', ['api_key' => 'PRIVATE-VALUE']);
    $json ? $response->assertUnprocessable() : $response->assertRedirect();
    expect($this->failureEntries)->toHaveCount(1)
        ->and($this->failureEntries[0]['error_code'])->toBe('VALIDATION_FAILED')
        ->and($this->failureEntries[0]['validation_fields'])->toBe(['hostname'])
        ->and($this->failureEntries[0]['validation_rules'])->toBe(['hostname' => ['Required']])
        ->and(json_encode($this->failureEntries))->not->toContain('PRIVATE-VALUE');
})->with([true, false]);

it('logs direct error responses and missing routes but leaves successes quiet', function (): void {
    $this->postJson('/__failure-test/forbidden')->assertForbidden();
    $this->getJson('/__failure-test/missing')->assertNotFound();
    $this->getJson('/__failure-test/success')->assertOk();
    expect($this->failureEntries)->toHaveCount(2)
        ->and(array_column($this->failureEntries, 'http_status'))->toBe([403, 404]);
});


it('retains safe diagnostic context for unexpected server errors', function (): void {
    $this->postJson('/__failure-test/server')->assertStatus(500);
    expect($this->failureEntries)->toHaveCount(1)
        ->and($this->failureEntries[0]['http_status'])->toBe(500)
        ->and($this->failureEntries[0]['exception_class'])->toBe(RuntimeException::class);
});
