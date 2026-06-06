<?php

namespace Dencel\LaravelEparaksts\Callbacks;

use Illuminate\Http\RedirectResponse;

abstract class IdentificationCallback
{
    public array $identity = [];

    abstract public function handle(): ?RedirectResponse;
}
