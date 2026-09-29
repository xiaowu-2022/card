<?php

namespace App\Http\Requests\Concerns;

use App\Application\Media\DirectImageUploads;
use App\Application\Media\DirectKycUploads;
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
                $data[$field] = $purpose === 'kyc'
                    ? app(DirectKycUploads::class)->resolve($tenant, $user, $data[$field.'_upload_id'], $field, $data[$field.'_url'] ?? '')
                    : app(DirectImageUploads::class)->resolve($tenant, $user, $data[$field.'_upload_id'], $purpose, $field);
            }
            unset($data[$field.'_upload_id'], $data[$field.'_url']);
        }

        return $data;
    }
}
