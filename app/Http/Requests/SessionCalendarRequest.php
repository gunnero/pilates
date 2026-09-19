<?php

namespace App\Http\Requests;

class SessionCalendarRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'], 'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'], 'location_id' => ['sometimes', 'uuid'], 'trainer_id' => ['sometimes', 'uuid'], 'group_id' => ['sometimes', 'uuid'], 'status' => ['sometimes', 'in:scheduled,cancelled,all'], 'page' => ['sometimes', 'integer', 'between:1,10000'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
    }
}
