<?php

namespace App\Http\Controllers\User;

use App\Application\Card\CardProductProviderRouter;
use App\Application\Card\CreateCardRecipientAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Models\CardRecipientApplication;
use App\Domain\Card\Services\CardRecipientMaterials;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProvider\Contracts\PhysicalCardProviderInterface;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCardRecipientRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CardRecipientController extends Controller
{
    public function inspect(string $recipient, Request $request, TenantContext $context): JsonResponse
    {
        $row = CardRecipientApplication::where('tenant_id', $context->id())->where('user_id', $request->user('tenant_user')->id)->whereKey($recipient)->firstOrFail();
        if ($row->status === 'UNKNOWN' && $row->provider_recipient_id) {
            $product = CardProduct::findOrFail($row->card_product_id);
            abort_unless($product->card_provider_reference_id === $row->card_provider_reference_id, 409);
            $provider = app(CardProductProviderRouter::class)->forProduct($product, 'physical_card');
            try {
                if ($provider instanceof PhysicalCardProviderInterface && $provider->recipientAvailable($row->provider_recipient_id)) {
                    $row->forceFill(['status' => 'READY'])->save();
                    app(AuditLogger::class)->record($context->id(), 'USER', $request->user('tenant_user')->id, 'CARD_RECIPIENT_CONFIRMED', 'card_recipient_application', $row->id);
                }
            } catch (\Throwable) { /* Never resubmit an unknown addRecipient. */
            }
        }

        return response()->json(['id' => $row->id, 'status' => $row->status,
            'fields' => json_decode(app(CardRecipientMaterials::class)->decrypt($row->materials_encrypted), true, 512, JSON_THROW_ON_ERROR)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(CreateCardRecipientRequest $request, TenantContext $context, CreateCardRecipientAction $action): JsonResponse
    {
        $recipient = $action->execute($context->id(), $request->user('tenant_user')->id, $request->validated());

        return response()->json(['id' => $recipient->id, 'status' => $recipient->status])->header('Cache-Control', 'private, no-store');
    }
}
