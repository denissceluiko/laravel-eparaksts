<?php

namespace Dencel\LaravelEparaksts;


use Dencel\Eparaksts\Eparaksts;
use Dencel\Eparaksts\SignAPI\v1\SignAPI;
use Dencel\LaravelEparaksts\Middleware\HandlesSessionStorage;
use Dencel\LaravelEparaksts\Services\Eparaksts as EparakstsService;
use Dencel\LaravelEparaksts\Services\SessionStorage;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class EparakstsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('eparaksts-connector', function (): Eparaksts {
            return new Eparaksts(
                config('eparaksts.username'),
                config('eparaksts.password'),
                config('eparaksts.host')
            );
        });
        
        $this->app->singleton('eparaksts-signapi', function (): SignAPI {
            return new SignAPI(  
                config('eparaksts.username'),
                config('eparaksts.password'),
                config('eparaksts.signapi_host'),
                config('eparaksts.host'),
            );
        });
        
        $this->app->singleton('ep-session', function (): SessionStorage {
            return new SessionStorage(config('eparaksts.session_prefix'));
        });

        
        $this->app->bind('eparaksts', function (Application $app): EparakstsService {
            return new EparakstsService(
                $app->make('eparaksts-connector'),
                $app->make('ep-session'),
                $app->make('eparaksts-signapi'),
            );
        });
        
        $this->mergeConfigFrom(
            __DIR__.'/../config/eparaksts.php', 'eparaksts'
        );
    }

    public function boot(Router $router): void
    {
        $router->pushMiddlewareToGroup('web', HandlesSessionStorage::class);

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        
        $this->registerComponents();
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'eparaksts');
        
        $this->publishes([
            __DIR__.'/../config/eparaksts.php' => config_path('eparaksts.php'),
        ]);

        // TBI
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ]);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/eparaksts'),
        ]);

        $this->publishes([
            __DIR__.'/../resources/dist' => public_path('vendor/eparaksts'),
        ], 'public');
    }

    public function registerComponents(): void
    {
        Blade::componentNamespace('Dencel\\LaravelEparaksts\\View\\Components', 'eparaksts');
    }
}