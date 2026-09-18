<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class MemberSearchRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => [$this->isMethod('GET') ? 'prohibited' : 'sometimes', 'nullable', 'string', 'min:2', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'archived', 'all'])],
            'digital_access_mode' => ['sometimes', Rule::in(['app', 'assisted', 'offline'])],
            'preferred_location_id' => ['sometimes', 'uuid']];
    }
}
