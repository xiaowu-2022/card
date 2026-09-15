<?php

use App\Application\Kyc\SubmitKycApplicationAction;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrStatus;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->userA = User::query()->where('tenant_id', $this->tenantA->id)->firstOrFail();
    $this->userB = User::query()->where('tenant_id', $this->tenantB->id)->firstOrFail();
});

it('enforces tenant user consistency and valid KYC values in PostgreSQL', function (): void {
    $protected = app(IdentityNumberProtector::class)->protect($this->tenantA->id, 'NATIONAL_ID', 'MY', 'DB-1234');
    expect(fn () => DB::table('kyc_applications')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'user_id' => $this->userB->id,
        'document_type' => 'PASSPORT', 'document_country' => 'malaysia',
        'identity_number_encrypted' => $protected['encrypted'], 'identity_hash' => $protected['hash'],
        'front_object_key' => 'private/front', 'back_object_key' => 'private/back',
        'ocr_status' => 'UNKNOWN', 'review_status' => 'PENDING', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('prevents a cross-user or cross-tenant resubmission link at database level', function (): void {
    $applicationA = app(SubmitKycApplicationAction::class)->execute($this->tenantA, $this->userA, 'MY', 'DB-A', kycTestImage('front.jpg'), kycTestImage('back.jpg'));
    $protected = app(IdentityNumberProtector::class)->protect($this->tenantB->id, 'NATIONAL_ID', 'MY', 'DB-B');
    expect(fn () => DB::table('kyc_applications')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantB->id, 'user_id' => $this->userB->id,
        'resubmission_of_id' => $applicationA->id, 'document_type' => KycDocumentType::NationalId->value, 'document_country' => 'MY',
        'identity_number_encrypted' => $protected['encrypted'], 'identity_hash' => $protected['hash'],
        'front_object_key' => 'private/front', 'back_object_key' => 'private/back', 'ocr_status' => KycOcrStatus::NotStarted->value,
        'review_status' => KycReviewStatus::Pending->value, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('requires a positive identity account limit and keeps identity hash non-unique', function (): void {
    $indexes = collect(DB::select("SELECT indexdef FROM pg_indexes WHERE tablename IN ('kyc_applications','identity_records')"))->pluck('indexdef')->implode(' ');
    expect($indexes)->not->toContain('UNIQUE (identity_hash)')
        ->and($indexes)->toContain('kyc_one_pending_application_per_user');
    expect(fn () => $this->tenantA->kycSettings()->update(['max_accounts_per_identity' => 0]))->toThrow(QueryException::class);
    expect(fn () => $this->tenantA->kycSettings()->update(['max_accounts_per_identity' => 101]))->toThrow(QueryException::class);
});

it('retains completed foundations and the explicitly authorized promotion and refund stage', function (): void {
    expect(Schema::hasTable('kyc_applications'))->toBeTrue()
        ->and(Schema::hasTable('identity_records'))->toBeTrue()
        ->and(Schema::hasTable('wallets'))->toBeTrue()
        ->and(Schema::hasTable('ledger_accounts'))->toBeTrue()
        ->and(Schema::hasTable('ledger_entries'))->toBeTrue()
        ->and(Schema::hasTable('ledger_postings'))->toBeTrue()
        ->and(Schema::hasTable('wallet_topup_orders'))->toBeTrue()
        ->and(Schema::hasTable('payment_provider_transactions'))->toBeTrue()
        ->and(Schema::hasTable('payment_provider_events'))->toBeTrue()
        ->and(Schema::hasTable('withdrawal_destinations'))->toBeTrue()
        ->and(Schema::hasTable('withdrawal_orders'))->toBeTrue()
        ->and(Schema::hasTable('withdrawal_transaction_attempts'))->toBeTrue()
        ->and(Schema::hasTable('card_products'))->toBeTrue()
        ->and(Schema::hasTable('tenant_card_product_configs'))->toBeTrue()
        ->and(Schema::hasTable('provider_cardholders'))->toBeTrue()
        ->and(Schema::hasTable('card_issue_orders'))->toBeTrue()
        ->and(Schema::hasTable('user_cards'))->toBeTrue()
        ->and(Schema::hasTable('security_deposit_refund_requests'))->toBeTrue()
        ->and(Schema::hasTable('commission_awards'))->toBeTrue();
    foreach (['card_provider_connections', 'card_load_orders'] as $futureTable) {
        expect(Schema::hasTable($futureTable))->toBeFalse();
    }
});
