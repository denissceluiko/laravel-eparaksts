<?php

namespace Dencel\LaravelEparaksts\Middleware;

use Closure;
use Illuminate\Http\Request;

class HandlesSessionStorage
{
    public function handle(Request $request, Closure $next)
    {
        $storage = resolve('ep-session');
        $storage->init($request->session());
        
        if ($storage->hasTokens()) {
            resolve('eparaksts-connector')->setTokens($storage->getTokens());
            resolve('eparaksts-signapi')->setTokens($storage->getTokens());
        }

        $response = $next($request);

        $storage->saveTokens(array_merge(
            resolve('eparaksts-signapi')->getTokens(),
            resolve('eparaksts-connector')->getTokens()
        ));

        $storage->save();

        return $response;
    }
}
