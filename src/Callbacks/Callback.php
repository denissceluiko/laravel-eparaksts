<?php 

namespace Dencel\LaravelEparaksts\Callbacks;

abstract class Callback
{
    public ?string $sessionId = null;

    abstract public function handle();

    public function getSessionId() : ?string
    {
        return $this->sessionId;   
    }

    public function setSessionId(string $sessionId) : ?string
    {
        return $this->sessionId = $sessionId;
    }
}