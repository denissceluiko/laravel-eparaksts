<?php

namespace Dencel\LaravelEparaksts\Tests;

use Dencel\LaravelEparaksts\Facades\Eparaksts;
use Dencel\LaravelEparaksts\Facades\Eparaksts as EparakstsFacade;
use Dencel\LaravelEparaksts\Services\Eparaksts as EparakstsService;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EparakstsFacade::class)]
class FacadeTest extends TestCase
{
    public function testFacadeResolvesToService(): void
    {
        $this->assertInstanceOf(EparakstsService::class, Eparaksts::getFacadeRoot());
    }

    public function testFacadeProxiesGetSession(): void
    {
        $this->assertNull(Eparaksts::getSession());
    }

    public function testFacadeProxiesGetFiles(): void
    {
        $this->assertSame([], Eparaksts::getFiles());
    }
}
