<?php 

namespace Dencel\LaravelEparaksts\Controllers;

use Dencel\Eparaksts\Eparaksts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

use function Dencel\LaravelEparaksts\epsession;

class EparakstsController
{
    public function redirect(Request $request)
    {
        $state = request('state', null);

        if ($state !== epsession()->state()) {
            session()->flash('ep_error', 'state mismatch');
            abort(403);
            // TBI
        }

        $eparaksts = resolve('eparaksts-connector');
        $token = $eparaksts->requestToken(
            Eparaksts::GRANT_AUTHORIZATION_CODE, 
            ['code' => request('code')]
        );
        epsession()->saveTokens($eparaksts->getTokens());

        $activeSigning = session()->get( config('eparaksts.session_prefix') . '_active_signing' , null);

        return match (epsession()->action()) {
            Eparaksts::SCOPE_IDENTIFICATION => $this->callbackIdentification(),
            Eparaksts::SCOPE_SIGNING_IDENTITY => $this->callbackIdentities(),
            Eparaksts::SCOPE_SIGNATURE => $this->finalizeSigning($request->merge(['session' => $activeSigning])),
            default => $this->callbackDefault(),
        };
    }

    public function identificationFlow(): RedirectResponse
    {
        epsession()->action(Eparaksts::SCOPE_IDENTIFICATION);

        $flow = match(request('flow')) {
            'mobile' => Eparaksts::ACR_MOBILEID,
            'sc' => Eparaksts::ACR_SC_PLUGIN,
            'eid' => Eparaksts::ACR_MOBILE_EID,
            'cross' => Eparaksts::ACR_MOBILEID_CROSS,
            default => null,
        };

        $eparaksts = resolve('eparaksts-connector');

        // TBI: check if token still valid and usable before redirect

        $redirect = $eparaksts->authorize(
            epsession()->action(),
            epsession()->state(true),
            route('eparaksts.redirect'),
            ['acr_values' => $flow]
        );
        
        return redirect($redirect);
    }

    public function logoutFlow()
    {
        epsession()->action('logout');
        $redirect = resolve('eparaksts-connector')->logout(route('eparaksts.redirect'));
        epsession()->flush();

        return $this->redirect($redirect);
    }

    public function identitiesFlow(): RedirectResponse
    {
        epsession()->action(Eparaksts::SCOPE_SIGNING_IDENTITY);
        $eparaksts = resolve('eparaksts-connector');
        // TBI: check if token still valid and usable before redirect

        $redirect = $eparaksts->authorize(
            epsession()->action(),
            epsession()->state(true),
            route('eparaksts.redirect')
        );
        
        return redirect($redirect);
    }

    public function signFlow(Request $request)
    {
        $sessionId = $request->session;

        $here = route('eparaksts.sign', ['session' => $sessionId]);

        $eparaksts = resolve('eparaksts')
            ->session($sessionId);

        if (!$eparaksts->sessionOk()) {
            return back();
        }

        if ($eparaksts->getRedirectAfter() === null) {
            $eparaksts->redirectAfter($request->headers->get('referer'));
        }

        if (!$eparaksts->connector()->isAuthenticated(Eparaksts::SCOPE_IDENTIFICATION)) {
            Redirect::setIntendedUrl($here);
            return redirect()->route('eparaksts.identification');
        }

        if (!$eparaksts->hasFiles()) {
            session()->flash('error', 'Session has no files');
            return back();
        }

        if (!$eparaksts->connector()->isAuthenticated(Eparaksts::SCOPE_SIGNING_IDENTITY)) {
            Redirect::setIntendedUrl($here);
            return redirect()->route('eparaksts.identities');
        }

        if (!$eparaksts->hasDigestCalculated() && !$eparaksts->calculateDigest()) {
            session()->flash('error', 'Could not calculate digest');
            return back();
        }

        epsession()->action(Eparaksts::SCOPE_SIGNATURE);
        $redirect = $eparaksts->connector()->authorize(
            epsession()->action(),
            epsession()->state(true),
            route('eparaksts.redirect', ['session' => $request->session]),
            $eparaksts->signatureAuthorizationData()
        );

        session()->flash( config('eparaksts.session_prefix') . '_active_signing' , $request->session);
        
        return redirect($redirect);
    }

    public function finalizeSigning(Request $request)
    {
        $eparaksts = resolve('eparaksts')
            ->session($request->session);

        $digestSignResult = $eparaksts->signDigest();
        if ($digestSignResult === false) {
            session()->flash('error', 'Could not sign digest');
            return back();
        }

        if (!$eparaksts->finalizeSigning()) {
            session()->flash('error', 'Could not finalize signing');
            return back();
        }

        $redirect = $eparaksts->getRedirectAfter();
        $eparaksts->resetRedirectAfter();
        return redirect()->to($redirect);
    }

    public function callbackIdentification()
    {
        $eparaksts = resolve('eparaksts-connector');
        $identity = $eparaksts->me(Eparaksts::SCOPE_IDENTIFICATION);

        if (empty($identity)) {
            return redirect()->route('eparaksts.identification');
        }

        if ($this->attemptAuthentication($identity)) {
            session()->regenerate();
            epsession()->me($identity);

            return redirect()->intended('/');
        } elseif (config('eparaksts.registration_enabled') === true) {
            return $this->register($identity); // TBI
        }

        return redirect()->intended('/');
    }

    public function callbackIdentities()
    {
        $eparaksts = resolve('eparaksts-connector');
        $identities = $eparaksts->me(Eparaksts::SCOPE_SIGNING_IDENTITY);

        if (empty($identities)) {
            return redirect()->route('eparaksts.identities');
        }

        epsession()->me($identities);

        foreach (epsession()->signIdentities() as $identity) {
            $data = $eparaksts->getSignIdentity($identity['id']);
            epsession()->signIdentity($identity['id'], $data['identity']);
        }

        return redirect()->intended('/');
    }

    public function callbackDefault()
    {
        return redirect('/');
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

    protected function attemptAuthentication(array $identity): bool
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
        
        if (empty($user))
            return false;

        Auth::login($user);
        return true;
    }

    protected function register(array $identity)
    {
        //
    }
}