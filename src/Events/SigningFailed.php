<?php

namespace Dencel\LaravelEparaksts\Events;

class SigningFailed
{
    public function __construct(
        public readonly ?string $sessionId,
        public readonly string $reason,
    ) {}
}
