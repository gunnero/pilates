<?php

use App\Http\Controllers\LocationController;
use App\Http\Controllers\OrganizationContextController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StudioMemberController;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('/me', ProfileController::class);
    Route::prefix('organizations/{organization}')->whereUuid('organization')->middleware([ResolveOrganization::class, 'can:studio.access'])->group(function (): void {
        Route::get('/context', OrganizationContextController::class);
        Route::post('/switch', [OrganizationController::class, 'switch']);
        Route::get('/', [OrganizationController::class, 'show'])->middleware('can:organizations.view');
        Route::patch('/', [OrganizationController::class, 'update'])->middleware('can:organizations.manage');
        Route::get('/locations', [LocationController::class, 'index'])->middleware('can:locations.view');
        Route::post('/locations', [LocationController::class, 'store'])->middleware('can:locations.manage');
        Route::get('/locations/{location}', [LocationController::class, 'show'])->whereUuid('location')->middleware('can:locations.view');
        Route::patch('/locations/{location}', [LocationController::class, 'update'])->whereUuid('location')->middleware('can:locations.manage');
        Route::get('/staff', [StaffController::class, 'index'])->middleware('can:staff.view');
        Route::get('/staff/{membership}', [StaffController::class, 'show'])->whereUuid('membership')->middleware('can:staff.view');
        Route::patch('/staff/{membership}', [StaffController::class, 'update'])->whereUuid('membership')->middleware('can:staff.manage');
        Route::prefix('members')->middleware('can:members.view')->group(function (): void {
            Route::get('/', [StudioMemberController::class, 'index']);
            Route::post('/search', [StudioMemberController::class, 'index']);
            Route::post('/', [StudioMemberController::class, 'store'])->middleware('can:members.create');
            Route::get('/{member}', [StudioMemberController::class, 'show'])->whereUuid('member');
            Route::patch('/{member}', [StudioMemberController::class, 'update'])->whereUuid('member')->middleware('can:members.update');
            Route::patch('/{member}/status', [StudioMemberController::class, 'status'])->whereUuid('member')->middleware('can:members.status.manage');
        });
        Route::get('/permissions', [StaffController::class, 'permissions'])->middleware('can:permissions.view');
    });
});
