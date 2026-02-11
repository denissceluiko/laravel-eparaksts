<?php

namespace Dencel\LaravelEparaksts\Services;

use Dencel\Eparaksts\Eparaksts as DencelEparaksts;
use Dencel\Eparaksts\SignAPI\v1\SignAPI;

class Eparaksts
{
    protected ?string $session = null;
    protected string $containerType = 'edoc';
    protected string $availableContainerTypes = ['edoc', 'pdf', 'asice'];
    protected bool $newContainer = false;
    protected array $filePaths = [];
    protected array $logs = [];

    public function __construct(
        protected DencelEparaksts $connector,
        protected SessionStorage $sessionStorage,
        protected SignAPI $signAPI,
    ) {
        
    }

    public function connector(): DencelEparaksts
    {
        return $this->connector;
    }

    public function sessionStorage(): SessionStorage
    {
        return $this->sessionStorage;
    }

    public function signAPI(): SignAPI
    {
        return $this->signAPI;
    }

    public function upload(array|string $paths): static
    {
        if (is_string($paths)) {
            $paths = [$paths];
        }

        foreach ($paths as $path) {
            $this->addFile($path);
        }

        if (empty($this->getFiles())) {
            $this->log('warning', 'Nothing to upload');
            return $this;
        }

        if (!$this->establishConnection()) {
            $this->log('error', 'Could not establish connection to SignAPI.');
            return $this;
        }

        if (!$this->establishSession()) {
            $this->log('error', 'Could not establish a session.');
            return $this;
        }

        return $this;
    }

    protected function addFile(string $path): bool
    {
        if (!file_exists($path)) {
            $this->log('error', 'File does not exist: ' . $path);
            return false;
        }

        $this->filePaths[] = $path;

        return true;
    }

    public function getFiles(): array
    {
        return $this->filePaths;
    }

    public function session(string $id): static
    {
        $this->session = $id;
        return $this;
    }

    public function getSession(): string
    {
        return $this->session;
    }
    
    public function signAs(string $type, ?bool $newContainer = null): bool
    {
        if ($this->canSignAs($type)) {
            $this->containerType = $type;
            $this->newContainer = is_null($newContainer) 
                ? $this->newContainer
                : $newContainer;
        }

        return false;
    }

    public function pdf(): static
    {
        $this->signAs('pdf');
        return $this;
    }

    public function edoc(?bool $newContainer = null): static
    {
        $this->signAs('edoc', $newContainer);
        return $this;
    }

    public function download()
    {

    }

    public function establishConnection(): bool
    {
        if ($this->signAPI->isExpired()) {
            $token = $this->signAPI->freshToken();
            $this->sessionStorage->saveTokens($this->signAPI->getTokens());

        }

        // $response = $this->signAPI->configuration()->get('');

        return true;
    }

    public function establishSession(): bool
    {
        if (empty($this->session)) {
            $response = $this->signAPI->session()->start();

            if (empty($response) || empty($response['data']['sessionId'])) {
                $this->log('error', 'Could not start new session');
                return false;
            }

            $this->session($response['data']['sessionId']);
        }

        $list = $this->signAPI->storage()->list($this->session);

        return true;
    }

    public function getParameters(): array
    {
        return [
            'session' => $this->session,
            'containerType' => $this->containerType,
            'newContainer' => $this->newContainer,
        ];
    }

    protected function log(string $type, string $text): void
    {
        if (empty($this->logs[$type]))
            $this->logs[$type] = [];

        $this->logs[$type][] = $text;
    }

    public function getLogs(): array
    {
        return $this->logs;
    }
}