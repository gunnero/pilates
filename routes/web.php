<?php

use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\MemberInvitationController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/activate', [MemberInvitationController::class, 'activate'])->middleware('throttle:activation');
    Route::post('/login', [SessionController::class, 'store'])->middleware(['guest', 'throttle:login']);
    Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth');
    Route::post('/forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:password');
    Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:password');
});
Route::view('/{path?}', 'app')->where('path', '(?!api(?:/|$)|auth(?:/|$)|sanctum(?:/|$)).*')->name('login');
