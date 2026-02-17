<?php 

namespace Dencel\LaravelEparaksts\Concerns;

use Dencel\LaravelEparaksts\Callbacks\Callback;

trait HasCallbacks
{
    protected array $callbacks = [];

    protected function push(string $fullAction, string|array $callbacks): static
    {
        if (is_string($callbacks)) {
            $callbacks = [$callbacks];
        }

        $callbacks = array_filter($callbacks, fn($callback): bool => is_a($callback, Callback::class, true));
        
        if (empty($this->callbacks[$fullAction])) {
            $this->callbacks[$fullAction] = [];
        }

        $this->callbacks[$fullAction] = array_merge($this->callbacks[$fullAction], $callbacks);
        $this->callbacks[$fullAction] = array_unique($this->callbacks[$fullAction]);

        $this->sessionStorage->callbacks($this->callbacks);

        return $this;
    }

    protected function invokeCallback(string $name) 
    {
        $name = lcfirst($name);

        if (empty($this->callbacks[$name]))
            return;

        foreach ($this->callbacks[$name] as $callback) {
            $instance = new $callback();
            $instance->setEparaksts($this);
            $instance->handle();
        }
    }

    public function getCallbacks(): array
    {
        return $this->callbacks;
    }

    public function __call($name, $arguments)
    {
        if (str_starts_with($name, 'call')) {
            $this->invokeCallback(substr($name, 4));
        } elseif (str_starts_with($name, 'before')) {
            $this->push($name, $arguments);
        } elseif (str_starts_with($name, 'after')) {
            $this->push($name, $arguments);
        }
    }
}