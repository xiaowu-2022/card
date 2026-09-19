<?php

namespace App\Application\Assets;

use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetCatalog;
use App\Domain\Assets\AssetRail;
use App\Domain\Assets\ChainConnection;
use App\Domain\Assets\CompanyRail;
use App\Domain\Assets\ExchangePolicy;
use App\Domain\Assets\MarketSettings;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Withdrawal\Services\WithdrawalAddressProtector;
use App\Infrastructure\Assets\BitcoinAddress;
use App\Infrastructure\Assets\ChainReader;
use App\Infrastructure\Assets\PublicChainNodes;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class ConfigureAssetsAction
{
    public function __construct(private AssetAccess $access, private AuditLogger $audit) {}

    public function execute(AdminUser $actor, array $input, ?Tenant $tenant = null): void
    {
        $this->access->platform($actor, 'tenant.manage');
        if (! $tenant && ($input['kind'] ?? '') === 'market-refresh') {
            $snapshot = app(MarketPrices::class)->refresh();
            $this->audit->record(null, 'ADMIN', $actor->id, 'ASSET_PRICES_REFRESHED', 'asset_market_snapshot', $snapshot->id);

            return;
        }
        $batch = ($input['kind'] ?? '') === 'batch';
        if ($batch) {
            Validator::make($input, [
                'sections' => 'required|array|list|min:1|max:10',
                'sections.*' => 'required|array',
                'sections.*.network' => 'sometimes|string',
                'sections.*.code' => 'sometimes|string',
                'sections.*.asset' => 'sometimes|string',
                'sections.*.kind' => $tenant ? 'required|in:company-rail,company-tron,exchange' : 'required|in:market,network,rail,tron-rail',
            ])->validate();
        }
        $sections = $batch ? $input['sections'] : [$input];
        $prepared = [];
        $seen = [];
        // Probe nodes before starting any database transaction. Preserve original error indexes.
        foreach ($sections as $index => $section) {
            $kind = $section['kind'] ?? '';
            $key = $kind.':'.match ($kind) {
                'network', 'network-test' => $section['network'] ?? '',
                'rail', 'company-rail' => $section['code'] ?? '',
                'exchange' => $section['asset'] ?? '',
                default => '',
            };
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['sections' => 'Duplicate configuration section.']);
            }
            $seen[$key] = true;
            try {
                $prepared[$index] = ! $tenant && in_array($kind, ['network', 'network-test'], true) ? $this->network($section) : null;
            } catch (DomainException|ValidationException $e) {
                $this->sectionError($e, $batch, $index);
            }
        }
        if (! $batch && ($input['kind'] ?? '') === 'network-test') {
            return;
        }
        // Networks must be saved before dependent rails, regardless of client array order.
        uksort($sections, fn ($a, $b) => (($sections[$a]['kind'] ?? '') === 'network' ? 0 : 1) <=> (($sections[$b]['kind'] ?? '') === 'network' ? 0 : 1));
        DB::transaction(function () use ($actor, $sections, $prepared, $tenant, $batch): void {
            AssetAccess::lock('asset-configuration');
            foreach ($sections as $index => $section) {
                try {
                    $this->persist($actor, $section, $tenant, $prepared[$index]);
                } catch (DomainException|ValidationException $e) {
                    $this->sectionError($e, $batch, $index);
                }
            }
        }, 3);
    }

    private function sectionError(DomainException|ValidationException $e, bool $batch, int $index): never
    {
        if (! $batch) {
            throw $e;
        }
        $errors = $e instanceof ValidationException ? $e->errors() : ['form' => [$e->getMessage()]];
        throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($messages, $key) => ['sections.'.$index.'.'.$key => $messages])->all());
    }

    private function persist(AdminUser $actor, array $input, ?Tenant $tenant, ?ChainConnection $network): void
    {
        $kind = $input['kind'] ?? '';
        if ($kind === 'tron-rail' && ! $tenant) {
            $data = Validator::make($input, ['address' => 'required|string|max:128'])->validate();
            $address = app(WithdrawalAddressProtector::class)->normalize($data['address']);
            DB::table('asset_tron_settings')->where('id', 1)->update(['deposit_address' => $address, 'updated_at' => now()]);
        } elseif ($kind === 'market' && ! $tenant) {
            // Market data is always enabled and public, including stale client submissions.
            MarketSettings::query()->whereKey(1)->update(['enabled' => true, 'api_key' => null]);
        } elseif ($kind === 'network' && ! $tenant) {
            $c = ChainConnection::query()->whereKey($network->network)->lockForUpdate()->firstOrFail();
            if ($c->start_height !== null && $c->start_height !== $network->start_height) {
                throw new DomainException('SCAN_BOUNDARY_IMMUTABLE', 'The initial scan boundary cannot be changed.');
            }
            $c->fill([
                'rpc_url' => $network->rpc_url, 'enabled' => $network->enabled,
                'confirmations' => $network->confirmations, 'start_height' => $network->start_height,
                'next_height' => $c->next_height ?? $network->start_height,
                'credential' => $network->credential,
            ]);
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
            } elseif (! BitcoinAddress::valid($address)) {
                throw new DomainException('ADDRESS_INVALID', 'Enter a valid destination address.');
            }
            if ($rail->network === 'BITCOIN' && str_starts_with(strtolower($address), 'bc1')) {
                $address = strtolower($address);
            }
            $rail->update(['enabled' => $data['enabled'], 'deposit_address' => $address]);
        } elseif ($kind === 'company-tron' && $tenant) {
            $data = Validator::make($input, ['minimum' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/D'], 'fee_percent' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]?)(?:\.\d{1,8})?$/D']])->validate();
            app(UpdateTenantBusinessSettingsAction::class)->execute($tenant, ['withdrawal_fee_percent' => $data['fee_percent'], 'tron_minimum_deposit' => $data['minimum']], $actor);
        } elseif ($kind === 'company-rail' && $tenant) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $data = Validator::make($input, ['code' => 'required|exists:asset_rails,code', 'deposit_enabled' => 'required|boolean', 'withdrawal_enabled' => 'required|boolean', 'minimum' => 'nullable|string|regex:/^\d{1,12}(?:\.\d{1,18})?$/', 'fee_percent' => ['nullable', 'string', 'regex:/^(?:0|[1-9][0-9]?)(?:\.[0-9]{1,8})?$/D']])->validate();
            $rail = AssetRail::findOrFail($data['code']);
            if ($data['deposit_enabled'] && ($data['minimum'] ?? null) === null || $data['withdrawal_enabled'] && ($data['fee_percent'] ?? null) === null) {
                throw new DomainException('CONFIG_INCOMPLETE', 'Complete the required configuration first.');
            }
            foreach (['minimum'] as $field) {
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
            CompanyRail::updateOrCreate(['tenant_id' => $tenant->id, 'rail_code' => $rail->code], ['deposit_enabled' => $data['deposit_enabled'], 'withdrawal_enabled' => $data['withdrawal_enabled'], 'minimum_deposit' => $data['minimum'] ?? null, 'withdrawal_fee_percent' => $data['fee_percent'] ?? null]);
        } elseif ($kind === 'exchange' && $tenant) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $data = Validator::make($input, ['asset' => 'required|in:USDC,ETH,BTC', 'enabled' => 'required|boolean'])->validate();
            // Legacy clients cannot reintroduce a fee or limits through stale fields.
            ExchangePolicy::updateOrCreate(['tenant_id' => $tenant->id, 'asset_code' => $data['asset']], [
                'enabled' => $data['enabled'], 'fee_percent' => '0', 'single_limit' => null, 'daily_limit' => null,
            ]);
        } else {
            abort(422);
        }
        $this->audit->record($tenant?->id, 'ADMIN', $actor->id, 'ASSET_CONFIGURATION_UPDATED', 'asset_configuration', $tenant?->id, null, ['section' => $kind]);
    }

    /** Probe outside transactions; never scan, persist observations or credit an account. */
    private function network(array $input): ChainConnection
    {
        $data = Validator::make($input, [
            'network' => 'required|in:ETHEREUM,BITCOIN', 'enabled' => 'required|boolean',
            'use_public' => 'sometimes|boolean', 'rpc_url' => 'required_if:use_public,false|nullable|url:https|max:1024',
            'username' => 'nullable|string|max:256', 'credential' => 'nullable|string|max:512',
            'start_height' => 'nullable|integer|min:0', 'start_from_current' => 'sometimes|boolean',
            'confirmations' => 'required|integer|min:6|max:10000',
        ])->validate();
        $c = ChainConnection::findOrFail($data['network']);
        $url = ($data['use_public'] ?? false) || empty($data['rpc_url'])
            ? PublicChainNodes::URLS[$c->network] : $data['rpc_url'];
        if (! PublicChainNodes::allowed($c->network, $url)) {
            throw new DomainException('RPC_HOST_NOT_ALLOWED', 'The node hostname must be explicitly allowed in deployment configuration.');
        }
        // An endpoint change must never carry a previous provider's secret to a new host.
        if ($url !== $c->rpc_url || $url === PublicChainNodes::URLS[$c->network]) {
            $c->credential = null;
        }
        if ($url !== PublicChainNodes::URLS[$c->network] && ! empty($data['credential'])) {
            $c->credential = empty($data['username']) ? ['api_key' => $data['credential']] : ['username' => $data['username'], 'password' => $data['credential']];
        }
        $c->rpc_url = $url;
        $c->confirmations = (int) $data['confirmations'];
        $start = $c->start_height;
        if ($start !== null && isset($data['start_height']) && $start !== (int) $data['start_height']) {
            throw new DomainException('SCAN_BOUNDARY_IMMUTABLE', 'The initial scan boundary cannot be changed.');
        }
        if ($data['enabled'] || $input['kind'] === 'network-test') {
            $c->enabled = true;
            $reader = app(ChainReader::class);
            try {
                $height = $reader->finalHeight($c);
                // Verify public block/receipt reads; contract-only ETH deposits use manual confirmation.
                $reader->block($c, $height);
            } catch (DomainException $e) {
                throw $e;
            } catch (\Throwable) {
                throw new DomainException('CHAIN_UNAVAILABLE', 'The network connection is unavailable.', 503);
            }
            if ($start === null && ($data['start_from_current'] ?? false)) {
                $start = $height + 1;
            }
        }
        $start ??= isset($data['start_height']) ? (int) $data['start_height'] : null;
        if ($data['enabled'] && $start === null) {
            throw new DomainException('CONFIG_INCOMPLETE', 'Complete the required configuration first.');
        }
        $c->start_height = $start;
        $c->enabled = $data['enabled'];

        return $c;
    }
}
