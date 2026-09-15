<?php

namespace App\Application\CardProviderDirectory;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class SaveCardProviderReferenceAction
{
    public function __construct(private AuthorizationService $authorization, private AuditLogger $audit) {}

    public function execute(?string $id, array $data, AdminUser $actor, ?string $auditRequestId = null): CardProviderReference
    {
        $data['name'] = is_string($data['name'] ?? null) ? trim($data['name']) : null;
        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'reference_balance' => [$id === null ? 'required' : 'sometimes', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'request_id' => [$id === null ? 'required' : 'prohibited', 'uuid'],
            'version' => [$id === null ? 'prohibited' : 'required', 'integer', 'min:1'],
            'balance' => ['prohibited'], 'asset' => ['prohibited'], 'tenant_id' => ['prohibited'],
            'runtime_driver' => ['prohibited'],
        ])->validate();
        $amount = isset($data['reference_balance']) ? (string) BigDecimal::of($data['reference_balance'])->toScale(8) : null;

        return DB::transaction(function () use ($id, $data, $amount, $actor, $auditRequestId): CardProviderReference {
            $actor = $actor->fresh();
            abort_unless($actor && $actor->status === AdminUserStatus::Active && $this->authorization->allows($actor, ScopeType::Platform, null, 'card_provider_reference.manage'), 403);
            $recordId = $id ?? $data['request_id'];
            if ($id === null) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['card-provider-reference:'.$recordId]);
            }
            $query = CardProviderReference::query()->whereKey($recordId)->lockForUpdate();
            $record = $id === null ? $query->first() : $query->firstOrFail();
            if ($record?->photonpay_reporting_encrypted !== null) {
                if (array_key_exists('reference_balance', $data)) {
                    throw ValidationException::withMessages(['reference_balance' => 'API balances are read-only.']);
                }
                $amount = $record->reference_balance;
            } elseif ($amount === null) {
                throw ValidationException::withMessages(['reference_balance' => 'A reference balance is required.']);
            }
            $hash = hash('sha256', json_encode([$data['name'], $amount, 'USDT'], JSON_THROW_ON_ERROR));
            if ($id === null && $record) {
                if ($record->created_by !== $actor->id || $record->creation_hash !== $hash) {
                    throw new DomainException('CARD_PROVIDER_REFERENCE_REQUEST_CONFLICT', 'This request was already used. Reopen the form and try again.', 409);
                }

                return $record;
            }
            if ($record && $record->name === $data['name'] && $record->reference_balance === $amount) {
                return $record;
            }
            if ($record && $record->version !== (int) $data['version']) {
                throw new DomainException('CARD_PROVIDER_REFERENCE_VERSION_CONFLICT', 'This reference was updated elsewhere. Refresh before editing.', 409);
            }
            $before = $record ? ['name' => $record->name, 'reference_balance' => $record->reference_balance, 'version' => $record->version] : null;
            $record ??= new CardProviderReference;
            if (! $record->exists) {
                $record->forceFill(['id' => $recordId, 'creation_hash' => $hash, 'created_by' => $actor->id, 'asset_code' => 'USDT', 'version' => 0]);
                $record->forceFill(['runtime_driver' => strtolower($data['name']) === 'test' && app()->environment('local') && LocalCardSimulation::enabled() ? 'LOCAL_MOCK' : 'UNCONFIGURED']);
            }
            $record->forceFill(['name' => $data['name'], 'reference_balance' => $amount, 'updated_by' => $actor->id, 'version' => $record->version + 1])->save();
            $this->audit->record(null, 'ADMIN', $actor->id, $id === null ? 'CARD_PROVIDER_REFERENCE_CREATED' : 'CARD_PROVIDER_REFERENCE_UPDATED', 'card_provider_reference', $record->id, $before, [
                'name' => $record->name, 'reference_balance' => $record->reference_balance, 'asset' => 'USDT',
                'source' => 'MANUAL_REFERENCE_ONLY', 'version' => $record->version,
                'runtime_driver' => $record->runtime_driver,
            ], $auditRequestId);

            return $record;
        });
    }
}
