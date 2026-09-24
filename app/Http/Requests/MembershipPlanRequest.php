<?php

namespace App\Http\Requests;

class MembershipPlanRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:1000'], 'price_minor' => ['required', 'integer:strict', 'min:0', 'max:100000000'], 'duration_unit' => ['required', 'in:days,months'], 'duration_count' => ['required', 'integer:strict', 'min:1', $this->input('duration_unit') === 'months' ? 'max:36' : 'max:1095'], 'status' => ['required', 'in:active,inactive,archived']];
    }
}
