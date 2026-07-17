<?php

use App\Http\Controllers\Api\ProvisioningController;
use App\Http\Middleware\ProvisioningGuard;
use Illuminate\Support\Facades\Route;

// Provisioning de comptes — 404 tant que PROVISION_TOKEN n'est pas configuré.
Route::middleware([ProvisioningGuard::class, 'throttle:30,1'])
    ->prefix('admin/users')
    ->group(function () {
        Route::post('/', [ProvisioningController::class, 'store']);
        Route::post('/suspend', [ProvisioningController::class, 'suspend']);
        Route::post('/unsuspend', [ProvisioningController::class, 'unsuspend']);
    });
