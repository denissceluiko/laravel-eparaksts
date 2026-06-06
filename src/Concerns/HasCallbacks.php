<?php

namespace Dencel\LaravelEparaksts\Concerns;

use Dencel\LaravelEparaksts\Callbacks\Callback;
use Dencel\LaravelEparaksts\Callbacks\IdentificationCallback;
use Illuminate\Http\RedirectResponse;

trait HasCallbacks
{
    protected array $callbacks = [];

    public function restoreCallbacks(): static
    {
        $this->callbacks = $this->sessionStorage->callbacks();
        return $this;
    }

    protected function push(string $fullAction, mixed $callback): static
    {
        if (!is_string($callback)) {
            return $this;
        }

        if (!is_a($callback, Callback::class, true) && !is_a($callback, IdentificationCallback::class, true)) {
            return $this;
        }

        if (empty($this->callbacks[$fullAction])) {
            $this->callbacks[$fullAction] = [];
        }

        $this->callbacks[$fullAction] = array_merge($this->callbacks[$fullAction], [$callback]);
        $this->callbacks[$fullAction] = array_unique($this->callbacks[$fullAction]);

        $this->sessionStorage->callbacks($this->callbacks);

        return $this;
    }

    protected function invokeCallback(string $name): void
    {
        $name = lcfirst($name);

        if (empty($this->callbacks[$name])) {
            return;
        }

        foreach ($this->callbacks[$name] as $callback) {
            if (!is_a($callback, Callback::class, true)) {
                continue;
            }
            $instance = new $callback();
            $instance->setEparaksts($this);
            $instance->handle();
        }
    }

    public function callOnIdentificationReceived(array $identity): ?RedirectResponse
    {
        $name = 'onIdentificationReceived';

        if (empty($this->callbacks[$name])) {
            return null;
        }

        foreach ($this->callbacks[$name] as $callback) {
            if (!is_a($callback, IdentificationCallback::class, true)) {
                continue;
            }
            $instance           = new $callback();
            $instance->identity = $identity;
            $response           = $instance->handle();
            if ($response !== null) {
                return $response;
            }
        }

        return null;
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
        if (str_starts_with((string) $name, 'call')) {
            $this->invokeCallback(substr((string) $name, 4));
        } elseif (str_starts_with((string) $name, 'before')) {
            return $this->push($name, $arguments[0]);
        } elseif (str_starts_with((string) $name, 'after')) {
            return $this->push($name, $arguments[0]);
        } elseif (str_starts_with((string) $name, 'on')) {
            return $this->push($name, $arguments[0]);
        }

        return null;
    }
}
