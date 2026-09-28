<?php

namespace App\Application\Media;

use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Services\CardholderMaterials;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Support\Models\SupportMessage;
use Illuminate\Support\Facades\DB;

final class ImageReferences
{
    /** Only business-owned image references, never a recursive disk crawl. */
    public function all(?string $tenant = null): \Generator
    {
        foreach (DB::table('tenant_branding')->when($tenant, fn ($q) => $q->where('tenant_id', $tenant))->orderBy('tenant_id')->cursor() as $row) {
            foreach (['logo_object_key', 'favicon_object_key'] as $field) {
                if ($row->$field) {
                    yield $this->ref($row->tenant_id, 'public', $row->$field, 'branding', $row->tenant_id);
                }
            }
        }
        foreach (DB::table('tenant_business_settings')->when($tenant, fn ($q) => $q->where('tenant_id', $tenant))->whereNotNull('invitation_poster_background')->orderBy('tenant_id')->cursor() as $row) {
            yield $this->ref($row->tenant_id, 'private', $row->invitation_poster_background, 'poster', $row->tenant_id);
        }
        foreach (KycApplication::when($tenant, fn ($q) => $q->where('tenant_id', $tenant))->lazyById(100) as $row) {
            foreach (['front_object_key', 'back_object_key'] as $field) {
                if ($row->$field) {
                    yield $this->ref($row->tenant_id, (string) config('kyc.document_disk'), $row->$field, 'kyc', $row->id);
                }
            }
        }
        foreach (SupportMessage::when($tenant, fn ($q) => $q->where('tenant_id', $tenant))->whereNotNull('image_object_key')->lazyById(100) as $row) {
            yield $this->ref($row->tenant_id, 'private', $row->image_object_key, 'support', $row->id, 'support');
        }
        foreach (ProviderCardholder::when($tenant, fn ($q) => $q->where('tenant_id', $tenant))->whereNotNull('materials_encrypted')->lazyById(100) as $row) {
            $data = json_decode(app(CardholderMaterials::class)->decrypt($row->materials_encrypted), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['documents'] ?? [] as $key) {
                if (is_string($key) && $key !== '') {
                    yield $this->ref($row->tenant_id, 'private', $key, 'card', $row->id, 'card');
                }
            }
        }
    }

    private function ref(string $tenant, string $disk, string $key, string $purpose, string $reference, string $codec = 'plain'): array
    {
        return compact('tenant', 'disk', 'key', 'purpose', 'reference', 'codec');
    }

    public function contains(string $tenant, string $disk, string $key): bool
    {
        foreach ($this->all($tenant) as $ref) {
            if ($ref['disk'] === $disk && $ref['key'] === $key) {
                return true;
            }
        }

        return false;
    }
}
