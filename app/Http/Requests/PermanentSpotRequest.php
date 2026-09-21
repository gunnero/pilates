<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class PermanentSpotRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['member_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid'], 'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'], 'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from', 'before_or_equal:2100-12-31'], 'status' => ['required', Rule::in(['active', 'inactive'])]];
    }
}
