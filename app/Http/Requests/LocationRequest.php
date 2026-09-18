<?php

namespace App\Http\Requests;

use App\Support\LocationStatus;
use Illuminate\Validation\Rule;

class LocationRequest extends LifecycleRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'required', 'string', 'max:160'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'], 'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'], 'status' => ['sometimes', Rule::enum(LocationStatus::class)]];
    }
}
