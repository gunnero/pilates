<?php

use App\Http\Controllers\OrganizationContextController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('/me', ProfileController::class);
    Route::get('/organizations/{organization}/context', OrganizationContextController::class)
        ->whereUuid('organization')->middleware([ResolveOrganization::class, 'can:studio.access']);
});
