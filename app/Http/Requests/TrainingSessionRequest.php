<?php

namespace App\Http\Requests;

class TrainingSessionRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['group_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid'], 'location_id' => ['required', 'uuid'], 'trainer_id' => ['required', 'uuid'], 'capacity' => ['required', 'integer', 'between:1,100'], 'local_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i', 'after:start_time']];
    }
}
