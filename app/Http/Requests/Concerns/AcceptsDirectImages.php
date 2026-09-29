<?php

namespace App\Http\Requests\Concerns;

use App\Application\Media\DirectImageUploads;
use App\Application\Media\DirectKycUploads;
use App\Domain\Media\DirectImageUpload;
use Illuminate\Validation\Rule;

trait AcceptsDirectImages
{
    private function imageRules(string $field, bool $required, array $rules): array
    {
        return [
            $field => [Rule::requiredIf($required && ! $this->filled($field.'_upload_id')), 'nullable', Rule::prohibitedIf($this->filled($field.'_upload_id')), ...$rules],
            $field.'_upload_id' => ['nullable', 'uuid', Rule::prohibitedIf(! $this->attributes->get('consumer_user')), Rule::prohibitedIf($this->hasFile($field))],
        ];
    }

    public function resolvedImages(string $tenant, string $user, string $purpose, array $fields = ['front', 'back']): array
    {
        $data = $this->validated();
        foreach ($fields as $field) {
            if (! empty($data[$field.'_upload_id'])) {
                $legacyUrl = $purpose === 'kyc' && DirectImageUpload::whereKey($data[$field.'_upload_id'])->where('tenant_id', $tenant)->where('user_id', $user)->where('upload_mode', 'kyc_url')->exists();
                abort_if(! $legacyUrl && ! empty($data[$field.'_url']), 422);
                $data[$field] = $legacyUrl
                    ? app(DirectKycUploads::class)->resolve($tenant, $user, $data[$field.'_upload_id'], $field, $data[$field.'_url'] ?? '')
                    : app(DirectImageUploads::class)->resolve($tenant, $user, $data[$field.'_upload_id'], $purpose, $field);
            }
            unset($data[$field.'_upload_id'], $data[$field.'_url']);
        }

        return $data;
    }
}
