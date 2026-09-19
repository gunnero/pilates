<?php

namespace App\Http\Requests;

class ScheduleRuleRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['weekday' => ['required', 'integer', 'between:1,7'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'], 'effective_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from', 'before_or_equal:2100-12-31']];
    }
}
