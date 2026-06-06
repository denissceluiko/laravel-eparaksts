<?php

namespace Dencel\LaravelEparaksts;

use Dencel\Eparaksts\Eparaksts;
use Dencel\Eparaksts\SignAPI\v1\SignAPI;
use Dencel\LaravelEparaksts\Console\Commands\InstallCommand;
use Dencel\LaravelEparaksts\Middleware\HandlesSessionStorage;
use Dencel\LaravelEparaksts\Services\Eparaksts as EparakstsService;
use Dencel\LaravelEparaksts\Services\SessionStorage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class EparakstsServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton('eparaksts-connector', fn(): Eparaksts => new Eparaksts(
            config('eparaksts.username'),
            config('eparaksts.password'),
            config('eparaksts.host')
        ));

        $this->app->singleton('eparaksts-signapi', fn(): SignAPI => new SignAPI(
            config('eparaksts.username'),
            config('eparaksts.password'),
            config('eparaksts.signapi_host'),
            config('eparaksts.host'),
        ));

        $this->app->singleton('ep-session', fn(): SessionStorage => new SessionStorage(config('eparaksts.session_prefix')));

        $this->app->bind('eparaksts', fn(Application $app): EparakstsService => new EparakstsService(
            $app->make('eparaksts-connector'),
            $app->make('ep-session'),
            $app->make('eparaksts-signapi'),
        ));

        $this->mergeConfigFrom(
            __DIR__ . '/../config/eparaksts.php',
            'eparaksts'
        );
    }

    public function boot(): void
    {
        // callAfterResolving ensures we add to the Kernel's middleware groups rather
        // than the Router's, so the entry survives the syncMiddlewareToRouter() call
        // that Kernel::__construct() makes before service providers are booted.
        $this->callAfterResolving(
            Kernel::class,
            fn($kernel) => $kernel->appendMiddlewareToGroup('web', HandlesSessionStorage::class)
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        $this->registerComponents();
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'eparaksts');

        $this->publishes([
            __DIR__ . '/../config/eparaksts.php' => config_path('eparaksts.php'),
        ], 'eparaksts-config');

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'eparaksts-migrations');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/eparaksts'),
        ]);

        $this->publishes([
            __DIR__ . '/../resources/dist' => public_path('vendor/eparaksts'),
        ], 'public');
    }

    public function registerComponents(): void
    {
        Blade::componentNamespace('Dencel\\LaravelEparaksts\\View\\Components', 'eparaksts');
    }
}
