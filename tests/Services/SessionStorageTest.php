<?php

namespace Dencel\LaravelEparaksts\Tests\Services;

use Dencel\Eparaksts\Eparaksts;
use Dencel\LaravelEparaksts\Services\SessionStorage;
use Dencel\LaravelEparaksts\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SessionStorage::class)]
class SessionStorageTest extends TestCase
{
    private function make(): SessionStorage
    {
        return new SessionStorage('eparaksts_');
    }

    public function testActionRoundTrip(): void
    {
        $s = $this->make();
        $s->action('my-action');
        $this->assertSame('my-action', $s->action());
    }

    public function testStateGeneratesNewValue(): void
    {
        $s  = $this->make();
        $s1 = $s->state(true);
        $s2 = $s->state(true);
        $this->assertNotEmpty($s1);
        $this->assertNotSame($s1, $s2);
    }

    public function testStateReturnsSameWithoutNew(): void
    {
        $s = $this->make();
        $s->state(true);
        $stored = $s->state();
        $this->assertSame($stored, $s->state());
    }

    public function testMeMergesIdentity(): void
    {
        $s = $this->make();
        $s->me(['name' => 'Jānis Bērziņš']);
        $s->me(['sign_identities' => []]);
        $this->assertSame('Jānis Bērziņš', $s->me()['name']);
        $this->assertSame([], $s->me()['sign_identities']);
    }

    public function testSignIdentitiesReturnsNullWhenEmpty(): void
    {
        $s = $this->make();
        $this->assertNull($s->signIdentities());
    }

    public function testSignIdentitiesReturnsArray(): void
    {
        $s = $this->make();
        $s->me(['sign_identities' => [['id' => 'id1', 'name' => 'test']]]);
        $this->assertCount(1, $s->signIdentities());
    }

    public function testSaveAndGetTokens(): void
    {
        $s = $this->make();
        $s->saveTokens([
            Eparaksts::SCOPE_IDENTIFICATION => ['bearer' => 'tok', 'expires' => time() + 3600],
        ]);
        $tokens = $s->getTokens();
        $this->assertSame('tok', $tokens[Eparaksts::SCOPE_IDENTIFICATION]['bearer']);
    }

    public function testSaveAndGetDigest(): void
    {
        $s = $this->make();
        $this->assertNull($s->getDigest());

        $s->saveDigest(['digest' => 'abc', 'signature_algorithm' => 'rsa-sha256']);
        $this->assertSame('abc', $s->getDigest()['digest']);

        // flushDigest sets storage to []; getDigest() treats empty as null
        $s->flushDigest();
        $this->assertNull($s->getDigest());
    }

    public function testRedirectAfterRoundTrip(): void
    {
        $s = $this->make();
        $this->assertNull($s->redirectAfter());
        $s->redirectAfter('https://example.com/done');
        $this->assertSame('https://example.com/done', $s->redirectAfter());
        $s->resetRedirectAfter();
        $this->assertNull($s->redirectAfter());
    }

    public function testCallbacksRoundTrip(): void
    {
        $s = $this->make();
        $this->assertSame([], $s->callbacks());
        $s->callbacks(['afterSigning' => ['SomeClass']]);
        $this->assertSame(['SomeClass'], $s->callbacks()['afterSigning']);
    }

    public function testSaveAndInitRoundTrip(): void
    {
        $this->startSession();
        $s = new SessionStorage('eparaksts_');
        $s->action('test-action');
        $s->save();

        $s2 = new SessionStorage('eparaksts_');
        $s2->init(session()->driver());
        $this->assertSame('test-action', $s2->action());
    }

    public function testFlushClearsSessionKey(): void
    {
        $this->startSession();
        $s = new SessionStorage('eparaksts_');
        $s->action('test');
        $s->save();
        $s->flush();

        $s2 = new SessionStorage('eparaksts_');
        $s2->init(session()->driver());
        $this->assertSame('', $s2->action());
    }

    public function testFlushSessionDataClearsSigningState(): void
    {
        $s = $this->make();
        $s->saveDigest(['digest' => 'abc']);
        $s->redirectAfter('https://example.com');
        $s->callbacks(['hook' => ['Class']]);
        $s->saveTokens([
            Eparaksts::SCOPE_SIGNATURE => ['bearer' => 'tok', 'expires' => time() + 3600],
        ]);

        $s->flushSessionData();

        $this->assertEmpty($s->getDigest());
        $this->assertNull($s->redirectAfter());
        $this->assertSame([], $s->callbacks());
        // SCOPE_SIGNATURE token should be flushed
        $tokens = $s->getTokens();
        $this->assertNull($tokens[Eparaksts::SCOPE_SIGNATURE]['bearer'] ?? null);
    }
}
