<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;

class AccountPasswordRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'max:1024'], 'password' => ['required', 'string', 'confirmed', 'max:1024', Password::min(12)->mixedCase()->numbers()->symbols()], 'password_confirmation' => ['required', 'string', 'max:1024']];
    }
}
