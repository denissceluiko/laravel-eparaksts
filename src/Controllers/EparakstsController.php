<?php 

namespace Dencel\LaravelEparaksts\Controllers;

use Dencel\Eparaksts\Eparaksts;
use Illuminate\Http\RedirectResponse;

use function Dencel\LaravelEparaksts\epsession;
use function Dencel\LaravelEparaksts\newState;

class EparakstsController
{
    public function redirect()
    {
        $state = request('state', null);

        // if ($state !== '')
    }

    public function identificationFlow(): RedirectResponse
    {
        epsession('action', Eparaksts::SCOPE_IDENTIFICATION);

        $flow = match(request('flow')) {
            'mobile' => Eparaksts::ACR_MOBILEID,
            'sc' => Eparaksts::ACR_SC_PLUGIN,
            'eid' => Eparaksts::ACR_MOBILE_EID,
            'cross' => Eparaksts::ACR_MOBILEID_CROSS,
            default => null,
        };


        $eparaksts = resolve('eparaksts');

        $redirect = $eparaksts->authorize(
            epsession('action'),
            newState(),
            route('eparaksts.redirect'),
            ['acr_values' => $flow]
        );
        
        return redirect($redirect);
    }
}