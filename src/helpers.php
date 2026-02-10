<?php

namespace Dencel\LaravelEparaksts;

use Illuminate\Support\Arr;

if (!function_exists('Dencel\LaravelEparaksts\epsession')) 
{
    function epsession()
    {
        return resolve('ep-session');
    }
}