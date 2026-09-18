<?php

namespace App\Http\Requests;

use App\Support\DigitalAccessMode;
use App\Support\MemberContact;
use Illuminate\Validation\Rule;

class StudioMemberRequest extends LifecycleRequest
{
    protected function prepareForValidation(): void
    {
        $data = [];
        if (is_string($this->input('email'))) {
            $data['email'] = mb_strtolower(trim($this->input('email')));
        }
        if (is_string($this->input('phone'))) {
            $data['phone'] = MemberContact::phone($this->input('phone'));
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return ['first_name' => [$presence, 'required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'last_name' => [$presence, 'required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:254', 'regex:/^[^\s@]+@[^\s@]+\.[^\s@]+$/u'],
            'preferred_locale' => ['sometimes', Rule::in(['mk', 'en'])],
            'digital_access_mode' => ['sometimes', Rule::enum(DigitalAccessMode::class)],
            'preferred_location_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
