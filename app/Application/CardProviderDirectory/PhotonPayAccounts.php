<?php

namespace App\Application\CardProviderDirectory;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Providers\Card\PhotonPayMerchantReport;
use App\Infrastructure\Providers\Card\PhotonPayTransactionNormalizer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PhotonPayAccounts
{
    public const BASES = ['sandbox' => 'https://x-api.sandbox.photontech.cc', 'production' => 'https://x-api.photonpay.com'];

    public function authorize(AdminUser $actor): void
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->status->value === 'ACTIVE' && app(AuthorizationService::class)->allows($actor, ScopeType::Platform, null, 'card_provider_reference.manage'), 403);
    }

    public function save(string $id, #[\SensitiveParameter] array $input, AdminUser $actor): void
    {
        $this->authorize($actor);
        $data = Validator::make($input, [
            'version' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120'],
            'environment' => ['required', 'in:sandbox,production'], 'enabled' => ['required', 'boolean'],
            'app_id' => ['required', 'string', 'max:180'], 'app_secret' => ['nullable', 'string', 'max:2000'],
            'private_key' => ['nullable', 'string', 'max:16000'], 'webhook_public_key' => ['nullable', 'string', 'max:16000'],
            'account_id' => ['required', 'string', 'max:100'], 'member_id' => ['required', 'string', 'max:100'],
            'matrix_account' => ['nullable', 'string', 'max:100'],
        ])->validate();
        DB::transaction(function () use ($id, $data, $actor): void {
            $this->authorize($actor);
            $row = CardProviderReference::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($row->runtime_driver !== 'UNCONFIGURED') {
                throw ValidationException::withMessages(['environment' => 'This merchant cannot be converted to PhotonPay.']);
            }
            if ($row->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Configuration changed. Refresh before saving.']);
            }
            if ($row->photonpay_migration_error) {
                throw ValidationException::withMessages(['environment' => 'Resolve the legacy connection identity mismatch before configuring this account.']);
            }
            $old = $row->photonpay_issuing_encrypted ? json_decode(Crypt::decryptString($row->photonpay_issuing_encrypted), true, 512, JSON_THROW_ON_ERROR) : [];
            $c = ['base_url' => self::BASES[$data['environment']], 'app_id' => trim($data['app_id']),
                'app_secret' => ! empty($data['app_secret']) ? $data['app_secret'] : ($old['app_secret'] ?? ''),
                'private_key' => ! empty($data['private_key']) ? $data['private_key'] : ($old['private_key'] ?? ''),
                'account_id' => trim($data['account_id']), 'member_id' => trim($data['member_id']),
                'matrix_account' => empty($data['matrix_account']) ? null : trim($data['matrix_account'])];
            $webkey = ! empty($data['webhook_public_key']) ? $data['webhook_public_key'] : ($row->photonpay_webhook_key_encrypted ? Crypt::decryptString($row->photonpay_webhook_key_encrypted) : '');
            $c['private_key'] = $this->normalizeKey($c['private_key'], true);
            $webkey = $this->normalizeKey($webkey, false);
            if ($c['app_secret'] === '') {
                throw ValidationException::withMessages(['app_secret' => 'PhotonPay App Secret is required.']);
            }
            $identity = array_intersect_key($c, array_flip(['base_url', 'account_id', 'member_id', 'matrix_account']));
            if ($row->photonpay_identity && $row->photonpay_identity != $identity && $this->hasBusiness($id)) {
                throw ValidationException::withMessages(['account_id' => 'Account identity is locked. Create a separate account instead.']);
            }
            $identityLock = hash('sha256', json_encode($identity));
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['photonpay-identity:'.$identityLock]);
            if (CardProviderReference::where('id', '!=', $id)->where('photonpay_identity', json_encode($identity))->exists()) {
                throw ValidationException::withMessages(['account_id' => 'This PhotonPay account is already configured.']);
            }
            $changed = $old != $c || ($row->photonpay_webhook_key_encrypted ? Crypt::decryptString($row->photonpay_webhook_key_encrypted) : '') !== $webkey;
            $values = ['name' => trim($data['name']), 'photonpay_identity' => $identity, 'photonpay_enabled' => $data['enabled'], 'updated_by' => $actor->id, 'version' => $row->version + 1];
            if ($changed) {
                $values += ['photonpay_issuing_encrypted' => Crypt::encryptString(json_encode($c, JSON_THROW_ON_ERROR)),
                    'photonpay_reporting_encrypted' => Crypt::encryptString(json_encode(array_intersect_key($c, array_flip(['base_url', 'app_id', 'app_secret', 'account_id', 'member_id', 'matrix_account'])), JSON_THROW_ON_ERROR)),
                    'photonpay_webhook_key_encrypted' => Crypt::encryptString($webkey), 'photonpay_checked_at' => null, 'photonpay_check_status' => 'UNCHECKED', 'bin_catalog' => null];
                DB::table('card_products')->where('card_provider_reference_id', $id)->update(['supported_form_factors' => '[]', 'form_factors_synced_at' => null]);
            }
            $row->forceFill($values)->save();
            app(AuditLogger::class)->record(null, 'ADMIN', $actor->id, 'PHOTONPAY_ACCOUNT_SAVED', 'card_provider_reference', $id, null, ['enabled' => (bool) $row->photonpay_enabled, 'credentials_changed' => $changed, 'version' => $row->version]);
        });
    }

    private function normalizeKey(#[\SensitiveParameter] string $value, bool $private): string
    {
        $value = trim(str_replace('\\n', "\n", $value));
        $candidates = [$value];
        if (! str_contains($value, '-----BEGIN ')) {
            $body = preg_replace('/\s+/', '', $value);
            if ($body !== '' && base64_decode($body, true) !== false) {
                foreach ($private ? ['PRIVATE KEY', 'RSA PRIVATE KEY'] : ['PUBLIC KEY', 'RSA PUBLIC KEY'] as $label) {
                    $candidates[] = "-----BEGIN {$label}-----\n".chunk_split($body, 64, "\n")."-----END {$label}-----";
                }
            }
        }
        foreach ($candidates as $candidate) {
            $key = $private ? @openssl_pkey_get_private($candidate) : @openssl_pkey_get_public($candidate);
            $details = $key ? openssl_pkey_get_details($key) : false;
            if (! $details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < ($private ? 2048 : 1024)) {
                continue;
            }
            if (! $private) {
                return $details['key'];
            }
            if (openssl_pkey_export($key, $pem)) {
                return $pem;
            }
        }
        throw ValidationException::withMessages([
            $private ? 'private_key' : 'webhook_public_key' => $private
                ? 'Enter a valid RSA signing private key of at least 2048 bits (PEM or Base64).'
                : 'Enter a valid PhotonPay RSA webhook public key of at least 1024 bits (PEM or Base64).',
        ]);
    }

    public function check(string $id, AdminUser $actor): void
    {
        $this->authorize($actor);
        $snapshot = CardProviderReference::findOrFail($id);
        $ok = false;
        $bins = null;
        try {
            if (! $snapshot->photonpay_identity || ! $snapshot->photonpay_issuing_encrypted || ! $snapshot->photonpay_webhook_key_encrypted || $snapshot->photonpay_migration_error) {
                throw new \RuntimeException;
            }
            $c = json_decode(Crypt::decryptString($snapshot->photonpay_issuing_encrypted), true, 512, JSON_THROW_ON_ERROR);
            if (! in_array($c['base_url'], self::BASES, true)) {
                throw new \RuntimeException;
            }
            $auth = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()->withHeaders(['Authorization' => 'basic '.base64_encode($c['app_id'].'/'.$c['app_secret'])])->withBody('', 'application/json')->post($c['base_url'].'/oauth2/token/accessToken');
            if (! $auth->successful() || $auth->json('code') !== '0000' || ! is_string($auth->json('data.token'))) {
                throw new \RuntimeException;
            }
            $a = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()->withHeaders(['X-PD-TOKEN' => $auth->json('data.token')])->get($c['base_url'].'/wallet/openApi/v4/account/single', array_filter(['currency' => 'USD', 'accountType' => 'FT10001', 'memberId' => $c['member_id'], 'matrixAccount' => $c['matrix_account'] ?? null], fn ($v) => $v !== null && $v !== ''));
            $accountResponse = (new PhotonPayTransactionNormalizer)->decode($a->body());
            if (! $a->successful() || ($accountResponse['code'] ?? null) !== '0000' || ($accountResponse['data']['accountNo'] ?? null) !== $c['account_id'] || ($accountResponse['data']['memberId'] ?? null) !== $c['member_id'] || ($accountResponse['data']['currency'] ?? null) !== 'USD' || ($accountResponse['data']['accountType'] ?? null) !== 'FT10001') {
                throw new \RuntimeException;
            }
            $bins = app(PhotonPayMerchantReport::class)->bins($snapshot->photonpay_reporting_encrypted, true);
            $ok = is_array($bins);
        } catch (\Throwable) {
            $ok = false;
        }
        DB::transaction(function () use ($snapshot, $actor, $ok, $bins): void {
            $this->authorize($actor);
            $r = CardProviderReference::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if ($r->version !== $snapshot->version) {
                throw ValidationException::withMessages(['version' => 'Configuration changed. Check again.']);
            }
            $r->forceFill(['photonpay_check_status' => $ok ? 'VERIFIED' : 'FAILED', 'photonpay_checked_at' => now(), 'bin_catalog' => $ok ? $bins : null])->save();
            foreach (CardProduct::where('card_provider_reference_id', $r->id)->lockForUpdate()->get() as $p) {
                $p->forceFill(['supported_form_factors' => $ok ? (collect($bins)->firstWhere('bin', $p->provider_product_ref)['formFactors'] ?? []) : [], 'form_factors_synced_at' => $ok ? now() : null])->save();
            }
            app(AuditLogger::class)->record(null, 'ADMIN', $actor->id, 'PHOTONPAY_ACCOUNT_CHECKED', 'card_provider_reference', $r->id, null, ['status' => $ok ? 'VERIFIED' : 'FAILED']);
        });
        if (! $ok) {
            throw ValidationException::withMessages(['connection' => 'PhotonPay connection or account ownership could not be verified.']);
        }
    }

    public function hasBusiness(string $id): bool
    {
        $products = DB::table('card_products')->where('card_provider_reference_id', $id)->select('id');
        foreach (['provider_cardholders', 'card_recipient_applications', 'card_issue_orders', 'user_cards'] as $table) {
            if (DB::table($table)->whereIn('card_product_id', $products)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function publicConfiguration(CardProviderReference $r): ?array
    {
        if (! $r->photonpay_issuing_encrypted && ! $r->photonpay_identity) {
            return null;
        }
        try {
            $c = json_decode(Crypt::decryptString($r->photonpay_issuing_encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $c = [];
        }

        return ['identityLocked' => $this->hasBusiness($r->id), 'environment' => ($c['base_url'] ?? '') === self::BASES['sandbox'] ? 'sandbox' : 'production', 'appId' => $c['app_id'] ?? '',
            'accountId' => $c['account_id'] ?? '', 'memberId' => $c['member_id'] ?? '', 'matrixAccount' => $c['matrix_account'] ?? '',
            'enabled' => (bool) $r->photonpay_enabled, 'checkStatus' => $r->photonpay_check_status ?? 'UNCHECKED', 'checkedAt' => $r->photonpay_checked_at?->toIso8601String(),
            'complete' => (bool) ($r->photonpay_issuing_encrypted && $r->photonpay_webhook_key_encrypted && $r->photonpay_identity && ! $r->photonpay_migration_error),
            'migrationError' => $r->photonpay_migration_error, 'callbackUrl' => url('/webhooks/card-provider/'.$r->id)];
    }
}
