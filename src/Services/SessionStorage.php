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
        $b64Encoded = base64_encode(json_encode($this->storage));
        session()->put($this->prefix.'_ep_storage', $b64Encoded);
    }

    public function init(Store $store)
    {
        $b64Decoded = base64_decode($store->get($this->prefix.'_ep_storage', ''), true);
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

        $this->storage['me'] = array_merge($this->storage['me'] ?? [], $identity);
        return $this->storage['me'];
    }

        
    public function signIdentities(): ?array
    {
        if (empty( $this->me()['sign_identities'] ))
            return null;

        return $this->storage['me']['sign_identities'] ?? null;
    }

    public function signIdentity(string $id, ?array $newData = null): ?array
    {
       if (empty( $this->me()['sign_identities'] ))
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

    public function callbacks(): array
    {
        if (empty($this->storage['callbacks'])) {
            $this->storage['callbacks'] = [];
        }

        return $this->storage['callbacks'];
    }

    public function callbacksFor(string $hook, ?array $callbacks = null): array
    {
        if (empty($this->storage['callbacks'])) {
            $this->storage['callbacks'] = [];
        }

        if ($callbacks === null) {
            return $this->storage['callbacks'][$hook] ?? [];
        }

        $this->storage['callbacks'][$hook] = $callbacks;
        return $this->storage['callbacks'][$hook];
    }

    public function redirectAfter(?string $to = null): ?string
    {
        if ($to !== null) {
            $this->storage['redirectAfter'] = $to;
        }

        return $this->storage['redirectAfter'] ?? null;
    }

    public function resetRedirectAfter(): void
    {
        $this->storage['redirectAfter'] = null;
    }

    public function flush(): void
    {
        session()->put($this->prefix.'_ep_storage', '');
        $this->storage = [];
    }

    public function hasTokens(): bool
    {
        return !empty($this->storage['tokens']);
    }

    public function saveTokens(array $tokens): void
    {
        foreach ($tokens as $scope => $token) {
            if (empty($token['bearer']) || empty($token['expires']))
                continue;
            
            $this->storage['tokens'][$scope] = $token;
        }
    }

    public function getTokens(): array
    {
        return $this->storage['tokens'] ?? [];
    }

    public function getDigest(string $session): ?array
    {
        if (empty($this->storage['digests'])){
            return null;
        }

        return $this->storage['digests'][$session] ?? null;
    }

    public function saveDigest(string $session, ?array $data): void
    {
        if (empty($this->storage['digests'])){
            $this->storage['digests'] = [];
        }

        $this->storage['digests'][$session] = $data;
    }
}