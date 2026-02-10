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
            resolve('eparaksts')->setTokens($storage->getTokens());
        }

        $response = $next($request);

        $storage->save();

        return $response;
    }
}
