<?php

namespace Dencel\LaravelEparaksts\Facades;

class Eparaksts extends \Illuminate\Support\Facades\Facade
{
    /**
     * {@inheritDoc}
     */
    protected static function getFacadeAccessor(): string
    {
        return 'eparaksts';
    }
}