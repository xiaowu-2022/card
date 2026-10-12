<?php

namespace App\Application\CardProviderDirectory;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class DeleteCardProviderReferenceAction
{
    public function __construct(private AuthorizationService $authorization, private AuditLogger $audit) {}

    public function execute(string $id, array $input, AdminUser $actor): void
    {
        $data = Validator::make($input, ['version' => 'required|integer|min:1', 'confirmed' => 'required|accepted', 'request_id' => 'required|uuid'])->validate();
        try {
            DB::transaction(function () use ($id, $data, $actor): void {
                $actor = $actor->fresh();
                abort_unless($actor && $this->authorization->allows($actor, ScopeType::Platform, null, 'card_provider_reference.manage'), 403);
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['card-provider-delete-request:'.$data['request_id']]);
                // Share the creation lock so a historical create retry cannot revive the ID.
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['card-provider-reference:'.$id]);
                $previous = DB::table('audit_logs')->where('action', 'CARD_PROVIDER_REFERENCE_DELETED')->where('request_id', $data['request_id'])->first();
                if ($previous) {
                    $before = json_decode($previous->before_data, true, flags: JSON_THROW_ON_ERROR);
                    if ($previous->actor_id !== $actor->id || $previous->resource_id !== $id || (int) $before['version'] !== (int) $data['version']) {
                        throw new DomainException('CARD_PROVIDER_REFERENCE_REQUEST_CONFLICT', 'This request was already used. Reopen the form and try again.', 409);
                    }

                    return;
                }
                $record = CardProviderReference::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($record->version !== (int) $data['version']) {
                    throw new DomainException('CARD_PROVIDER_REFERENCE_VERSION_CONFLICT', 'This card provider was updated elsewhere. Refresh before deleting.', 409);
                }
                // Freeze related product bindings while checking the current local cards.
                DB::table('card_products')->where('card_provider_reference_id', $id)->orderBy('id')->lockForUpdate()->get(['id']);
                if (CardProviderReference::currentCards($id)->exists()) {
                    throw $this->used();
                }
                $before = ['name' => $record->name, 'reference_balance' => $record->reference_balance, 'version' => $record->version];
                // Audit-backed removal preserves every historical FK and recovery identity.
                $this->audit->record(null, 'ADMIN', $actor->id, 'CARD_PROVIDER_REFERENCE_DELETED', 'card_provider_reference', $id, $before, null, $data['request_id']);
            });
        } catch (QueryException $error) {
            // Keep database FK guards authoritative for a racing/new dependency.
            if (in_array((string) $error->getCode(), ['23503', '23001'], true)) {
                throw $this->used();
            }
            throw $error;
        }
    }

    private function used(): DomainException
    {
        return new DomainException('CARD_PROVIDER_REFERENCE_IN_USE', 'This card provider is in use and cannot be deleted.', 409);
    }
}
