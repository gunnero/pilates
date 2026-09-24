<?php

namespace App\Http\Requests;

class MemberTokenRequest extends LifecycleRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:254'], 'password' => ['required', 'string', 'max:1024'], 'device_name' => ['required', 'string', 'max:80', 'not_regex:/[\x00-\x1F\x7F]/u']];
    }
}
