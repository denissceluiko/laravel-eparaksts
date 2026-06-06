<?php

namespace Dencel\LaravelEparaksts\Tests;

use Dencel\Eparaksts\Eparaksts;
use Dencel\Eparaksts\SignAPI\v1\SignAPI;
use Dencel\LaravelEparaksts\EparakstsServiceProvider;
use Dencel\LaravelEparaksts\Services\Eparaksts as EparakstsService;
use Dencel\LaravelEparaksts\Services\SessionStorage;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EparakstsServiceProvider::class)]
class EparakstsServiceProviderTest extends TestCase
{
    public function testConnectorBindingResolves(): void
    {
        $this->assertInstanceOf(Eparaksts::class, $this->app->make('eparaksts-connector'));
    }

    public function testSignApiBindingResolves(): void
    {
        $this->assertInstanceOf(SignAPI::class, $this->app->make('eparaksts-signapi'));
    }

    public function testSessionStorageBindingResolves(): void
    {
        $this->assertInstanceOf(SessionStorage::class, $this->app->make('ep-session'));
    }

    public function testEparakstsServiceBindingResolves(): void
    {
        $this->assertInstanceOf(EparakstsService::class, $this->app->make('eparaksts'));
    }

    public function testConnectorIsSingleton(): void
    {
        $a = $this->app->make('eparaksts-connector');
        $b = $this->app->make('eparaksts-connector');
        $this->assertSame($a, $b);
    }

    public function testEparakstsServiceIsNotSingleton(): void
    {
        $a = $this->app->make('eparaksts');
        $b = $this->app->make('eparaksts');
        $this->assertNotSame($a, $b);
    }

    public function testConfigIsLoaded(): void
    {
        $this->assertSame('test-client', config('eparaksts.username'));
        $this->assertSame('test-secret', config('eparaksts.password'));
        $this->assertSame('eparaksts_', config('eparaksts.session_prefix'));
    }

    public function testRoutesAreRegistered(): void
    {
        $this->assertNotNull(route('eparaksts.redirect'));
        $this->assertNotNull(route('eparaksts.identification'));
        $this->assertNotNull(route('eparaksts.logout'));
        $this->assertNotNull(route('eparaksts.identities'));
        $this->assertNotNull(route('eparaksts.sign', ['session' => 'abc']));
    }
}
