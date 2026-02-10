<?php

namespace Dencel\LaravelEparaksts;


use Dencel\Eparaksts\Eparaksts;
use Illuminate\Support\ServiceProvider;

class EparakstsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('eparaksts', function () {
            return new Eparaksts(
                config('eparaksts.username'),
                config('eparaksts.password'),
                config('eparaksts.host')
            );

        });
        
        $this->mergeConfigFrom(
            __DIR__.'/../config/eparaksts.php', 'eparaksts'
        );


    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        
        $this->publishes([
            __DIR__.'/../config/eparaksts.php' => config_path('eparaksts.php'),
        ]);

        // TBI
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ]);
    }
}