<?php

namespace App\Application\Promotion;

use App\Domain\Audit\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RecordAccountActivation
{
    public function __construct(private PaidPromotionRules $rules, private AuditLogger $audit) {}

    /** Source transaction holds Tenant then User locks; no money is moved here. */
    public function execute(string $tenant, string $user, string $kind, string $source, string $entry, CarbonImmutable $at): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Activation requires a source transaction.');
        }
        if (DB::table('account_activations')->where('tenant_id', $tenant)->where('user_id', $user)->exists()) {
            return false;
        }
        $id = (string) Str::uuid();
        DB::table('account_activations')->insert(['id' => $id, 'tenant_id' => $tenant, 'user_id' => $user,
            'source_type' => $kind, 'source_id' => $source, 'ledger_entry_id' => $entry, 'activated_at' => $at, 'created_at' => now()]);
        foreach ($this->rules->ancestors($tenant, $user) as $ancestor) {
            DB::table('account_activation_relations')->insert(['activation_id' => $id, 'tenant_id' => $tenant,
                'ancestor_user_id' => $ancestor->user_id, 'depth' => $ancestor->depth]);
        }
        $this->audit->record($tenant, 'SYSTEM', null, 'ACCOUNT_ACTIVATED', 'account_activation', $id, null, ['user_id' => $user, 'source_type' => $kind, 'source_id' => $source]);

        return true;
    }
}
