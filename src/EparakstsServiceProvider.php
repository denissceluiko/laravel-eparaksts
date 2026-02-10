<?php

namespace Dencel\LaravelEparaksts;


use Dencel\Eparaksts\Eparaksts;
use Dencel\LaravelEparaksts\Middleware\HandlesSessionStorage;
use Dencel\LaravelEparaksts\Services\SessionStorage;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class EparakstsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('eparaksts', function (): Eparaksts {
            return new Eparaksts(
                config('eparaksts.username'),
                config('eparaksts.password'),
                config('eparaksts.host')
            );
        });
        
        $this->app->singleton('ep-session', function (): SessionStorage {
            return new SessionStorage(config('eparaksts.session_prefix'));
        });
        
        $this->mergeConfigFrom(
            __DIR__.'/../config/eparaksts.php', 'eparaksts'
        );
    }

    public function boot(Router $router): void
    {
        $router->pushMiddlewareToGroup('web', HandlesSessionStorage::class);

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