<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class OrganizationRequest extends LifecycleRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:160'], 'locale' => ['sometimes', Rule::in(['mk', 'en'])],
            'timezone' => ['sometimes', 'required', 'timezone:all'], 'currency' => ['sometimes', Rule::in(['MKD'])],
            // Suspension/recovery is an operator capability, never delegated by organizations.manage.
            'status' => ['sometimes', Rule::in(['active', 'inactive'])]];
    }
}
