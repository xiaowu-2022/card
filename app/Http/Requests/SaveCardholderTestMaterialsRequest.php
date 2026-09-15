<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveCardholderTestMaterialsRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = ['request_id' => ['required', 'uuid'], 'card_product_id' => ['required', 'uuid'], 'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'], 'identity_number' => ['prohibited']];
        foreach (['legal_first_name', 'legal_last_name', 'date_of_birth', 'email', 'mobile', 'mobile_country_code', 'nationality_country_code', 'residential_address', 'residential_city', 'residential_state', 'residential_country_code', 'residential_postal_code', 'document_type'] as $field) {
            $rules[$field] = ['present', 'nullable', 'string', 'max:254'];
        }
        foreach (['front', 'back'] as $side) {
            $rules[$side] = ['nullable', 'file', 'image', 'mimetypes:image/jpeg,image/png', 'max:6144', 'dimensions:max_width=12000,max_height=12000'];
        }

        return $rules;
    }
}
