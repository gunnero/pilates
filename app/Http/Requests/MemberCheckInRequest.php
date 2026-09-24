<?php

namespace App\Http\Requests;

class MemberCheckInRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['credential' => ['required', 'string', 'max:2048', 'regex:/\A[A-Za-z0-9+\/=]+\z/']];
    }
}
