<?php

namespace App\Http\Requests;

class GroupSearchRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'between:1,10000'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'search' => [$this->isMethod('GET') ? 'prohibited' : 'sometimes', 'nullable', 'string', 'min:2', 'max:100'], 'status' => ['sometimes', 'in:active,inactive,archived,all'], 'location_id' => ['sometimes', 'uuid'], 'trainer_id' => ['sometimes', 'uuid']];
    }
}
