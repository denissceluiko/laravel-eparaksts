<?php

namespace Dencel\LaravelEparaksts\Services;

use Illuminate\Session\Store;

class SessionStorage {
    protected string $prefix;
    protected array $storage = [];

    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
    }

    public function save()
    {
        $b64Encoded =json_encode($this->storage);
        session()->put($this->prefix.'_ep_storage', $b64Encoded);
    }

    public function init(Store $store)
    {
        $b64Decoded = $store->get($this->prefix.'_ep_storage', '');
        $this->storage = json_decode($b64Decoded, true) ?? [];
    }

    public function action(?string $new = null): string
    {
        if ($new === null)
            return $this->storage['action'] ?? '';

        return $this->storage['action'] = $new;
    }

    public function state(bool $new = false): string
    {
        if ($new === false)
            return $this->storage['state'] ?? '';

        $this->storage['state'] = sha1(uniqid('eparaksts_'));
        return $this->storage['state'];
    }
    
    public function me(?array $identity = null): array
    {
        if ($identity === null)
            return $this->storage['me'] ?? [];

        $this->storage['me'] = array_merge($this->storage['me'], $identity);
        return $this->storage['me'];
    }

        
    public function signIdentities(): ?array
    {
        if (empty($this->storage['me']['sign_identities']))
            return null;

        return $this->storage['me']['sign_identities'] ?? null;
    }

    public function signIdentity(string $id, ?array $newData = null): ?array
    {
        if (empty($this->storage['me']['sign_identities']))
            return null;

        foreach ($this->storage['me']['sign_identities'] as $key => $signIdentity) {
            if ($id === $signIdentity['id']) {
                if (empty($newData))
                    return $signIdentity;
                
                return $this->storage['me']['sign_identities'][$key] = $newData;
            }
        }

        return null;
    }

    public function flush(): void
    {
        session()->put($this->prefix.'_ep_storage', '');
        $this->storage = [];
    }

    public function hasTokens(): bool
    {
        return empty($this->storage['tokens']);
    }

    public function saveTokens(array $data): void
    {
        $this->storage['tokens'] = $data;
    }

    public function getTokens(): array
    {
        return $this->storage['tokens'] ?? [];
    }
}