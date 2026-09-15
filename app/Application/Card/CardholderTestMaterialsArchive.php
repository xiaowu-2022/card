<?php

namespace App\Application\Card;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Services\CardholderMaterials;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\User\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class CardholderTestMaterialsArchive
{
    public function __construct(private CardholderMaterials $cipher, private AuditLogger $audit) {}

    private function assertScope(string $tenantId, string $userId, string $productId): void
    {
        abort_unless(app()->environment('local', 'testing') && in_array(DB::connection()->getDatabaseName(), ['card_mock', 'card_ui_test'], true), 404);
        abort_unless(Str::isUuid($tenantId) && Str::isUuid($userId) && Str::isUuid($productId), 404);
        User::query()->where('tenant_id', $tenantId)->whereKey($userId)->where('status', 'ACTIVE')->whereHas('tenant', fn ($q) => $q->where('status', 'ACTIVE'))->firstOrFail();
        TenantCardProductConfig::query()->where('tenant_id', $tenantId)->where('card_product_id', $productId)->firstOrFail();
    }

    public function save(string $tenantId, string $userId, #[\SensitiveParameter] array $data, ?string $requestId = null): string
    {
        $productId = $data['card_product_id'];
        $this->assertScope($tenantId, $userId, $productId);
        $fields = [];
        foreach (['request_id', 'card_product_id', 'legal_first_name', 'legal_last_name', 'date_of_birth', 'email', 'mobile', 'mobile_country_code', 'nationality_country_code', 'residential_address', 'residential_city', 'residential_state', 'residential_country_code', 'residential_postal_code', 'document_type'] as $field) {
            $fields[$field] = (string) ($data[$field] ?? '');
        }
        $documents = [];
        foreach (['front', 'back'] as $side) {
            $file = $data[$side] ?? null;
            if ($file !== null) {
                abort_unless($file instanceof UploadedFile && $file->isValid() && $file->getSize() <= 6291456 && in_array($file->getMimeType(), ['image/png', 'image/jpeg'], true), 422);
                $documents[$side] = ['mime' => $file->getMimeType(), 'content' => base64_encode($file->getContent())];
            }
        }
        $id = (string) Str::uuid();
        $path = 'card-test-materials/'.$tenantId.'/'.$userId.'/'.$productId.'/'.$id;
        $envelope = ['version' => 1, 'tenant_id' => $tenantId, 'user_id' => $userId, 'fields' => $fields, 'documents' => $documents];
        abort_unless(Storage::disk('private')->put($path, $this->cipher->encrypt(json_encode($envelope, JSON_THROW_ON_ERROR))), 503);
        try {
            $this->audit->record($tenantId, 'USER', $userId, 'CARD_TEST_MATERIALS_SAVED', 'card_test_materials', $id, null, ['product_id' => $productId, 'document_count' => count($documents)], $requestId);
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($path);
            throw $e;
        }

        return $id;
    }

    public function read(string $tenantId, string $userId, string $productId, ?string $requestId = null): array
    {
        $this->assertScope($tenantId, $userId, $productId);
        $saved = DB::table('audit_logs')->where('tenant_id', $tenantId)->where('actor_type', 'USER')->where('actor_id', $userId)->where('action', 'CARD_TEST_MATERIALS_SAVED')->where('after_data->product_id', $productId)->orderByDesc('created_at')->orderByDesc('id')->first();
        abort_unless($saved && Str::isUuid($saved->resource_id), 404);
        $path = 'card-test-materials/'.$tenantId.'/'.$userId.'/'.$productId.'/'.$saved->resource_id;
        $contents = Storage::disk('private')->get($path);
        abort_unless(is_string($contents), 404);
        $envelope = json_decode($this->cipher->decrypt($contents), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(($envelope['tenant_id'] ?? null) === $tenantId && ($envelope['user_id'] ?? null) === $userId && ($envelope['fields']['card_product_id'] ?? null) === $productId, 404);
        $this->audit->record($tenantId, 'USER', $userId, 'CARD_TEST_MATERIALS_READ', 'card_test_materials', $saved->resource_id, null, ['product_id' => $productId], $requestId);

        return ['fields' => $envelope['fields'], 'documents' => $envelope['documents']];
    }
}
