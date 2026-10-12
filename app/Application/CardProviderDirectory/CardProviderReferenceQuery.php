<?php

namespace App\Application\CardProviderDirectory;

use App\Domain\Card\Models\UserCard;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Providers\Card\PhotonPayMerchantReport;

final class CardProviderReferenceQuery
{
    public function __construct(private PhotonPayMerchantReport $report) {}

    public function list(): array
    {
        $usage = "EXISTS (SELECT 1 FROM user_cards c JOIN card_products p ON p.id=c.card_product_id WHERE p.card_provider_reference_id=platform_card_provider_references.id AND c.archived_at IS NULL AND (c.provider_status IS NULL OR c.provider_status<>'cancelled'))";

        return ['providers' => CardProviderReference::query()->select('platform_card_provider_references.*')->selectRaw('('.$usage.') AS deletion_blocked')->withCasts(['deletion_blocked' => 'boolean'])->latest('created_at')->orderBy('id')->paginate(20)
            ->through(function (CardProviderReference $record): array {
                $connected = $record->photonpay_reporting_encrypted !== null;
                $report = $connected ? $this->report->read($record->photonpay_reporting_encrypted) : null;
                $localCount = $connected ? null : UserCard::query()
                    ->whereIn('card_product_id', CardProduct::query()
                        ->where('card_provider_reference_id', $record->id)->select('id'))->count();

                return [
                    'photonpay' => app(PhotonPayAccounts::class)->publicConfiguration($record),
                    'id' => $record->id, 'name' => $record->name,
                    'referenceBalance' => $connected ? $report['balance'] : $record->reference_balance,
                    'asset' => $connected ? $report['asset'] : $record->asset_code, 'version' => $record->version,
                    'localMock' => $record->runtime_driver === 'LOCAL_MOCK',
                    'apiConnected' => $connected, 'sandbox' => $report['sandbox'] ?? false,
                    'canDelete' => ! $record->deletion_blocked,
                    'issuedCardCount' => $connected ? $report['issuedCardCount'] : (string) $localCount,
                    'updatedAt' => $connected ? $report['queriedAt'] : $record->updated_at->toIso8601String(),
                ];
            })];
    }

    public function count(): int
    {
        return CardProviderReference::query()->count();
    }
}
