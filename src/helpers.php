<?php

namespace Dencel\LaravelEparaksts;

if (!function_exists('Dencel\LaravelEparaksts\epsession')) {
    function epsession()
    {
        return resolve('ep-session');
    }
}
