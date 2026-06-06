<?php

namespace Dencel\LaravelEparaksts\Controllers;

use Dencel\Eparaksts\Eparaksts;

use function Dencel\LaravelEparaksts\epsession;

use Dencel\LaravelEparaksts\Events\SigningFailed;
use Dencel\LaravelEparaksts\Events\UserIdentified;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

class EparakstsController
{
    public function redirect(Request $request): RedirectResponse
    {
        // Logout callback: eParaksts redirects back after logout with no code or state.
        if (epsession()->action() === 'logout') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect(config('eparaksts.redirects.logout', '/'));
        }

        $state = $request->input('state', null);

        if ($state !== epsession()->state()) {
            session()->flash('ep_error', 'state_mismatch');
            return redirect(config('eparaksts.redirects.error', '/'));
        }

        if ($request->has('error')) {
            return $this->callbackError($request);
        }

        $eparaksts = resolve('eparaksts-connector');
        $token     = $eparaksts->requestToken(
            Eparaksts::GRANT_AUTHORIZATION_CODE,
            ['code' => request('code')]
        );
        epsession()->saveTokens($eparaksts->getTokens());

        $activeSigning = session()->pull(config('eparaksts.session_prefix') . '_signing_' . $state, null);

        return match (epsession()->action()) {
            Eparaksts::SCOPE_IDENTIFICATION,
            Eparaksts::SCOPE_IDENTIFICATION_WITH_AGE,
            Eparaksts::SCOPE_IDENTIFICATION_WITH_AGE_14,
            Eparaksts::SCOPE_IDENTIFICATION_WITH_AGE_16,
            Eparaksts::SCOPE_IDENTIFICATION_WITH_AGE_18,
            Eparaksts::SCOPE_IDENTIFICATION_WITH_AGE_21 => $this->callbackIdentification(),
            Eparaksts::SCOPE_SIGNING_IDENTITY           => $this->callbackIdentities(),
            Eparaksts::SCOPE_SIGNATURE                  => $this->finalizeSigning($request->merge(['session' => $activeSigning])),
            default                                     => $this->callbackDefault(),
        };
    }

    public function identificationFlow(): RedirectResponse
    {
        $scope = match (request('age')) {
            '14'    => Eparaksts::SCOPE_IDENTIFICATION_WITH_AGE_14,
            '16'    => Eparaksts::SCOPE_IDENTIFICATION_WITH_AGE_16,
            '18'    => Eparaksts::SCOPE_IDENTIFICATION_WITH_AGE_18,
            '21'    => Eparaksts::SCOPE_IDENTIFICATION_WITH_AGE_21,
            null    => Eparaksts::SCOPE_IDENTIFICATION,
            default => Eparaksts::SCOPE_IDENTIFICATION_WITH_AGE,
        };

        epsession()->action($scope);

        $flow = match (request('flow')) {
            'mobile' => Eparaksts::ACR_MOBILEID,
            'sc'     => Eparaksts::ACR_SC_PLUGIN,
            'eid'    => Eparaksts::ACR_MOBILE_EID,
            'cross'  => Eparaksts::ACR_MOBILEID_CROSS,
            default  => null,
        };

        $eparaksts = resolve('eparaksts-connector');

        $redirect = $eparaksts->authorize(
            epsession()->action(),
            epsession()->state(true),
            route('eparaksts.redirect'),
            ['acr_values' => $flow]
        );

        return redirect($redirect);
    }

    public function logoutFlow(): RedirectResponse
    {
        // Set action first so the callback can recognise the return redirect from eParaksts.
        epsession()->action('logout');
        $redirect = resolve('eparaksts-connector')->logout(route('eparaksts.redirect'));

        return redirect($redirect);
    }

    public function identitiesFlow(): RedirectResponse
    {
        epsession()->action(Eparaksts::SCOPE_SIGNING_IDENTITY);
        $eparaksts = resolve('eparaksts-connector');

        $redirect = $eparaksts->authorize(
            epsession()->action(),
            epsession()->state(true),
            route('eparaksts.redirect')
        );

        return redirect($redirect);
    }

    public function signFlow(Request $request, string $session): RedirectResponse
    {
        $sessionId = $session;

        $here = route('eparaksts.sign', ['session' => $sessionId]);

        $eparaksts = resolve('eparaksts');
        $eparaksts->callBeforeSignFlowSessionEstablished();

        $eparaksts->session($sessionId);

        if (!$eparaksts->sessionOk()) {
            return back();
        }

        $eparaksts->callAfterSignFlowSessionEstablished();

        if ($eparaksts->getRedirectAfter() === null) {
            $referer = $request->headers->get('referer');
            if ($referer && parse_url($referer, PHP_URL_HOST) === $request->getHost()) {
                $eparaksts->redirectAfter($referer);
            }
        }

        $eparaksts->callBeforeIdentificationObtained();

        if (!$eparaksts->connector()->isAuthenticated(Eparaksts::SCOPE_IDENTIFICATION)) {
            Redirect::setIntendedUrl($here);
            return redirect()->route('eparaksts.identification');
        }

        $eparaksts->callAfterIdentificationObtained();

        if (!$eparaksts->hasFiles()) {
            session()->flash('error', 'Session has no files');
            return back();
        }

        $eparaksts->callBeforeSigningIdentityObtained();

        if (!$eparaksts->connector()->isAuthenticated(Eparaksts::SCOPE_SIGNING_IDENTITY)) {
            Redirect::setIntendedUrl($here);
            return redirect()->route('eparaksts.identities');
        }

        $eparaksts->callAfterSigningIdentityObtained();

        if (!$eparaksts->hasDigestCalculated() && !$eparaksts->calculateDigest()) {
            session()->flash('error', 'Could not calculate digest');
            return back();
        }

        $eparaksts->callAfterDigestCalculated();

        epsession()->flushToken(Eparaksts::SCOPE_SIGNATURE);
        epsession()->action(Eparaksts::SCOPE_SIGNATURE);
        $oauthState = epsession()->state(true);
        $redirect   = $eparaksts->connector()->authorize(
            epsession()->action(),
            $oauthState,
            route('eparaksts.redirect'),
            $eparaksts->signatureAuthorizationData()
        );

        session()->put(config('eparaksts.session_prefix') . '_signing_' . $oauthState, $sessionId);
        $eparaksts->callBeforeSignatureAuthorizationRedirect();

        return redirect($redirect);
    }

    public function finalizeSigning(Request $request): RedirectResponse
    {
        $eparaksts = resolve('eparaksts')
            ->session($request->input('session'));

        $tokens = epsession()->getTokens()[Eparaksts::SCOPE_SIGNATURE] ?? null;
        if (empty($tokens['bearer'])) {
            return redirect()->route('eparaksts.sign', [$eparaksts->getSession()]);
        }

        $eparaksts->callBeforeSigningDigest();

        $digestSignResult = $eparaksts->signDigest();
        if ($digestSignResult === false) {
            event(new SigningFailed($eparaksts->getSession(), 'sign_digest_failed'));
            session()->flash('error', 'Could not sign digest');
            return back();
        }
        $eparaksts->callAfterSigningDigest();

        if (!$eparaksts->finalizeSigning()) {
            event(new SigningFailed($eparaksts->getSession(), 'finalize_failed'));
            session()->flash('error', 'Could not finalize signing');
            return back();
        }

        $eparaksts->callAfterSigningFinalized();

        $redirect = $eparaksts->getRedirectAfter() ?? config('eparaksts.redirects.signing_complete', '/');
        $eparaksts->callBeforeFinalRedirect();
        $eparaksts->resetRedirectAfter();

        return redirect()->to($redirect);
    }

    public function callbackIdentification(): RedirectResponse
    {
        $eparaksts = resolve('eparaksts-connector');
        $identity  = $eparaksts->me(epsession()->action());

        if (empty($identity)) {
            return redirect()->route('eparaksts.identification');
        }

        epsession()->me($identity);

        $response = resolve('eparaksts')->restoreCallbacks()->callOnIdentificationReceived($identity);
        if ($response !== null) {
            return $response;
        }

        $user = $this->attemptAuthentication($identity);
        if ($user !== null) {
            session()->regenerate();
            event(new UserIdentified($user, $identity));

            return redirect()->intended(config('eparaksts.redirects.login', '/'));
        } elseif (config('eparaksts.registration_enabled') === true) {
            return $this->register($identity);
        } else {
            event(new Failed(config('auth.defaults.guard', 'web'), null, $this->mapCredentials($identity)));
            session()->flash('ep_error', 'user_not_found');
        }

        return redirect()->intended(config('eparaksts.redirects.login', '/'));
    }

    public function callbackIdentities(): RedirectResponse
    {
        $eparaksts  = resolve('eparaksts-connector');
        $identities = $eparaksts->me(Eparaksts::SCOPE_SIGNING_IDENTITY);

        if (empty($identities)) {
            return redirect()->route('eparaksts.identities');
        }

        epsession()->me($identities);

        foreach (epsession()->signIdentities() as $identity) {
            $data = $eparaksts->getSignIdentity($identity['id']);
            epsession()->signIdentity($identity['id'], $data['identity']);
        }

        return redirect()->intended(config('eparaksts.redirects.login', '/'));
    }

    public function callbackDefault(): RedirectResponse
    {
        return redirect()->intended(config('eparaksts.redirects.login', '/'));
    }

    public function callbackError(Request $request): RedirectResponse
    {
        $error       = $request->input('error', 'unknown_error');
        $description = $request->input('error_description', '');
        session()->flash('ep_error', $error);

        if ($description) {
            session()->flash('ep_error_description', $description);
        }

        resolve('eparaksts')->restoreCallbacks()->callOnError();

        // For signing-flow cancellations, redirect to the page that initiated signing.
        $activeSigning = session()->pull(config('eparaksts.session_prefix') . '_signing_' . $request->input('state'), null);
        if ($activeSigning !== null) {
            $redirectAfter = epsession()->redirectAfter();
            if ($redirectAfter !== null) {
                return redirect()->to($redirectAfter);
            }
        }

        return redirect()->intended(config('eparaksts.redirects.error', '/'));
    }

    protected function mapCredentials(array $identity): array
    {
        $credentials = [
            config('eparaksts.fields.full_name')  => $identity['name'],
            config('eparaksts.fields.first_name') => $identity['given_name'],
            config('eparaksts.fields.last_name')  => $identity['family_name'],
        ];

        if (config('eparaksts.normalize_names')) {
            foreach ($credentials as &$name) {
                $name = $this->normalize($name);
            }
        }

        $credentials[config('eparaksts.fields.personal_number')] = $identity['serial_number'];

        return $credentials;
    }

    protected function normalize(string $string): string
    {
        return ucfirst(strtolower($string));
    }

    protected function attemptAuthentication(array $identity): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $fields = Arr::only(
            config('eparaksts.fields'),
            config('eparaksts.authentication_match')
        );

        $values = Arr::only(
            $this->mapCredentials($identity),
            array_values($fields)
        );

        $type = config('eparaksts.user_model');
        $user = $type::where($values)->first();

        if (empty($user)) {
            return null;
        }

        Auth::login($user);
        return $user;
    }

    protected function register(array $identity): RedirectResponse
    {
        $type = config('eparaksts.user_model');
        $user = $type::create($this->mapCredentials($identity));

        event(new Registered($user));
        Auth::login($user);
        session()->regenerate();
        epsession()->me($identity);
        event(new UserIdentified($user, $identity));

        return redirect()->intended(config('eparaksts.redirects.login', '/'));
    }
}
