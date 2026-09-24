<?php

namespace App\Http\Requests;

class MemberInvitationRequest extends LifecycleRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email:rfc', 'max:254', 'regex:/^[^\s@]+@[^\s@]+\.[^\s@]+$/u']];
    }
}
