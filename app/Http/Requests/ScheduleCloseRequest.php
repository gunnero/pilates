<?php

namespace App\Http\Requests;

class ScheduleCloseRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['effective_until' => ['required', 'date_format:Y-m-d', 'before_or_equal:2100-12-31']];
    }
}
