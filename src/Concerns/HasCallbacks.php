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

        foreach ($callbacks as &$callback) {
            if (is_object($callback)) {
                $callback = serialize($callback);
            }
        }

        $this->callbacks[$fullAction] = array_merge($this->callbacks[$fullAction], $callbacks);
        $this->callbacks[$fullAction] = array_unique($this->callbacks[$fullAction]);

        $this->sessionStorage->callbacks($this->callbacks);

        return $this;
    }

    protected function invokeCallback(string $name): void 
    {
        $name = lcfirst($name);

        if (empty($this->callbacks[$name]))
            return;

        foreach ($this->callbacks[$name] as $callback) {
            if (class_exists($callback)) {
                $instance = new $callback();
            } else {
                $instance = unserialize($callback);
                
                if ($instance === false || !is_a($instance, Callback::class))
                    continue;
            }
            $instance->setEparaksts($this);
            $instance->handle();
        }
    }

    public function getCallbacks(): array
    {
        return $this->callbacks;
    }

    public function clearCallbacks(): static
    {
        $this->callbacks = [];
        return $this;
    }

    public function __call($name, $arguments): mixed
    {
        if (str_starts_with($name, 'call')) {
            $this->invokeCallback(substr($name, 4));
        } elseif (str_starts_with($name, 'before')) {
            return $this->push($name, $arguments[0]);
        } elseif (str_starts_with($name, 'after')) {
            return $this->push($name, $arguments[0]);
        }

        return null;
    }
}