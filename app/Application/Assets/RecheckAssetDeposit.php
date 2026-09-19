<?php

namespace App\Application\Assets;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetDepositOrder;
use App\Domain\Assets\ChainConnection;
use App\Domain\Assets\ChainObservation;
use App\Domain\Audit\Services\AuditLogger;
use App\Infrastructure\Assets\ChainReader;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class RecheckAssetDeposit
{
    public function __construct(private AssetAccess $access, private ChainReader $reader, private DepositAssetsAction $deposits, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $id, AdminUser $actor, string $hash, string $request): void
    {
        $this->access->platform($actor, 'wallet_topups.verify');
        AssetAccess::requestId($request);
        $o = AssetDepositOrder::where('tenant_id', $tenantId)->whereKey($id)->firstOrFail();
        if (! preg_match($o->network === 'ETHEREUM' ? '/^0x[0-9a-f]{64}$/i' : '/^[0-9a-f]{64}$/i', $hash)) {
            throw new DomainException('TRANSACTION_INVALID', 'Enter a valid transaction hash.');
        }
        $this->audit->record($tenantId, 'ADMIN', $actor->id, 'ASSET_DEPOSIT_VERIFICATION_REQUESTED', 'asset_deposit_order', $id, null, null, $request);
        try {
            $proofs = $this->reader->transaction(ChainConnection::findOrFail($o->network), strtolower($hash), false);
        } catch (\Throwable) {
            $proofs = [];
        }
        $proofs = array_values(array_filter($proofs, fn ($p) => $p['contract'] === $o->contract && $p['address'] === $o->address && BigDecimal::of($p['amount'])->isEqualTo($o->amount) && $p['occurred_at']->greaterThanOrEqualTo($o->created_at) && $p['occurred_at']->lessThanOrEqualTo($o->expires_at)));
        if (count($proofs) !== 1) {
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'ASSET_DEPOSIT_UNCONFIRMED', 'asset_deposit_order', $id, null, null, $request);

            return;
        }
        $p = $proofs[0];
        DB::transaction(function () use ($o, $p): void {
            AssetAccess::lock('asset-observation:'.$o->network.':'.$p['event_id']);
            $observation = ChainObservation::where('network', $o->network)->where('event_id', $p['event_id'])->first();
            // Previously ambiguous evidence must never be reassigned by a manual recheck.
            if ($observation) {
                if ($observation->status === 'MATCHED' && $observation->order_id === $o->id) {
                    $this->deposits->verified($observation);
                }

                return;
            }
            $observation = ChainObservation::create(['network' => $o->network, 'event_id' => $p['event_id'], 'rail_code' => $o->rail_code, 'address' => $o->address, 'amount' => $p['amount'], 'block_height' => $p['block_height'], 'block_hash' => $p['block_hash'], 'occurred_at' => $p['occurred_at'], 'status' => 'MATCHED', 'order_id' => $o->id]);
            $this->deposits->verified($observation);
        }, 3);
    }
}
