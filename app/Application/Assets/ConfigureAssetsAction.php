<?php

namespace App\Application\Assets;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetCatalog;
use App\Domain\Assets\AssetDepositOrder;
use App\Domain\Assets\AssetRail;
use App\Domain\Assets\AssetWithdrawalOrder;
use App\Domain\Assets\ChainConnection;
use App\Domain\Assets\CompanyRail;
use App\Domain\Assets\ExchangePolicy;
use App\Domain\Assets\MarketSettings;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Assets\ChainRpc;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

final readonly class ConfigureAssetsAction
{
    public function __construct(private AssetAccess $access, private AuditLogger $audit) {}

    public function execute(AdminUser $actor, array $input, ?Tenant $tenant = null): void
    {
        $this->access->platform($actor, 'tenant.manage');
        if (! Hash::check($input['password'] ?? '', $actor->fresh()->password)) {
            throw new DomainException('PASSWORD_INVALID', 'The password is incorrect.', 403);
        }
        // Validate a Bitcoin receiving address before entering the configuration transaction.
        if (! $tenant && ($input['kind'] ?? '') === 'rail' && ($input['code'] ?? '') === 'BTC_BITCOIN' && filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $connection = ChainConnection::findOrFail('BITCOIN');
            $proof = app(ChainRpc::class)->call($connection, 'validateaddress', [trim((string) ($input['address'] ?? ''))]);
            if (($proof['isvalid'] ?? false) !== true) {
                throw new DomainException('ADDRESS_INVALID', 'Enter a valid destination address.');
            }
        }
        DB::transaction(function () use ($actor, $input, $tenant): void {
            AssetAccess::lock('asset-configuration');
            $kind = $input['kind'] ?? '';
            if ($kind === 'market' && ! $tenant) {
                $data = Validator::make($input, ['enabled' => 'required|boolean', 'api_key' => 'nullable|string|max:512'])->validate();
                $settings = MarketSettings::query()->lockForUpdate()->findOrFail(1);
                if (! empty($data['api_key'])) {
                    $settings->api_key = $data['api_key'];
                }
                if ($data['enabled'] && ! $settings->api_key) {
                    throw new DomainException('CONFIG_INCOMPLETE', 'Complete the required configuration first.');
                }
                $settings->enabled = $data['enabled'];
                $settings->save();
            } elseif ($kind === 'network' && ! $tenant) {
                $data = Validator::make($input, ['network' => 'required|in:ETHEREUM,BITCOIN', 'enabled' => 'required|boolean', 'rpc_url' => 'required|url:https|max:1024', 'username' => 'nullable|string|max:256', 'credential' => 'nullable|string|max:512', 'start_height' => 'required|integer|min:0', 'confirmations' => 'required|integer|min:6|max:10000'])->validate();
                $c = ChainConnection::query()->whereKey($data['network'])->lockForUpdate()->firstOrFail();
                $url = $data['rpc_url'];
                if (! in_array(parse_url($url, PHP_URL_HOST), config('assets.rpc_allowed_hosts', []), true) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_QUERY) !== null) {
                    throw new DomainException('RPC_HOST_NOT_ALLOWED', 'The node hostname must be explicitly allowed in deployment configuration.');
                }
                if ($c->start_height !== null && $c->start_height !== (int) $data['start_height']) {
                    throw new DomainException('SCAN_BOUNDARY_IMMUTABLE', 'The initial scan boundary cannot be changed.');
                }
                $c->fill(['rpc_url' => $url, 'enabled' => $data['enabled'], 'confirmations' => $data['confirmations'], 'start_height' => $data['start_height'], 'next_height' => $c->next_height ?? $data['start_height']]);
                if (! empty($data['credential'])) {
                    $c->credential = empty($data['username']) ? ['api_key' => $data['credential']] : ['username' => $data['username'], 'password' => $data['credential']];
                }
                $c->save();
            } elseif ($kind === 'rail' && ! $tenant) {
                $data = Validator::make($input, ['code' => 'required|exists:asset_rails,code', 'enabled' => 'required|boolean', 'address' => 'required|string|max:128'])->validate();
                $rail = AssetRail::query()->whereKey($data['code'])->lockForUpdate()->firstOrFail();
                $address = trim($data['address']);
                if ($rail->network === 'ETHEREUM') {
                    if (! preg_match('/^0x[0-9a-f]{40}$/i', $address) || preg_match('/^0x0{40}$/i', $address)) {
                        throw new DomainException('ADDRESS_INVALID', 'Enter a valid destination address.');
                    }
                    $address = strtolower($address);
                } elseif (! preg_match('/^(bc1[ac-hj-np-z02-9]{11,87}|[13][1-9A-HJ-NP-Za-km-z]{25,34})$/', $address)) {
                    throw new DomainException('ADDRESS_INVALID', 'Enter a valid destination address.');
                }
                if ($rail->deposit_address && $rail->deposit_address !== $address && (AssetDepositOrder::where('rail_code', $rail->code)->exists() || AssetWithdrawalOrder::where('rail_code', $rail->code)->exists())) {
                    throw new DomainException('RAIL_ADDRESS_IMMUTABLE', 'A network address with financial history cannot be changed.');
                }
                if ($data['enabled'] && ! ChainConnection::whereKey($rail->network)->where('enabled', true)->whereNotNull('next_height')->exists()) {
                    throw new DomainException('CONFIG_INCOMPLETE', 'Complete the required configuration first.');
                }
                $rail->update(['enabled' => $data['enabled'], 'deposit_address' => $address]);
            } elseif ($kind === 'company-rail' && $tenant) {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $data = Validator::make($input, ['code' => 'required|exists:asset_rails,code', 'deposit_enabled' => 'required|boolean', 'withdrawal_enabled' => 'required|boolean', 'minimum' => 'nullable|string|regex:/^\d{1,12}(?:\.\d{1,18})?$/', 'fee' => 'nullable|string|regex:/^\d{1,12}(?:\.\d{1,18})?$/'])->validate();
                $rail = AssetRail::findOrFail($data['code']);
                if ($data['deposit_enabled'] && ($data['minimum'] ?? null) === null || $data['withdrawal_enabled'] && ($data['fee'] ?? null) === null) {
                    throw new DomainException('CONFIG_INCOMPLETE', 'Complete the required configuration first.');
                }
                foreach (['minimum', 'fee'] as $field) {
                    if (isset($data[$field])) {
                        try {
                            $value = BigDecimal::of($data[$field])->toScale(AssetCatalog::chainScale($rail->asset_code));
                            if ($field === 'minimum' && ! $value->isPositive()) {
                                throw new \InvalidArgumentException;
                            }
                        } catch (\Throwable) {
                            throw new DomainException('AMOUNT_INVALID', 'Enter an amount within the currency precision.');
                        }
                    }
                }
                CompanyRail::updateOrCreate(['tenant_id' => $tenant->id, 'rail_code' => $rail->code], ['deposit_enabled' => $data['deposit_enabled'], 'withdrawal_enabled' => $data['withdrawal_enabled'], 'minimum_deposit' => $data['minimum'] ?? null, 'withdrawal_fee' => $data['fee'] ?? null]);
            } elseif ($kind === 'exchange' && $tenant) {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $data = Validator::make($input, ['asset' => 'required|in:USDC,ETH,BTC', 'enabled' => 'required|boolean', 'fee' => 'nullable|string|regex:/^\d{1,2}(?:\.\d{1,8})?$/', 'single' => 'nullable|string|regex:/^\d{1,12}(?:\.\d{1,8})?$/', 'daily' => 'nullable|string|regex:/^\d{1,12}(?:\.\d{1,8})?$/'])->validate();
                if ($data['enabled'] && (! isset($data['fee'],$data['single'],$data['daily']) || ! BigDecimal::of($data['single'])->isPositive() || BigDecimal::of($data['daily'])->isLessThan($data['single']))) {
                    throw new DomainException('CONFIG_INCOMPLETE', 'Complete the required configuration first.');
                }
                foreach (['single', 'daily'] as $field) {
                    if (isset($data[$field]) && ! BigDecimal::of($data[$field])->isPositive()) {
                        throw new DomainException('CONFIG_INCOMPLETE', 'Complete the required configuration first.');
                    }
                }
                ExchangePolicy::updateOrCreate(['tenant_id' => $tenant->id, 'asset_code' => $data['asset']], ['enabled' => $data['enabled'], 'fee_percent' => $data['fee'] ?? null, 'single_limit' => $data['single'] ?? null, 'daily_limit' => $data['daily'] ?? null]);
            } else {
                abort(422);
            }
            $this->audit->record($tenant?->id, 'ADMIN', $actor->id, 'ASSET_CONFIGURATION_UPDATED', 'asset_configuration', $tenant?->id, null, ['section' => $kind]);
        }, 3);
    }
}
