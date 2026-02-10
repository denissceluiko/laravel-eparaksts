<?php

use Dencel\LaravelEparaksts\Controllers\EparakstsController;
use Illuminate\Support\Facades\Route;

Route::name('eparaksts.')
    ->group(function() {
        Route::get(config('eparaksts.redirect'), [EparakstsController::class, 'redirect'])
            ->name('redirect');
        Route::get(config('eparaksts.route_prefix') . '/auth', [EparakstsController::class, 'identificationFlow'])
            ->name('identification');
    });