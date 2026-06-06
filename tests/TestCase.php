<?php

namespace Dencel\LaravelEparaksts\Tests;

use Dencel\LaravelEparaksts\EparakstsServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            EparakstsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('eparaksts.username', 'test-client');
        $app['config']->set('eparaksts.password', 'test-secret');
        $app['config']->set('eparaksts.host', 'https://eidas-demo.eparaksts.lv');
        $app['config']->set('eparaksts.signapi_host', 'https://signapi-prep.eparaksts.lv');
        $app['config']->set('eparaksts.session_prefix', 'eparaksts_');
        $app['config']->set('eparaksts.route_prefix', 'ep');
        $app['config']->set('eparaksts.redirect', '/eparaksts/callback');
        $app['config']->set('session.driver', 'array');
    }
}
