<?php

namespace Dencel\LaravelEparaksts\Events;

class DocumentSigned
{
    public function __construct(
        public readonly string $sessionId,
        public readonly array $batchSessionIds = [],
    ) {}
}
