<?php

namespace Dencel\LaravelEparaksts\Events;

use Illuminate\Contracts\Auth\Authenticatable;

class UserIdentified
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly array $identity,
    ) {}
}
