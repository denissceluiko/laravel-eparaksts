<?php

namespace Dencel\LaravelEparaksts\Tests\Concerns;

use Dencel\Eparaksts\Eparaksts;
use Dencel\Eparaksts\SignAPI\v1\SignAPI;
use Dencel\LaravelEparaksts\Callbacks\Callback;
use Dencel\LaravelEparaksts\Callbacks\IdentificationCallback;
use Dencel\LaravelEparaksts\Concerns\HasCallbacks;
use Dencel\LaravelEparaksts\Services\Eparaksts as EparakstsService;
use Dencel\LaravelEparaksts\Services\SessionStorage;
use Dencel\LaravelEparaksts\Tests\TestCase;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Http\RedirectResponse;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(HasCallbacks::class)]
class HasCallbacksTest extends TestCase
{
    private function makeService(): EparakstsService
    {
        $mock  = new MockHandler([]);
        $stack = HandlerStack::create($mock);

        return new EparakstsService(
            new Eparaksts('client', 'secret', handlerStack: $stack),
            new SessionStorage('eparaksts_'),
            new SignAPI('client', 'secret', handlerStack: $stack),
        );
    }

    public function testBeforeRegistersCallback(): void
    {
        $service = $this->makeService();
        $service->beforeFinalRedirect(CallbackStub::class);
        $callbacks = $service->getCallbacks();
        $this->assertArrayHasKey('beforeFinalRedirect', $callbacks);
        $this->assertContains(CallbackStub::class, $callbacks['beforeFinalRedirect']);
    }

    public function testAfterRegistersCallback(): void
    {
        $service = $this->makeService();
        $service->afterSigningFinalized(CallbackStub::class);
        $callbacks = $service->getCallbacks();
        $this->assertArrayHasKey('afterSigningFinalized', $callbacks);
    }

    public function testCallDispatchesCallback(): void
    {
        CallbackStub::$handled = false;
        $service               = $this->makeService();
        $service->afterSigningFinalized(CallbackStub::class);
        $service->callAfterSigningFinalized();
        $this->assertTrue(CallbackStub::$handled);
    }

    public function testDuplicateCallbacksAreDeduped(): void
    {
        $service = $this->makeService();
        $service->beforeFinalRedirect(CallbackStub::class);
        $service->beforeFinalRedirect(CallbackStub::class);
        $this->assertCount(1, $service->getCallbacks()['beforeFinalRedirect']);
    }

    public function testClearCallbacksEmptiesAll(): void
    {
        $service = $this->makeService();
        $service->afterSigningFinalized(CallbackStub::class);
        $service->clearCallbacks();
        $this->assertEmpty($service->getCallbacks());
    }

    public function testInvalidCallbackClassIsSkipped(): void
    {
        $service = $this->makeService();
        // Class does not extend Callback — should be silently filtered out
        $service->beforeFinalRedirect(\stdClass::class);
        $callbacks = $service->getCallbacks();
        // After filtering, key should be absent or array empty
        $this->assertEmpty($callbacks['beforeFinalRedirect'] ?? []);
    }

    // --- on* registration ---

    public function testOnPrefixRegistersCallback(): void
    {
        $service = $this->makeService();
        $service->onError(CallbackStub::class);
        $this->assertContains(CallbackStub::class, $service->getCallbacks()['onError'] ?? []);
    }

    // --- callOnIdentificationReceived ---

    public function testCallOnIdentificationReceivedReturnsNullWithNoCallbacks(): void
    {
        $service = $this->makeService();
        $this->assertNull($service->callOnIdentificationReceived(['name' => 'Test']));
    }

    public function testCallOnIdentificationReceivedDispatchesAndSetsIdentity(): void
    {
        IdentificationCallbackStub::$lastIdentity = [];
        $service                                  = $this->makeService();
        $service->onIdentificationReceived(IdentificationCallbackStub::class);
        $response = $service->callOnIdentificationReceived(['name' => 'Jānis']);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('Jānis', IdentificationCallbackStub::$lastIdentity['name']);
    }

    public function testCallOnIdentificationReceivedShortCircuitsOnFirstNonNull(): void
    {
        $service = $this->makeService();
        $service->onIdentificationReceived(IdentificationCallbackStub::class);
        $service->onIdentificationReceived(NullIdentificationCallbackStub::class);
        // Should return first non-null without calling the second
        $response = $service->callOnIdentificationReceived([]);
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testCallOnIdentificationReceivedReturnsNullWhenAllReturnNull(): void
    {
        $service = $this->makeService();
        $service->onIdentificationReceived(NullIdentificationCallbackStub::class);
        $this->assertNull($service->callOnIdentificationReceived([]));
    }

    public function testCallOnIdentificationReceivedSkipsVoidCallbacks(): void
    {
        $service = $this->makeService();
        // CallbackStub extends Callback (void), not IdentificationCallback
        $service->onIdentificationReceived(CallbackStub::class);
        $this->assertNull($service->callOnIdentificationReceived([]));
    }

    // --- restoreCallbacks ---

    public function testRestoreCallbacksLoadsFromSessionStorage(): void
    {
        $storage = new SessionStorage('eparaksts_');
        $storage->callbacks(['afterSigningFinalized' => [CallbackStub::class]]);

        $stack   = HandlerStack::create(new MockHandler([]));
        $service = new EparakstsService(
            new \Dencel\Eparaksts\Eparaksts('client', 'secret', handlerStack: $stack),
            $storage,
            new \Dencel\Eparaksts\SignAPI\v1\SignAPI('client', 'secret', handlerStack: $stack),
        );

        $service->clearCallbacks();
        $this->assertEmpty($service->getCallbacks());

        $service->restoreCallbacks();
        $this->assertContains(CallbackStub::class, $service->getCallbacks()['afterSigningFinalized'] ?? []);
    }
}

class CallbackStub extends Callback
{
    public static bool $handled = false;

    public function handle(): void
    {
        static::$handled = true;
    }
}

class IdentificationCallbackStub extends IdentificationCallback
{
    public static array $lastIdentity = [];

    public function handle(): ?RedirectResponse
    {
        static::$lastIdentity = $this->identity;
        return redirect('/signed-in');
    }
}

class NullIdentificationCallbackStub extends IdentificationCallback
{
    public function handle(): ?RedirectResponse
    {
        return null;
    }
}
