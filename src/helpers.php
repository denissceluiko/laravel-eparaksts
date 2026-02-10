<?php

namespace Dencel\LaravelEparaksts;

use Illuminate\Support\Arr;

if (!function_exists('Dencel\LaravelEparaksts\epsession')) 
{
    function epsession(string|array $key, $value = null)
    {
        $prefix = config('eparaksts.session_prefix') . '.';

        if (is_array($key)) {
            return app('session')
                ->put(Arr::prependKeysWith($key, $prefix));
        }

        if (!is_null($value)) {
            return app('session')->put($prefix.$key, $value);
        }

        return app('session')->get($prefix.$key);
    }
}

if (!function_exists('Dencel\LaravelEparaksts\newState')) 
{
    function newState()
    {
        epsession('state', sha1(uniqid('eparaksts_')));
        return epsession('state');
    }
}