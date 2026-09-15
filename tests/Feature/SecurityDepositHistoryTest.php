<?php

use App\Application\SecurityDeposit\SecurityDepositHistoryQuery;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\SecurityDeposit\Models\SecurityDepositRefundRequest;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
});

it('shows an empty read-only history and validates pagination', function (): void {
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/security-deposit/history')
        ->assertOk()->assertInertia(fn ($page) => $page->component('user/SecurityDepositHistory')->where('history.data', []));
    $this->get('http://a.localhost/security-deposit/history?page=0')->assertSessionHasErrors('page');
    expect(LedgerEntry::query()->count())->toBe(0);
});

it('paginates only the owners refund records without exposing internal evidence', function (): void {
    $other = $this->user->replicate(['account_id']);
    $other->email = 'history-other@a.localhost';
    $other->save();
    $foreign = User::query()->where('tenant_id', '!=', $this->tenant->id)->firstOrFail();
    // Synthetic cancelled-request fixtures only; no completed refund or financial posting.
    foreach ([$this->user, $other, $foreign] as $owner) {
        $wallet = Wallet::query()->create(['tenant_id' => $owner->tenant_id, 'user_id' => $owner->id, 'asset_code' => 'USDT', 'status' => 'ACTIVE']);
        for ($i = 0; $i < ($owner->id === $this->user->id ? 21 : 1); $i++) {
            SecurityDepositRefundRequest::query()->create([
                'tenant_id' => $owner->tenant_id, 'user_id' => $owner->id, 'wallet_id' => $wallet->id,
                'request_id' => (string) Str::uuid(), 'amount' => $owner->id === $this->user->id ? '100.12345678' : '999',
                'asset_code' => 'USDT', 'status' => 'CANCELLED', 'created_at' => now()->subMinutes($i),
            ]);
        }
    }
    $history = app(SecurityDepositHistoryQuery::class)->execute($this->tenant->id, $this->user->id);
    expect($history['data'])->toHaveCount(20)
        ->and($history['lastPage'])->toBe(2)
        ->and($history['data'][0]['amount'])->toBe('100.12345678')
        ->and(array_keys($history['data'][0]))->toBe(['id', 'amount', 'asset', 'state', 'requestedAt'])
        ->and(fn () => app(SecurityDepositHistoryQuery::class)->execute($foreign->tenant_id, $this->user->id))->toThrow(ModelNotFoundException::class);
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/security-deposit/history?page=2&user_id='.$foreign->id.'&tenant_id='.$foreign->tenant_id)
        ->assertOk()->assertInertia(fn ($page) => $page->has('history.data', 1)->where('history.data.0.state', 'cancelled')->where('history.data.0.amount', '100.12345678'));
    expect(LedgerEntry::query()->count())->toBe(0);
});
