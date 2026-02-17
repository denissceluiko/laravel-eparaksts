<?php 

namespace Dencel\LaravelEparaksts\Concerns;

use Dencel\LaravelEparaksts\Callbacks\Callback;

trait HasCallbacks
{
    protected array $afterSignCallbacks = [];

    public function afterSigning(Callback|array $callbacks) : static
    {
        if ($callbacks instanceof Callback) {
            $callbacks = [];
        }

        $callbacks = array_filter($callbacks, fn($callback): bool => is_a($callback, Callback::class, true));

        array_push($this->afterSignCallbacks, $callbacks);
        $this->sessionStorage->callbacksFor('afterSign', $this->afterSignCallbacks);

        return $this;
    }

    public function callAfterSign()
    {
        if (empty($this->afterSignCallbacks)) {
            return;
        }

        foreach ($this->afterSignCallbacks as $callback) {
            if (!is_a($callback, Callback::class, true))
                continue;

            $instance = new $callback();
            $instance->setSessionId($this->getSession());
            $instance->handle();
        }
    }
}