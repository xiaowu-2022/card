<?php

use App\Application\Card\CardProductProviderRouter;
use App\Application\CardProduct\CreateCardProductAction;
use App\Application\CardProviderDirectory\SaveCardProviderReferenceAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($this->owner, 'platform_admin');
    $this->url = 'http://admin.localhost/platform/card-providers';
    $this->data = ['request_id' => (string) Str::uuid(), 'name' => 'Reference only', 'reference_balance' => '123456789012.34'];
});

it('saves exact reference metadata once and updates it without changing actual money or calling providers', function (): void {
    $balances = LedgerAccount::query()->orderBy('id')->pluck('balance', 'id')->all();
    $entryCount = LedgerEntry::query()->count();
    $this->post($this->url, $this->data)->assertRedirect();
    $this->post($this->url, $this->data)->assertRedirect();
    $record = CardProviderReference::query()->sole();
    expect($record->reference_balance)->toBe('123456789012.34000000')->and($record->version)->toBe(1);
    $update = ['name' => 'Updated reference', 'reference_balance' => '0.01', 'version' => 1];
    $this->put($this->url.'/'.$record->id, $update)->assertRedirect();
    $this->put($this->url.'/'.$record->id, $update)->assertRedirect();
    $this->post($this->url, $this->data)->assertRedirect();
    expect($record->fresh()->reference_balance)->toBe('0.01000000')->and($record->fresh()->version)->toBe(2)
        ->and(LedgerAccount::query()->orderBy('id')->pluck('balance', 'id')->all())->toBe($balances)
        ->and(LedgerEntry::query()->count())->toBe($entryCount)
        ->and(DB::table('audit_logs')->where('resource_type', 'card_provider_reference')->count())->toBe(2);
    $this->get($this->url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('providers.data.0.referenceBalance', '0.01000000')->where('providers.data.0.name', 'Updated reference')
        ->missing('providers.data.0.creation_hash')->missing('providers.data.0.created_by'));
    $this->get('http://admin.localhost/platform/demo')->assertOk()->assertInertia(fn ($page) => $page->missing('cardProviderCount'));
    Http::assertNothingSent();
});

it('rejects stale edits mismatched replays and nonexistent references', function (): void {
    $this->post($this->url, $this->data)->assertRedirect();
    $id = $this->data['request_id'];
    $this->postJson($this->url, [...$this->data, 'name' => 'Changed replay'])->assertStatus(409);
    $this->put($this->url.'/'.$id, ['name' => 'First edit', 'reference_balance' => '10', 'version' => 1])->assertRedirect();
    $this->putJson($this->url.'/'.$id, ['name' => 'Stale edit', 'reference_balance' => '20', 'version' => 1])->assertStatus(409);
    $this->putJson($this->url.'/'.Str::uuid(), ['name' => 'Missing', 'reference_balance' => '20', 'version' => 1])->assertNotFound();
    expect(CardProviderReference::query()->sole()->name)->toBe('First edit');
});

it('rejects invalid amounts empty names and financial overrides', function (): void {
    foreach (['-1', '1e3', '1.001', '9999999999999', '', 'NaN', 1.23] as $amount) {
        $this->postJson($this->url, [...$this->data, 'reference_balance' => $amount])->assertUnprocessable();
    }
    $this->postJson($this->url, [...$this->data, 'name' => '   '])->assertUnprocessable();
    $this->postJson($this->url, [...$this->data, 'asset' => 'USD'])->assertUnprocessable();
    $this->postJson($this->url, [...$this->data, 'balance' => '99'])->assertUnprocessable();
    expect(CardProviderReference::query()->count())->toBe(0);
});

it('requires independent active Platform management permission', function (): void {
    $company = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($company, 'platform_admin')->postJson($this->url, $this->data)->assertForbidden();
    $permission = DB::table('permissions')->where('name', 'card_provider_reference.manage')->value('id');
    DB::table('role_permissions')->where('permission_id', $permission)->delete();
    $this->actingAs($this->owner->fresh(), 'platform_admin')->get($this->url)->assertOk();
    $this->postJson($this->url, $this->data)->assertForbidden();
    expect(CardProviderReference::query()->count())->toBe(0);
});

it('registers only the selected local test merchant and retains its runtime after renaming', function (): void {
    $data = [...$this->data, 'name' => 'test'];
    $action = app(SaveCardProviderReferenceAction::class);
    $record = $action->execute(null, $data, $this->owner);
    expect($record->runtime_driver)->toBe('UNCONFIGURED');
    $balances = LedgerAccount::query()->orderBy('id')->pluck('balance', 'id')->all();
    try {
        config(['card-provider.driver' => 'mock']);
        app()->detectEnvironment(fn () => 'local');
        app()->forgetInstance(CardProviderInterface::class);
        $this->artisan('cards:configure-local-mock-merchant', ['reference' => $record->id])->assertSuccessful();
        $this->artisan('cards:configure-local-mock-merchant', ['reference' => $record->id])->assertSuccessful();
        expect($record->fresh()->runtime_driver)->toBe('LOCAL_MOCK')->and($record->fresh()->version)->toBe(2);
        $action->execute($record->id, ['name' => 'Renamed test', 'reference_balance' => '0.00', 'version' => 2], $this->owner);
        expect($record->fresh()->runtime_driver)->toBe('LOCAL_MOCK');
        $created = $action->execute(null, [...$data, 'request_id' => (string) Str::uuid(), 'name' => 'TEST'], $this->owner);
        expect($created->runtime_driver)->toBe('LOCAL_MOCK');
        $product = app(CreateCardProductAction::class)->execute([
            'name' => 'Test', 'card_provider_reference_id' => $created->id,
            'minimum_initial_load' => '20', 'opening_fee' => '5.00000000', 'minimum_reload' => '20', 'status' => 'ACTIVE',
        ], $this->owner);
        expect(app(CardProductProviderRouter::class)->forProduct($product)->available())->toBeTrue();
        foreach (['production', 'staging'] as $environment) {
            app()->detectEnvironment(fn () => $environment);
            expect(app(CardProductProviderRouter::class)->forProduct($product)->available())->toBeFalse();
            $this->artisan('cards:configure-local-mock-merchant', ['reference' => $record->id])->assertFailed();
            $normal = $action->execute(null, [...$data, 'request_id' => (string) Str::uuid()], $this->owner);
            expect($normal->runtime_driver)->toBe('UNCONFIGURED');
        }
        expect(LedgerAccount::query()->orderBy('id')->pluck('balance', 'id')->all())->toBe($balances);
        expect(DB::table('audit_logs')->where('action', 'LOCAL_MOCK_CARD_MERCHANT_CONFIGURED')->count())->toBe(1);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
        app()->forgetInstance(CardProviderInterface::class);
    }
    Http::assertNothingSent();
});

it('rejects client runtime selection', function (): void {
    $this->postJson($this->url, [...$this->data, 'runtime_driver' => 'LOCAL_MOCK'])->assertUnprocessable();
});

it('rolls back reference and audit together when the outer transaction fails', function (): void {
    try {
        DB::transaction(function (): void {
            app(SaveCardProviderReferenceAction::class)->execute(null, $this->data, $this->owner);
            throw new RuntimeException('test rollback');
        });
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('test rollback');
    }
    expect(CardProviderReference::query()->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('resource_type', 'card_provider_reference')->count())->toBe(0);
});
