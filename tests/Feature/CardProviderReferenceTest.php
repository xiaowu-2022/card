<?php

use App\Application\Card\CardProductProviderRouter;
use App\Application\CardProviderDirectory\SaveCardProviderReferenceAction;
use App\Application\CardProviderDirectory\DeleteCardProviderReferenceAction;
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
        $product = legacyCardProductFixture([
            'name' => 'Test', 'card_provider_reference_id' => $created->id,
            'minimum_initial_load' => '20', 'opening_fee' => '5.00000000', 'minimum_reload' => '20', 'status' => 'ACTIVE',
        ]);
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

it('deletes only unused provider configuration once with immutable safe audit and no financial or provider changes', function (): void {
    $this->post($this->url, $this->data)->assertRedirect();
    $record = CardProviderReference::sole();
    $this->get($this->url)->assertOk()->assertInertia(fn ($p) => $p->where('providers.data.0.canDelete', true));
    $record->forceFill(['photonpay_reporting_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('private-delete-key')])->save();
    $before = collect(['ledger_accounts', 'ledger_entries', 'ledger_postings', 'wallets', 'user_cards', 'card_products'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    $input = ['version' => $record->version, 'confirmed' => true, 'request_id' => (string) Str::uuid()];
    $this->from($this->url)->delete($this->url.'/'.$record->id, $input)->assertRedirect($this->url)->assertSessionHas('success', 'Card provider deleted.');
    $this->delete($this->url.'/'.$record->id, $input)->assertRedirect();
    expect(CardProviderReference::find($record->id))->toBeNull();
    $audit = DB::table('audit_logs')->where('action', 'CARD_PROVIDER_REFERENCE_DELETED')->sole();
    expect($audit->resource_id)->toBe($record->id)->and($audit->actor_id)->toBe($this->owner->id)->and($audit->request_id)->toBe($input['request_id'])
        ->and($audit->before_data)->not->toContain('private-delete-key', 'photonpay_reporting_encrypted');
    foreach ($before as $table => $json) expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($json);
    $this->postJson($this->url, $this->data)->assertStatus(409)->assertJsonPath('error.code', 'CARD_PROVIDER_REFERENCE_DELETED');
    $this->deleteJson($this->url.'/'.Str::uuid(), $input)->assertStatus(409);
    $this->deleteJson($this->url.'/'.$record->id, [...$input, 'version' => 99])->assertStatus(409);
    Http::assertNothingSent();
});

it('requires explicit confirmation a fresh version and an existing provider to delete', function (): void {
    $this->post($this->url, $this->data)->assertRedirect();
    $id = $this->data['request_id'];
    $input = ['version' => 1, 'confirmed' => true, 'request_id' => (string) Str::uuid()];
    foreach ([['confirmed' => false], ['confirmed' => null], ['version' => 0], ['request_id' => 'bad']] as $change) {
        $this->deleteJson($this->url.'/'.$id, array_replace($input, $change))->assertUnprocessable();
    }
    $this->put($this->url.'/'.$id, ['name' => 'Updated', 'reference_balance' => '0', 'version' => 1])->assertRedirect();
    $this->deleteJson($this->url.'/'.$id, $input)->assertStatus(409);
    $this->deleteJson($this->url.'/'.Str::uuid(), [...$input, 'version' => 2])->assertNotFound();
    expect(CardProviderReference::find($id))->not->toBeNull()->and(DB::table('audit_logs')->where('action', 'CARD_PROVIDER_REFERENCE_DELETED')->count())->toBe(0);
});

it('retains providers referenced by active or archived products and refuses deletion retries after a dependency appears', function (bool $archived): void {
    $this->post($this->url, $this->data)->assertRedirect();
    $id = $this->data['request_id'];
    $input = ['version' => 1, 'confirmed' => true, 'request_id' => (string) Str::uuid()];
    $this->get($this->url)->assertOk()->assertInertia(fn ($p) => $p->where('providers.data.0.canDelete', true));
    $product = legacyCardProductFixture(['name' => 'Dependency', 'card_provider_reference_id' => $id,
        'status' => $archived ? 'INACTIVE' : 'ACTIVE', 'archived_at' => $archived ? now() : null]);
    $this->get($this->url)->assertOk()->assertInertia(fn ($p) => $p->where('providers.data.0.canDelete', false));
    $this->deleteJson($this->url.'/'.$id, $input)->assertStatus(409)->assertJsonPath('error.code', 'CARD_PROVIDER_REFERENCE_IN_USE');
    expect(CardProviderReference::find($id))->not->toBeNull()->and($product->fresh())->not->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'CARD_PROVIDER_REFERENCE_DELETED')->count())->toBe(0);
    Http::assertNothingSent();
})->with([false, true]);

it('requires active scoped management access for deletion including direct action calls', function (): void {
    $this->post($this->url, $this->data)->assertRedirect();
    $input = ['version' => 1, 'confirmed' => true, 'request_id' => (string) Str::uuid()];
    $company = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($company, 'platform_admin')->deleteJson($this->url.'/'.$this->data['request_id'], $input)->assertForbidden();
    expect(fn () => app(DeleteCardProviderReferenceAction::class)->execute($this->data['request_id'], $input, $company))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->owner->update(['status' => 'SUSPENDED']);
    $this->actingAs($this->owner, 'platform_admin')->deleteJson($this->url.'/'.$this->data['request_id'], $input)->assertForbidden();
    $this->owner->update(['status' => 'ACTIVE']);
    $permission = DB::table('permissions')->where('name', 'card_provider_reference.manage')->value('id');
    DB::table('role_permissions')->where('permission_id', $permission)->delete();
    $this->actingAs($this->owner->fresh(), 'platform_admin')->deleteJson($this->url.'/'.$this->data['request_id'], $input)->assertForbidden();
    expect(CardProviderReference::count())->toBe(1);
});

it('keeps foreign keys authoritative when a dependency arrives after the deletion check', function (): void {
    $this->post($this->url, $this->data)->assertRedirect();
    $id = $this->data['request_id'];
    $event = 'eloquent.deleting: '.CardProviderReference::class;
    \Illuminate\Support\Facades\Event::listen($event, fn ($record) => legacyCardProductFixture(['name' => 'Late dependency', 'card_provider_reference_id' => $record->id]));
    try {
        $this->deleteJson($this->url.'/'.$id, ['version' => 1, 'confirmed' => true, 'request_id' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('error.code', 'CARD_PROVIDER_REFERENCE_IN_USE');
    } finally {
        \Illuminate\Support\Facades\Event::forget($event);
    }
    expect(CardProviderReference::find($id))->not->toBeNull()->and(DB::table('card_products')->where('name', 'Late dependency')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'CARD_PROVIDER_REFERENCE_DELETED')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rolls back deletion and its audit together and returns emptied pagination to the last valid page', function (): void {
    for ($i = 0; $i < 21; $i++) {
        app(SaveCardProviderReferenceAction::class)->execute(null, [...$this->data, 'request_id' => (string) Str::uuid(), 'name' => 'Unused '.$i], $this->owner);
    }
    $record = CardProviderReference::latest('created_at')->orderBy('id')->skip(20)->firstOrFail();
    $input = ['version' => 1, 'confirmed' => true, 'request_id' => (string) Str::uuid()];
    try {
        DB::transaction(function () use ($record, $input) {
            app(DeleteCardProviderReferenceAction::class)->execute($record->id, $input, $this->owner);
            throw new RuntimeException('delete rollback');
        });
    } catch (RuntimeException $error) { expect($error->getMessage())->toBe('delete rollback'); }
    expect(CardProviderReference::find($record->id))->not->toBeNull()->and(DB::table('audit_logs')->where('action', 'CARD_PROVIDER_REFERENCE_DELETED')->count())->toBe(0);
    $this->delete($this->url.'/'.$record->id, $input)->assertRedirect();
    $this->get($this->url.'?page=2')->assertRedirect('http://admin.localhost/platform/card-providers?page=1');
    $this->get($this->url.'?page=1')->assertOk()->assertInertia(fn ($p) => $p->where('providers.total', 20));
    Http::assertNothingSent();
});
