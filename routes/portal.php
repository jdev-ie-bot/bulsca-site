<?php

use App\Http\Controllers\Api\V1\PortalController;
use Illuminate\Support\Facades\Route;

Route::get('csrf', fn () => response()->noContent())->name('csrf');
// A session can always be closed, including while the provider is disabled.
Route::post('logout', [\App\Http\Controllers\Auth\SSOAuthController::class, 'logout'])->middleware(['auth', 'throttle:120,1']);
Route::middleware(['auth', \App\Http\Middleware\RequireAuthentikSession::class, 'throttle:120,1'])->group(function () {
    Route::get('me', [PortalController::class, 'me'])->name('me');
    Route::get('organisations', [PortalController::class, 'index']);
    Route::post('organisations', [PortalController::class, 'store']);
    Route::get('organisations/{organisation}', [PortalController::class, 'show']);
    Route::post('organisations/{organisation}/members', [PortalController::class, 'member']);
    Route::patch('organisations/{organisation}/members/{membership}', [PortalController::class, 'membership']);
    Route::post('organisations/{organisation}/committees', [PortalController::class, 'committee']);
    Route::patch('organisations/{organisation}/committees/{committee}', [PortalController::class, 'committeeUpdate']);
    Route::post('organisations/{organisation}/roles', [PortalController::class, 'role']);
    Route::patch('organisations/{organisation}/roles/{role}', [PortalController::class, 'role']);
    Route::delete('organisations/{organisation}/roles/{role}', [PortalController::class, 'removeRole']);
    Route::post('organisations/{organisation}/appointments', [PortalController::class, 'appointment']);
    Route::delete('organisations/{organisation}/appointments/{appointment}', [PortalController::class, 'removeAppointment']);
    Route::post('organisations/{organisation}/branding', [PortalController::class, 'branding']);
});
