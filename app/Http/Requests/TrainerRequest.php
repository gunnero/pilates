<?php

namespace App\Http\Requests;

class TrainerRequest extends LifecycleRequest
{
    public function rules(): array
    {
        $rules = ['display_name' => ['sometimes', 'nullable', 'string', 'max:160', 'not_regex:/[\x00-\x1F\x7F]/u'], 'bio' => ['sometimes', 'nullable', 'string', 'max:1000']];
        if ($this->isMethod('POST')) {
            $rules['membership_id'] = ['required', 'uuid'];
        }

        return $rules;
    }
}
