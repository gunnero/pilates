<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController
{
    public function store(LoginRequest $request): Response
    {
        if (! Auth::attempt($request->validated())) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        $request->session()->regenerate();

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
