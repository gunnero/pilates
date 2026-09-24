<?php

namespace App\Http\Requests;

class MemberActivationRequest extends LifecycleRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email:rfc', 'max:254'], 'token' => ['required', 'string', 'size:64', 'alpha_num:ascii'], 'password' => ['required', 'string', 'max:1024'], 'password_confirmation' => ['required', 'string', 'same:password', 'max:1024']];
    }
}
