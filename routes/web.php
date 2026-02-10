<?php

use Dencel\LaravelEparaksts\Controllers\EparakstsController;
use Dencel\LaravelEparaksts\Middleware\HandlesSessionStorage;
use Illuminate\Support\Facades\Route;

Route::name('eparaksts.')
    ->middleware(['web'])
    ->group(function() {
        Route::get(config('eparaksts.redirect'), [EparakstsController::class, 'redirect'])
            ->name('redirect');
        Route::prefix(config('eparaksts.route_prefix'))
            ->group(function(): void {
                Route::get( '/auth/{flow?}', [EparakstsController::class, 'identificationFlow'])
                    ->name('identification');
                Route::get( '/logout', [EparakstsController::class, 'logoutFlow'])
                    ->name('logout');
                Route::get( '/identities', [EparakstsController::class, 'identitiesFlow'])
                    ->name('identities');
            });
    });