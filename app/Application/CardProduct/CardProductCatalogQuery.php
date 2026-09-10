<?php

namespace App\Application\CardProduct;

use App\Application\Wallet\WalletEligibilityService;
use App\Domain\CardProduct\Enums\CardProductStatus;
use App\Domain\CardProduct\Enums\TenantCardProductStatus;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;

final readonly class CardProductCatalogQuery
{
    public function __construct(private WalletEligibilityService $eligibility) {}

    /** @return array<string,mixed> */
    public function platform(): array
    {
        return ['products' => CardProduct::query()->withCount('tenantConfigs')->orderBy('name')->get()
            ->map(fn (CardProduct $product): array => $this->product($product))->all()];
    }

    /** @return array<string,mixed> */
    public function tenant(string $tenantId): array
    {
        return ['products' => CardProduct::query()->with(['tenantConfigs' => fn ($query) => $query->where('tenant_id', $tenantId)])
            ->orderBy('name')->get()->map(function (CardProduct $product): array {
                $config = $product->tenantConfigs->first();

                return $this->product($product) + ['config' => $config ? $this->config($config) : null];
            })->all()];
    }

    /** @return array<string,mixed> */
    public function user(string $tenantId, ?string $userId): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->with('businessSettings')->firstOrFail();
        $state = null;
        if ($userId !== null) {
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
            $state = $this->eligibility->forUser($tenant, $user);
        }
        $products = TenantCardProductConfig::query()->where('tenant_id', $tenantId)
            ->where('status', TenantCardProductStatus::Active->value)
            ->whereHas('product', fn ($query) => $query->where('status', CardProductStatus::Active->value))
            ->with('product')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (TenantCardProductConfig $config): array => $this->userProduct($config, $state))->all();

        return ['products' => $products];
    }

    /** @return array<string,mixed> */
    private function product(CardProduct $product): array
    {
        return [
            'id' => $product->id,
            'provider' => $product->provider,
            'providerProductRef' => $product->provider_product_ref,
            'name' => $product->name,
            'cardCurrency' => $product->card_currency,
            'cardType' => $product->card_type,
            'minimumInitialLoad' => $product->minimum_initial_load,
            'minimumReload' => $product->minimum_reload,
            'status' => $product->status->value,
            'tenantConfigCount' => $product->tenant_configs_count ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function config(TenantCardProductConfig $config): array
    {
        return [
            'displayName' => $config->display_name,
            'openingFee' => $config->opening_fee,
            'maxCardsPerUser' => $config->max_cards_per_user,
            'status' => $config->status->value,
            'sortOrder' => $config->sort_order,
        ];
    }

    /** @param array<string,mixed>|null $state @return array<string,mixed> */
    private function userProduct(TenantCardProductConfig $config, ?array $state): array
    {
        $product = $config->product;
        $opening = Money::of($config->opening_fee, 'USDT');
        $initial = Money::of($product->minimum_initial_load, 'USDT');
        $required = $opening->add($initial);
        $ready = false;
        $guidance = 'Sign in to check your readiness.';
        if ($state !== null) {
            $available = $state['available'];
            $baseReady = $state['tenantStatus'] === 'ACTIVE' && $state['userStatus'] === 'ACTIVE'
                && $state['kycStatus'] === 'APPROVED' && $state['walletStatus'] === 'ACTIVE'
                && $state['depositSatisfied'] && ($state['wallet']['asset'] ?? null) === 'USDT';
            $enough = $available !== null && $available['asset'] === 'USDT'
                && Money::of($available['amount'], 'USDT')->compare($required) >= 0;
            $ready = $baseReady && $enough;
            $guidance = match (true) {
                ! $baseReady => 'Complete verification, wallet setup, and security deposit first.',
                ! $enough => 'Add funds before opening a card.',
                default => 'Ready for card setup.',
            };
        }

        return [
            'id' => $product->id,
            'name' => $config->display_name ?: $product->name,
            'cardType' => 'Virtual card',
            'cardCurrency' => $product->card_currency,
            'openingFee' => $opening->amount(),
            'minimumInitialLoad' => $initial->amount(),
            'minimumReload' => $product->minimum_reload,
            'minimumRequiredBalance' => $required->amount(),
            'maxCardsPerUser' => $config->max_cards_per_user,
            'readyForSetup' => $ready,
            'guidance' => $guidance,
        ];
    }
}
