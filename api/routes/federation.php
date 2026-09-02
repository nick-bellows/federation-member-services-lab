<?php

use App\Federation\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Federation routes (fork)
|--------------------------------------------------------------------------
|
| Loaded by App\Federation\FederationServiceProvider under the "api" group
| and the /api prefix, like routes/api.php. Everything here authenticates
| with the OIDC bearer guard; upstream's Sanctum routes are untouched.
|
*/

Route::middleware('auth:oidc')
    ->prefix('v1/federation')
    ->name('federation.')
    ->group(function () {
        Route::get('me', [MeController::class, 'show'])->name('me');
    });
