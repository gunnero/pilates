<?php

namespace App\Http\Requests;

class TrainingGroupRequest extends LifecycleRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return ['name' => [$required, 'string', 'max:160', 'not_regex:/[\x00-\x1F\x7F]/u'], 'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'location_id' => [$required, 'uuid'], 'primary_trainer_id' => ['sometimes', 'nullable', 'uuid'], 'capacity' => [$required, 'integer', 'between:1,100']];
    }
}
