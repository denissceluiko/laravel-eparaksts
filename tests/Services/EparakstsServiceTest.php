<?php

namespace Dencel\LaravelEparaksts\Tests\Services;

use Dencel\Eparaksts\Eparaksts;
use Dencel\Eparaksts\SignAPI\v1\SignAPI;
use Dencel\LaravelEparaksts\Services\Eparaksts as EparakstsService;
use Dencel\LaravelEparaksts\Services\SessionStorage;
use Dencel\LaravelEparaksts\Tests\TestCase;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EparakstsService::class)]
class EparakstsServiceTest extends TestCase
{
    private function makeService(array $connectorResponses = [], array $signApiResponses = [], array &$history = []): EparakstsService
    {
        $connMock  = new MockHandler($connectorResponses);
        $connStack = HandlerStack::create($connMock);
        $connStack->push(Middleware::history($history));

        $apiMock  = new MockHandler($signApiResponses);
        $apiStack = HandlerStack::create($apiMock);
        $apiStack->push(Middleware::history($history));

        return new EparakstsService(
            new Eparaksts('client', 'secret', handlerStack: $connStack),
            new SessionStorage('eparaksts_'),
            new SignAPI('client', 'secret', handlerStack: $apiStack),
        );
    }

    // --- canSignAs ---

    public function testCanSignAsEdoc(): void
    {
        $s = $this->makeService();
        $this->assertTrue($s->canSignAs('edoc'));
    }

    public function testCanSignAsAsice(): void
    {
        $s = $this->makeService();
        $this->assertTrue($s->canSignAs('asice'));
    }

    public function testCanSignAsPdfWithNoPriorFiles(): void
    {
        $s = $this->makeService();
        $this->assertTrue($s->canSignAs('pdf'));
    }

    public function testCannotSignAsUnknownType(): void
    {
        $s = $this->makeService();
        $this->assertFalse($s->canSignAs('docx'));
    }

    // --- signAs ---

    public function testSignAsReturnsTrueOnSuccess(): void
    {
        $s = $this->makeService();
        $this->assertTrue($s->signAs('edoc'));
    }

    public function testSignAsReturnsFalseForInvalidType(): void
    {
        $s = $this->makeService();
        $this->assertFalse($s->signAs('docx'));
    }

    // --- getFiles / hasFiles ---

    public function testHasFilesReturnsFalseInitially(): void
    {
        $s = $this->makeService();
        $this->assertFalse($s->hasFiles());
        $this->assertEmpty($s->getFiles());
    }

    // --- hasDigestCalculated ---

    public function testHasDigestCalculatedFalseInitially(): void
    {
        $s = $this->makeService();
        $this->assertFalse($s->hasDigestCalculated());
    }

    // --- sessionOk ---

    public function testSessionOkFalseInitially(): void
    {
        $s = $this->makeService();
        $this->assertFalse($s->sessionOk());
    }

    // --- getSession / session ---

    public function testGetSessionNullInitially(): void
    {
        $s = $this->makeService();
        $this->assertNull($s->getSession());
    }

    // --- redirectAfter ---

    public function testRedirectAfterRoundTrip(): void
    {
        $s = $this->makeService();
        $s->redirectAfter('https://example.com/done');
        $this->assertSame('https://example.com/done', $s->getRedirectAfter());
        $s->resetRedirectAfter();
        $this->assertNull($s->getRedirectAfter());
    }

    // --- calculateDigest: no session ---

    public function testCalculateDigestFailsWithoutSession(): void
    {
        Log::spy();
        $s      = $this->makeService();
        $result = $s->calculateDigest();
        $this->assertFalse($result);
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    // --- finalizeSigning: no session ---

    public function testFinalizeSigningFailsWithoutSession(): void
    {
        $s      = $this->makeService();
        $result = $s->finalizeSigning();
        $this->assertFalse($result);
    }

    // --- signDigest: no digest ---

    public function testSignDigestFailsWithoutDigest(): void
    {
        $s = $this->makeService();
        $this->assertFalse($s->signDigest());
    }

    // --- getParameters ---

    public function testGetParametersStructure(): void
    {
        $s      = $this->makeService();
        $params = $s->getParameters();
        $this->assertArrayHasKey('session', $params);
        $this->assertArrayHasKey('containerType', $params);
        $this->assertArrayHasKey('newContainer', $params);
        $this->assertArrayHasKey('files', $params);
    }

    // --- disk / edoc / asice / pdf chainable methods ---

    public function testFluentMethodsReturnStatic(): void
    {
        $s = $this->makeService();
        $this->assertInstanceOf(EparakstsService::class, $s->disk('local'));
        $this->assertInstanceOf(EparakstsService::class, $s->edoc());
        $this->assertInstanceOf(EparakstsService::class, $s->asice());
        $this->assertInstanceOf(EparakstsService::class, $s->pdf());
        $this->assertInstanceOf(EparakstsService::class, $s->allowPdf(true));
        $this->assertInstanceOf(EparakstsService::class, $s->redirectAfter('/done'));
    }

    // --- establishSession: start failure (session start returns null) ---

    public function testEstablishSessionFailsWhenStartReturnsNull(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'tok',
            'expires_in'   => 3600,
            'scope'        => 'urn:safelayer:eidas:oauth:token:introspect',
        ]));

        $history = [];
        $s       = $this->makeService(
            connectorResponses: [],
            signApiResponses: [
                $tokenResponse,                                              // freshToken()
                new Response(200, [], json_encode(['status' => 'ok'])),     // configuration heartbeat
                new Response(400, [], ''),                                   // session start — non-201 → Session::start() returns null
            ],
            history: $history
        );

        $result = $s->session(null);
        $this->assertFalse($result->sessionOk());
    }

    private function tokenResponse(): Response
    {
        return new Response(200, [], json_encode([
            'access_token' => 'tok',
            'expires_in'   => 3600,
            'scope'        => 'urn:safelayer:eidas:oauth:token:introspect',
        ]));
    }

    // --- upload / addFile: missing file ---

    public function testUploadWithMissingFileLogsError(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'tok',
            'expires_in'   => 3600,
            'scope'        => 'urn:safelayer:eidas:oauth:token:introspect',
        ]));

        $history = [];
        $s       = $this->makeService(
            connectorResponses: [],
            signApiResponses: [
                $tokenResponse,                                                           // freshToken()
                new Response(200, [], json_encode([])),                                   // configuration heartbeat
                new Response(201, [], json_encode(['data' => ['sessionIds' => ['sess-1']]])), // session start
                new Response(200, [], json_encode(['data' => null])),                     // storage list (empty)
            ],
            history: $history
        );

        Log::spy();
        $s->upload('/tmp/this-file-does-not-exist-eparaksts-test.pdf');
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    // --- upload: happy path ---

    public function testUploadHappyPath(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ep_test_') . '.pdf';
        file_put_contents($tmp, '%PDF-1.4 test');

        $history = [];
        $s       = $this->makeService(
            signApiResponses: [
                $this->tokenResponse(),
                new Response(200, [], json_encode(['status' => 'ok'])),
                new Response(201, [], json_encode(['data' => ['sessionIds' => ['sess-1']]])),
                new Response(200, [], json_encode(['data' => null])),
                new Response(201, [], json_encode(['data' => ['id' => 'file-1', 'name' => basename($tmp)]])),
            ],
            history: $history
        );

        $s->upload($tmp);
        unlink($tmp);

        $this->assertTrue($s->hasFiles());
        $this->assertSame('file-1', $s->getFiles()[0]['id'] ?? null);
    }

    // --- finalizeSigning: success ---

    public function testFinalizeSigningSuccess(): void
    {
        $signIdentity = [
            'id'          => 'identity-1',
            'status'      => ['value' => 'enabled'],
            'labels'      => ['mobileid', 'x509:keyUsage:contentCommitment'],
            'description' => 'eparaksts:mobileid:sign',
            'details'     => ['certificate' => 'MOCK_CERT_PEM'],
        ];

        $s = $this->makeService(
            signApiResponses: [
                $this->tokenResponse(),
                new Response(200, [], json_encode(['status' => 'ok'])),
                new Response(201, [], json_encode(['data' => ['sessionIds' => ['sess-1']]])),
                new Response(200, [], json_encode(['data' => null])),
                new Response(200, [], json_encode([
                    'data' => ['results' => [['sessionId' => 'sess-1']]],
                ])),
            ]
        );

        $s->session(null);
        $s->sessionStorage()->me(['sign_identities' => [$signIdentity]]);

        $ref = new \ReflectionProperty($s, 'signature');
        $ref->setValue($s, base64_encode('mock-signature'));

        $this->assertTrue($s->finalizeSigning());
    }

    // --- finalizeSigning: no auth cert ---

    public function testFinalizeSigningFailsWhenAuthCertNotFound(): void
    {
        // Only a CERT_SIGNING identity, no CERT_MOBILEID_SIGN — findCert() returns null.
        $certSigningIdentity = [
            'id'      => 'identity-signing',
            'status'  => ['value' => 'enabled'],
            'labels'  => ['serverid'],
            'details' => ['certificate' => 'SIGNING_CERT_PEM'],
        ];

        $s = $this->makeService(
            signApiResponses: [
                $this->tokenResponse(),
                new Response(200, [], json_encode(['status' => 'ok'])),
                new Response(201, [], json_encode(['data' => ['sessionIds' => ['sess-1']]])),
                new Response(200, [], json_encode(['data' => null])),
            ]
        );

        $s->session(null);
        $s->sessionStorage()->me(['sign_identities' => [$certSigningIdentity]]);

        $ref = new \ReflectionProperty($s, 'signature');
        $ref->setValue($s, base64_encode('mock-signature'));

        Log::spy();
        $this->assertFalse($s->finalizeSigning());
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    // --- finalizeSigning: no signature ---

    public function testFinalizeSigningFailsWithoutSignature(): void
    {
        $history = [];
        $s       = $this->makeService(
            signApiResponses: [
                $this->tokenResponse(),
                new Response(200, [], json_encode(['status' => 'ok'])),
                new Response(201, [], json_encode(['data' => ['sessionIds' => ['sess-1']]])),
                new Response(200, [], json_encode(['data' => null])),
            ],
            history: $history
        );

        $s->session(null);
        $this->assertTrue($s->sessionOk());
        $this->assertFalse($s->finalizeSigning());
    }

    // --- download ---

    public function testDownloadNoFilesReturnsNull(): void
    {
        $s = $this->makeService();
        $this->assertNull($s->download('/tmp'));
    }

    public function testDownloadHappyPathAndAutoClose(): void
    {
        $tmpDir = sys_get_temp_dir();

        $s = $this->makeService(
            signApiResponses: [
                $this->tokenResponse(),
                new Response(200, [], json_encode(['status' => 'ok'])),
                new Response(200, [], json_encode(['data' => [['id' => 'file-1', 'name' => 'ep_test_dl.pdf']]])),
                new Response(200, [], 'SIGNED_PDF_CONTENT'),
                new Response(200, [], json_encode(['data' => null])),
            ]
        );

        $s->session('test-sess');
        $path = $s->download($tmpDir);

        $this->assertSame($tmpDir . '/ep_test_dl.pdf', $path);
        $this->assertFileExists($tmpDir . '/ep_test_dl.pdf');
        $this->assertFalse($s->sessionOk());
        $this->assertNull($s->getSession());

        @unlink($tmpDir . '/ep_test_dl.pdf');
    }

    public function testDownloadKeepPreservesSession(): void
    {
        $tmpDir = sys_get_temp_dir();

        $s = $this->makeService(
            signApiResponses: [
                $this->tokenResponse(),
                new Response(200, [], json_encode(['status' => 'ok'])),
                new Response(200, [], json_encode(['data' => [['id' => 'file-1', 'name' => 'ep_test_keep.pdf']]])),
                new Response(200, [], 'SIGNED_PDF_CONTENT'),
            ]
        );

        $s->session('test-sess');
        $path = $s->download($tmpDir, keep: true);

        $this->assertNotNull($path);
        $this->assertTrue($s->sessionOk());

        @unlink($tmpDir . '/ep_test_keep.pdf');
    }

    // --- close ---

    public function testCloseFlushesSession(): void
    {
        $s = $this->makeService(
            signApiResponses: [
                $this->tokenResponse(),
                new Response(200, [], json_encode(['status' => 'ok'])),
                new Response(200, [], json_encode(['data' => null])),
                new Response(200, [], json_encode(['data' => null])),
            ]
        );

        $s->session('test-sess');
        $this->assertTrue($s->sessionOk());

        $s->close();

        $this->assertFalse($s->sessionOk());
        $this->assertNull($s->getSession());
    }

    // --- getFileValidation ---

    public function testGetFileValidationNoFilesReturnsNull(): void
    {
        $s = $this->makeService();
        $this->assertNull($s->getFileValidation());
    }

    public function testGetFileValidationHappyPath(): void
    {
        $validationResult = ['status' => 'valid', 'signatures' => []];

        $s = $this->makeService(
            signApiResponses: [
                $this->tokenResponse(),
                new Response(200, [], json_encode(['status' => 'ok'])),
                new Response(200, [], json_encode(['data' => [['id' => 'file-1', 'name' => 'doc.pdf']]])),
                new Response(200, [], json_encode($validationResult)),
            ]
        );

        $s->session('test-sess');
        $result = $s->getFileValidation();

        $this->assertIsArray($result);
        $this->assertSame('valid', $result['status']);
    }

    // --- finalizeSigning: per-session error at 200 HTTP ---

    public function testFinalizeSigningFailsWithPerSessionError(): void
    {
        $signIdentity = [
            'id'          => 'identity-1',
            'status'      => ['value' => 'enabled'],
            'labels'      => ['mobileid', 'x509:keyUsage:contentCommitment'],
            'description' => 'eparaksts:mobileid:sign',
            'details'     => ['certificate' => 'MOCK_CERT_PEM'],
        ];

        $history = [];
        $s       = $this->makeService(
            signApiResponses: [
                $this->tokenResponse(),
                new Response(200, [], json_encode(['status' => 'ok'])),
                new Response(201, [], json_encode(['data' => ['sessionIds' => ['sess-1']]])),
                new Response(200, [], json_encode(['data' => null])),
                new Response(200, [], json_encode([
                    'data' => ['results' => [['sessionId' => 'sess-1', 'error' => 'signing_failed']]],
                ])),
            ],
            history: $history
        );

        $s->session(null);
        $s->sessionStorage()->me(['sign_identities' => [$signIdentity]]);

        $ref = new \ReflectionProperty($s, 'signature');
        $ref->setValue($s, base64_encode('mock-signature'));

        Log::spy();
        $this->assertFalse($s->finalizeSigning());
        Log::shouldHaveReceived('error')->atLeast()->once();
    }
}
