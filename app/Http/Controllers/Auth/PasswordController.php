<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordController
{
    private function normalize(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
    }

    public function forgot(Request $request): JsonResponse
    {
        $this->normalize($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        Password::sendResetLink($data);

        // Do not disclose whether an account exists or a broker throttle was hit.
        return response()->json(['message' => __('auth.reset_sent')]);
    }

    public function reset(Request $request): JsonResponse
    {
        $this->normalize($request);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'token' => ['required', 'string', 'max:256'],
            'password' => ['required', 'confirmed', 'max:1024', PasswordRule::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $status = Password::reset($data, function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $user->id)->delete();
                $user->tokens()->delete();
            });
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __('auth.reset_invalid')]);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => __('auth.reset_done')]);
    }
}
