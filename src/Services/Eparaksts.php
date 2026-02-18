<?php

namespace Dencel\LaravelEparaksts\Services;

use Dencel\Eparaksts\Eparaksts as DencelEparaksts;
use Dencel\Eparaksts\SignAPI\v1\SignAPI;
use Dencel\LaravelEparaksts\Concerns\HasCallbacks;
use Illuminate\Support\Facades\Storage;

class Eparaksts
{
    use HasCallbacks;

    public const AVAILABLE_CONTAINER_TYPES = ['edoc', 'pdf', 'asice'];

    protected ?string $session = null;
    protected bool $sessionEstablished = false;
    protected string $containerType = 'edoc';
    protected bool $allowPdf = true;
    protected bool $newContainer = false;
    protected array $files = [];
    protected array $logs = [];
    protected array $digestData = [];
    protected ?string $signature = null;
    protected ?string $disk = null;

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

    /**
     * Upload one or multiple files.
     *
     * @param array|string $paths should be either a single file path, or a list of file paths, or
     *                     a list of associative arrays.
     * @return static
     */
    public function upload(array|string $paths): static
    {
        if (empty($paths)) 
            return $this;

        // ['path' => '/path/to/file', 'name' => 'name.ext']
        if (is_array($paths) && !array_is_list($paths)) {
            $paths = [$paths];
        }

        // '/path/to/file'
        if (is_string($paths)) {
            $paths = [$paths];
        }

        if (!$this->sessionEstablished && !$this->establishSession()) {
            $this->log('error', 'Could not establish a session.');
            return $this;
        }

        foreach ($paths as $path) {
            if (is_array($path)) {
                $this->addFileFromArray($path);
            } else {
                $this->addFile($path);
            }
        }

        if (empty($this->getFiles())) {
            $this->log('warning', 'Nothing to upload');
            return $this;
        }

        $this->uploadFiles();

        return $this;
    }

    public function sign(): mixed
    {
        return redirect()->route('eparaksts.sign', [$this->getSession()]);
    }

    public function redirectAfter(string $to): static
    {
        $this->sessionStorage->redirectAfter($to);
        return $this;
    }    

    public function getRedirectAfter(): ?string
    {
        return $this->sessionStorage->redirectAfter();
    }
        
    public function resetRedirectAfter(): void
    {
        $this->sessionStorage->resetRedirectAfter();
    }

    protected function addFileFromArray(array $data): bool
    {
        if (array_is_list($data)) {
            if (count($data) == 1) { // ['/path/to/file']
                return $this->addFile($data[0]);
            } elseif (count($data) == 2) { // ['/path/to/file', 'name.ext']
                return $this->addFile($data[0], $data[1]);
            }
            return false;
        }

        if (!empty($data['path']) || !empty($data['name']))
            return false;

        // ['path' => '/path/to/file', 'name' => 'name.ext']
        return $this->addFile($data['path'], $data['name']);
    }

    protected function addFile(string $path, ?string $name = null): bool
    {
        $path = $this->disk ? Storage::disk()->path($path) : $path;

        if (!file_exists($path)) {
            $this->log('error', 'File does not exist: ' . $path);
            return false;
        }

        $name = $name ?? $this->getFilename($path);
        
        if ($this->indexOf($name) !== -1) {
            $this->log('warning', 'Omitting duplicate filename: ' . $name);
            return false;
        }        

        $this->files[] = [
            'name' => $name,
            'path' => $path,
        ];

        return true;
    }

    protected function indexOf(string $name): int
    {
        foreach ($this->files as $key => $file) {
            if ($file['name'] == $name) {
                return $key;
            }
        }

        return -1;
    }

    protected function getFilename(string $path): string
    {
        return substr($path, strrpos($path, '/') + 1);
    }

    public function getFile(string $id): ?array
    {
        foreach ($this->getFiles() as $file) {
            if ($file['id'] === $id)
                return $file;
        }

        return null;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function hasFiles(): bool
    {
        return !empty($this->files);
    }

    public function hasDigestCalculated(): bool
    {
        if(empty($this->digestData))
            return false;

        return true;
    }

    public function sessionOk(): bool
    {
        return $this->sessionEstablished;
    }

    public function session(?string $id = null): static
    {
        $this->session = $id;
        $this->sessionEstablished = false;
        $this->establishSession();
        return $this;
    }

    public function getSession(): ?string
    {
        return $this->session;
    }

    public function close(): void
    {
        if ($this->sessionEstablished) {
            $this->signAPI->session()->close($this->getSession());
        }
        
        // Clean up anything hanging
        $this->sessionStorage->flushSessionData();
        $this->sessionEstablished = false;
        $this->session = null;        
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

    public function canSignAs(string $type): bool 
    {
        if (!in_array($type, static::AVAILABLE_CONTAINER_TYPES))
            return false;

        if ($type === 'pdf') {
            if (count($this->files) === 0)
                return true;
            if (count($this->files) > 1)
                return false;
            
            $file = array_first($this->files);
            if (str_ends_with($file['name'], '.pdf') !== true)
                return false;
        }

        return true;
    }

    public function pdf(): static
    {
        $this->signAs('pdf');
        $this->allowPdf();
        return $this;
    }

    public function allowPdf(?bool $allow = true): static
    {
        $this->allowPdf = $allow;
        return $this;
    }

    public function edoc(?bool $newContainer = null): static
    {
        $this->signAs('edoc', $newContainer);
        $this->allowPdf(false);
        return $this;
    }

    public function asice(?bool $newContainer = null): static
    {
        $this->signAs('asice', $newContainer);
        $this->allowPdf(false);
        return $this;
    }

    public function disk(string $disk): static
    {
        $this->disk = $disk;
        return $this;
    }

    public function download(string $path = '', ?string $fileId = null, ?string $name = null): ?string
    {
        if (!$this->hasFiles()) {
            return null;
        }

        if (empty($fileId)) {
            $file = $this->getFiles()[0];
        } else {
            $file = $this->getFile($fileId);
        }

        $fileId = $fileId ?? $file['id'];

        $contents = $this->signAPI
            ->storage()
            ->download($this->getSession(), $fileId)
            ->getBody();

        $name = $name ?? $file['name'];
        $fullpath = rtrim($path, '/') . '/' . $name;

        if ($this->disk !== null) {
            $saved = Storage::disk($this->disk)->put($fullpath, $contents);
            $fullpath = Storage::disk($this->disk)->path($fullpath);
        } else {
            $saved = file_put_contents($fullpath, $contents);
        }
        
        return $saved !== false ? $fullpath : null;
    }

    protected function establishConnection(): bool
    {
        if ($this->signAPI->isExpired()) {
            $this->signAPI->freshToken();
            $this->sessionStorage->saveTokens($this->signAPI->getTokens());
        }

        $heartbeat = $this->signAPI->configuration()->get();

        if (empty($heartbeat))
            return false;

        return true;
    }

    protected function establishSession(): bool
    {        
        if (!$this->establishConnection()) {
            $this->log('error', 'Could not establish connection to SignAPI.');
            return false;
        }

        if (empty($this->getSession())) {
            $response = $this->signAPI->session()->start();

            if (empty($response) || empty($response['data']['sessionIds'])) {
                $this->log('error', 'Could not start new session');
                return false;
            }

            $this->session = $response['data']['sessionIds'][0];
        }

        $list = $this->signAPI->storage()->list($this->getSession());

        if (empty($list) || !array_key_exists('data',$list)) {
            $this->log('error', 'Could not connect to session');
            return false;
        }

        if (!empty($list['data'])) {
            $this->log('warning', 'Session not empty, overriding internal file data.');
            $this->files = $list['data'];
        }

        $this->sessionEstablished = true;
        $this->digestData = $this->sessionStorage->getDigest() ?? [];
        $this->callbacks = $this->sessionStorage->callbacks();

        return true;
    }

    protected function uploadFiles(): void
    {
        $newFiles = false;

        foreach ($this->getFiles() as $key => $file) {
            if (!empty($file['id'])) {
                $this->log('info', 'Already uploaded, skipping: ' . $file['name']);
                continue;
            }

            $result = $this->signAPI->storage()->upload($this->getSession(), $file['path']);

            if (empty($result['data'])) {
                $this->log('error', 'Upload failed for: '. $file['name']);
                continue;
            }

            $this->files[$key] = array_merge($this->files[$key], $result['data']);
            $newFiles = true;
        }

        if ($newFiles === true) {
            $this->digestData = [];
            $this->sessionStorage->flushDigest();
            $this->signature = null;
        }
    }

    public function calculateDigest(): bool 
    {
        if (!$this->sessionOk()) {
            $this->log('error', 'Can\'t calculate digest without a session.');
            return false;
        }

        $signingCert = $this->connector()->findCert(DencelEparaksts::CERT_SIGNING, $this->sessionStorage()->signIdentities());
        if (empty($signingCert)) {
            $this->log('error', 'Could not find signing certificate.');
            return false;
        }

        $pdf = $this->canSignAs('pdf') && $this->allowPdf === true;
        
        $response = $this->signAPI->signing()->calculateDigest(
            $this->getSession(),
            $signingCert,
            $pdf,
            $this->createNewEdoc ?? false
        );

        if (empty($response['data']) || empty($response['data']['sessionDigests'])){
            $this->log('error', 'Could not calculate digest.');
            return false;
        }

        $this->digestData = [
            'digest'                => $response['data']['sessionDigests'][0]['digest'],
            'digests_summary'       => $response['data']['digests_summary'],
            'algorithm'             => $response['data']['algorithm'],
            'signature_algorithm'   => $response['data']['signature_algorithm'],
        ];

        $this->sessionStorage->saveDigest($this->digestData);

        return true;
    }

    public function signDigest(): bool|string
    {
        if (!$this->hasDigestCalculated()) {   
            $this->log('error', 'Can\'t sign digest if it is not generated.');
            return false;
        }
        
        $signIdentity = $this->connector()->findIdentity(DencelEparaksts::CERT_SIGNING, $this->sessionStorage()->signIdentities());
        if (empty($signIdentity)) {
            $this->log('error', 'Could not find signing identity.');
            return false;
        }

        $signature = $this->connector()->sign(
            $this->digestData['digest'], 
            $this->digestData['signature_algorithm'],
            $signIdentity['id']
        );
        
        if (empty($signature)) {
            $error = json_decode($this->connector()->getResponse()->getBody()->getContents(), true);
            $this->log('error', 'Signing error: ' . $error['error']);
            return $error['error'];
        }

        $this->signature = base64_encode($signature);

        return true;
    }

    public function finalizeSigning(): bool
    {
        if (!$this->sessionOk()) {
            $this->log('error', 'Can\'t finalize signing without a session.');
            return false;
        }

        if ($this->signature === null) {
            $this->log('error', 'Can\'t finalize signing without a signature.');
            return false;
        }

        $authCertificate = $this->connector()->findCert(DencelEparaksts::CERT_MOBILEID_SIGN, $this->sessionStorage()->signIdentities());
        
        if (empty($authCertificate)) {
            $this->log('error', 'Could not find auth certificate.');
            return false;
        }

        $finalized = $this->signAPI->signing()->finalizeSigning(
            $authCertificate, 
            $this->getSession(), 
            $this->signature
        );

        if (empty($finalized['data']) || 
            empty($finalized['data']['results']) || 
            $finalized['data']['results'][0]['sessionId'] != $this->getSession() ||
            array_key_exists('error', $finalized['data']['results'][0]) 
        ) {
            $this->log('error', 'Could not finalize signing for session: ' . $this->getSession());
            return false;
        }

        return true;
    }

    public function signatureAuthorizationData(): array
    {
        if (!$this->sessionOk() || !$this->hasDigestCalculated()) {
            $this->log('error', 'Could not generate authorization data for: ' . $this->getSession());
            return [];
        }

        $signIdentity = $this->connector()->findIdentity(DencelEparaksts::CERT_SIGNING, $this->sessionStorage()->signIdentities());
        if (empty($signIdentity)) {
            $this->log('error', 'Could not find signing identity.');
            return [];
        }
        
        return [
            'sign_identity_id'          => $signIdentity['id'] ?? null,
            'digests_summary'           => $this->digestData['digests_summary'],
            'digests_summary_algorithm' => $this->digestData['algorithm'],
        ];
    }

    public function getParameters(): array
    {
        return [
            'session'       => $this->getSession(),
            'containerType' => $this->containerType,
            'newContainer'  => $this->newContainer,
            'files'         => $this->files,
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

    public static function activeSigning(string $session): bool
    {
        $activeSessions = session()->get( config('eparaksts.session_prefix') . 'active_sessions', []);
        return in_array($session, $activeSessions);
    }

    public static function startSigning(string $session): void
    {
        $activeSessions = session()->get( config('eparaksts.session_prefix') . 'active_sessions', []);
        $activeSessions[] = $session;
        session()->put( config('eparaksts.session_prefix') . 'active_sessions', $activeSessions);
    }

    public static function stopSigning(string $session): void
    {
        $activeSessions = session()->get( config('eparaksts.session_prefix') . 'active_sessions', []);
        $activeSessions = array_filter($activeSessions, function ($value) use ($session) {
            return $value != $session;
        });
        session()->put( config('eparaksts.session_prefix') . 'active_sessions', $activeSessions);
    }
}