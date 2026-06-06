<?php

namespace Dencel\LaravelEparaksts\Tests\Controllers;

use Dencel\Eparaksts\Eparaksts;
use Dencel\Eparaksts\SignAPI\v1\SignAPI;
use Dencel\LaravelEparaksts\Callbacks\IdentificationCallback;
use Dencel\LaravelEparaksts\Controllers\EparakstsController;
use Dencel\LaravelEparaksts\Services\Eparaksts as EparakstsService;
use Dencel\LaravelEparaksts\Tests\TestCase;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\RedirectResponse;
use PHPUnit\Framework\Attributes\CoversClass;

class TestIdentificationCallbackRedirect extends IdentificationCallback
{
    public function handle(): ?RedirectResponse
    {
        return redirect('/custom-ident-redirect');
    }
}

// Sign identities reused across finalizeSigning tests.
const CERT_SIGNING_IDENTITY = [
    'id'      => 'identity-serverid',
    'status'  => ['value' => 'enabled'],
    'labels'  => ['serverid'],
    'details' => ['certificate' => 'SERVER_CERT_PEM'],
];
const CERT_MOBILEID_SIGN_IDENTITY = [
    'id'          => 'identity-mobileid',
    'status'      => ['value' => 'enabled'],
    'labels'      => ['mobileid', 'x509:keyUsage:contentCommitment'],
    'description' => 'eparaksts:mobileid:sign',
    'details'     => ['certificate' => 'MOBILEID_CERT_PEM'],
];

#[CoversClass(EparakstsController::class)]
class EparakstsControllerTest extends TestCase
{
    // --- helpers ---

    private function blob(array $data): string
    {
        return base64_encode(json_encode($data));
    }

    private function epSession(array $storage = [], array $extra = []): array
    {
        return array_merge(
            ['eparaksts__ep_storage' => $this->blob($storage)],
            $extra
        );
    }

    private function bindSignApiMock(array $responses): void
    {
        $stack   = HandlerStack::create(new MockHandler($responses));
        $signApi = new SignAPI('client', 'secret', handlerStack: $stack);

        $this->app->bind('eparaksts', fn() => new EparakstsService(
            app('eparaksts-connector'),
            app('ep-session'),
            $signApi,
        ));
    }

    private function bindConnectorMock(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $this->app->instance('eparaksts-connector', new Eparaksts('client', 'secret', handlerStack: $stack));
    }

    private function signApiToken(): Response
    {
        return new Response(200, [], json_encode([
            'access_token' => 'signapi-token',
            'expires_in'   => 3600,
            'scope'        => Eparaksts::SCOPE_SIGNAPI,
        ]));
    }

    // --- redirect(): state mismatch ---

    public function testStateMismatchFlashesErrorAndRedirects(): void
    {
        $this->withSession($this->epSession(['state' => 'correct', 'action' => '']))
            ->get('/eparaksts/callback?state=wrong')
            ->assertRedirect('/')
            ->assertSessionHas('ep_error', 'state_mismatch');
    }

    // --- redirect(): logout ---

    public function testLogoutCallbackRedirects(): void
    {
        $this->withSession($this->epSession(['action' => 'logout']))
            ->get('/eparaksts/callback')
            ->assertRedirect('/');
    }

    // --- callbackError() ---

    public function testOAuthErrorFlashesEpError(): void
    {
        $state = 'err-state-1';
        $this->withSession($this->epSession(['state' => $state, 'action' => '']))
            ->get('/eparaksts/callback?state=' . $state . '&error=access_denied')
            ->assertRedirect('/')
            ->assertSessionHas('ep_error', 'access_denied');
    }

    public function testOAuthErrorWithDescriptionFlashesBoth(): void
    {
        $state = 'err-state-2';
        $this->withSession($this->epSession(['state' => $state, 'action' => '']))
            ->get('/eparaksts/callback?state=' . $state . '&error=access_denied&error_description=User+cancelled')
            ->assertSessionHas('ep_error', 'access_denied')
            ->assertSessionHas('ep_error_description', 'User cancelled');
    }

    public function testSigningCancellationRedirectsToRedirectAfter(): void
    {
        $state         = 'sig-cancel-state';
        $redirectAfter = 'https://example.com/doc/1';

        $this->withSession($this->epSession(
            ['state' => $state, 'redirectAfter' => $redirectAfter],
            ['eparaksts__signing_' . $state => 'sess-id-123']
        ))
            ->get('/eparaksts/callback?state=' . $state . '&error=access_denied')
            ->assertRedirect($redirectAfter)
            ->assertSessionHas('ep_error', 'access_denied');
    }

    public function testSigningCancellationRemovesSigningSessionKey(): void
    {
        $state = 'sig-cancel-cleanup';

        $this->withSession($this->epSession(
            ['state' => $state, 'redirectAfter' => 'https://example.com/done'],
            ['eparaksts__signing_' . $state => 'sess-id-456']
        ))
            ->get('/eparaksts/callback?state=' . $state . '&error=access_denied');

        $this->assertFalse(session()->has('eparaksts__signing_' . $state));
    }

    // --- identificationFlow() ---

    public function testIdentificationFlowRedirectsToEidasHost(): void
    {
        $response = $this->get('/ep/auth');
        $response->assertRedirect();
        $this->assertStringContainsString('eidas-demo.eparaksts.lv', $response->headers->get('Location'));
    }

    public function testIdentificationFlowWritesStateToSession(): void
    {
        $this->get('/ep/auth');
        $storage = json_decode(base64_decode((string) session()->get('eparaksts__ep_storage')), true);
        $this->assertNotEmpty($storage['state'] ?? '');
    }

    // --- logoutFlow() ---

    public function testLogoutFlowRedirectsToEidasHost(): void
    {
        $response = $this->get('/ep/logout');
        $response->assertRedirect();
        $this->assertStringContainsString('eidas-demo.eparaksts.lv', $response->headers->get('Location'));
    }

    public function testLogoutFlowWritesLogoutActionToSession(): void
    {
        $this->get('/ep/logout');
        $storage = json_decode(base64_decode((string) session()->get('eparaksts__ep_storage')), true);
        $this->assertSame('logout', $storage['action'] ?? '');
    }

    // --- signFlow() ---

    public function testSignFlowRedirectsToIdentificationWhenNotIdentified(): void
    {
        $this->bindSignApiMock([
            $this->signApiToken(),
            new Response(200, [], json_encode(['status' => 'ok'])),
            new Response(200, [], json_encode(['data' => null])),
        ]);

        $this->withSession($this->epSession(['tokens' => [], 'callbacks' => []]))
            ->get('/ep/sign/test-sess')
            ->assertRedirect(route('eparaksts.identification'));
    }

    public function testSignFlowRedirectsToIdentitiesWhenIdentifiedButNoSigningIdentity(): void
    {
        $this->bindSignApiMock([
            $this->signApiToken(),
            new Response(200, [], json_encode(['status' => 'ok'])),
            new Response(200, [], json_encode(['data' => [['id' => 'f1', 'name' => 'doc.pdf']]])),
        ]);

        $this->withSession($this->epSession([
            'tokens'    => [Eparaksts::SCOPE_IDENTIFICATION => ['bearer' => 'tok', 'expires' => 9999999999]],
            'me'        => ['sign_identities' => []],
            'callbacks' => [],
        ]))
            ->get('/ep/sign/test-sess')
            ->assertRedirect(route('eparaksts.identities'));
    }

    // --- finalizeSigning() controller (reached via SCOPE_SIGNATURE OAuth callback) ---

    private function connectorToken(): Response
    {
        return new Response(200, [], json_encode([
            'access_token' => 'sig-access-token',
            'expires_in'   => 3600,
            'scope'        => Eparaksts::SCOPE_SIGNATURE,
        ]));
    }

    public function testFinalizeSigningControllerRedirectsToRedirectAfter(): void
    {
        $state      = 'fin-happy-state';
        $digestData = [
            'digest'              => base64_encode('some-digest'),
            'digests_summary'     => base64_encode('summary'),
            'algorithm'           => 'SHA-256',
            'signature_algorithm' => 'rsa-sha256',
        ];

        // redirect() exchanges the auth code (requestToken), then signDigest() calls sign().
        $this->bindConnectorMock([
            $this->connectorToken(),
            new Response(200, [], 'raw-signature-bytes'),
        ]);

        $this->bindSignApiMock([
            $this->signApiToken(),
            new Response(200, [], json_encode(['status' => 'ok'])),
            new Response(200, [], json_encode(['data' => [['id' => 'f1', 'name' => 'doc.pdf']]])),
            new Response(200, [], json_encode([
                'data' => ['results' => [['sessionId' => 'fin-sess']]],
            ])),
        ]);

        $this->withSession($this->epSession(
            [
                'state'         => $state,
                'action'        => Eparaksts::SCOPE_SIGNATURE,
                'tokens'        => [],
                'me'            => ['sign_identities' => [CERT_SIGNING_IDENTITY, CERT_MOBILEID_SIGN_IDENTITY]],
                'digests'       => $digestData,
                'redirectAfter' => 'https://example.com/signed',
                'callbacks'     => [],
            ],
            ['eparaksts__signing_' . $state => 'fin-sess']
        ))
            ->get('/eparaksts/callback?code=auth-code&state=' . $state)
            ->assertRedirect('https://example.com/signed');
    }

    public function testFinalizeSigningControllerFlashesErrorWhenSignDigestFails(): void
    {
        // No digest data in session → signDigest() returns false immediately.
        $state = 'fin-nodigest-state';

        $this->bindConnectorMock([$this->connectorToken()]);

        $this->bindSignApiMock([
            $this->signApiToken(),
            new Response(200, [], json_encode(['status' => 'ok'])),
            new Response(200, [], json_encode(['data' => null])),
        ]);

        $this->withSession($this->epSession(
            [
                'state'     => $state,
                'action'    => Eparaksts::SCOPE_SIGNATURE,
                'tokens'    => [],
                'me'        => ['sign_identities' => [CERT_SIGNING_IDENTITY, CERT_MOBILEID_SIGN_IDENTITY]],
                'callbacks' => [],
                // no 'digests' key
            ],
            ['eparaksts__signing_' . $state => 'fin-sess']
        ))
            ->get('/eparaksts/callback?code=auth-code&state=' . $state)
            ->assertRedirect()
            ->assertSessionHas('error', 'Could not sign digest');
    }

    // --- callbackIdentification(): onIdentificationReceived returns non-null ---

    public function testCallbackIdentificationOnIdentificationReceivedNonNull(): void
    {
        $state = 'ident-custom-state';

        $this->bindConnectorMock([
            new Response(200, [], json_encode([
                'access_token' => 'ident-tok',
                'expires_in'   => 3600,
                'scope'        => Eparaksts::SCOPE_IDENTIFICATION,
            ])),
            new Response(200, [], json_encode([
                'name'          => 'JĀNIS',
                'given_name'    => 'JĀNIS',
                'family_name'   => 'BĒRZIŅŠ',
                'serial_number' => 'PNOLV-123456-12345',
            ])),
        ]);

        $this->withSession($this->epSession([
            'state'     => $state,
            'action'    => Eparaksts::SCOPE_IDENTIFICATION,
            'tokens'    => [],
            'callbacks' => ['onIdentificationReceived' => [TestIdentificationCallbackRedirect::class]],
        ]))
            ->get('/eparaksts/callback?code=auth-code&state=' . $state)
            ->assertRedirect('/custom-ident-redirect');
    }

    public function testFinalizeSigningControllerFlashesErrorWhenFinalizeSigningFails(): void
    {
        // Only CERT_SIGNING identity (no CERT_MOBILEID_SIGN) → finalizeSigning() can't find
        // the auth cert and returns false.
        $state      = 'fin-noauthcert-state';
        $digestData = [
            'digest'              => base64_encode('some-digest'),
            'digests_summary'     => base64_encode('summary'),
            'algorithm'           => 'SHA-256',
            'signature_algorithm' => 'rsa-sha256',
        ];

        $this->bindConnectorMock([
            $this->connectorToken(),
            new Response(200, [], 'raw-signature-bytes'),
        ]);

        $this->bindSignApiMock([
            $this->signApiToken(),
            new Response(200, [], json_encode(['status' => 'ok'])),
            new Response(200, [], json_encode(['data' => [['id' => 'f1', 'name' => 'doc.pdf']]])),
        ]);

        $this->withSession($this->epSession(
            [
                'state'     => $state,
                'action'    => Eparaksts::SCOPE_SIGNATURE,
                'tokens'    => [],
                'me'        => ['sign_identities' => [CERT_SIGNING_IDENTITY]],  // no CERT_MOBILEID_SIGN
                'digests'   => $digestData,
                'callbacks' => [],
            ],
            ['eparaksts__signing_' . $state => 'fin-sess']
        ))
            ->get('/eparaksts/callback?code=auth-code&state=' . $state)
            ->assertRedirect()
            ->assertSessionHas('error', 'Could not finalize signing');
    }
}
