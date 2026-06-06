<?php

namespace Dencel\LaravelEparaksts\Services;

use Dencel\Eparaksts\Eparaksts as DencelEparaksts;
use Dencel\Eparaksts\Exception\ApiException;
use Dencel\Eparaksts\SignAPI\v1\SignAPI;
use Dencel\LaravelEparaksts\Concerns\HasCallbacks;
use Dencel\LaravelEparaksts\Events\DocumentSigned;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class Eparaksts
{
    use HasCallbacks;

    public const AVAILABLE_CONTAINER_TYPES = ['edoc', 'pdf', 'asice'];

    protected ?string $session         = null;
    protected bool $sessionEstablished = false;
    protected string $containerType    = 'edoc';
    protected bool $allowPdf           = true;
    protected bool $newContainer       = false;
    protected array $files             = [];
    protected array $digestData        = [];
    protected ?string $signature       = null;
    protected ?string $disk            = null;
    protected string $signingCertType  = DencelEparaksts::CERT_SIGNING;
    protected string $authCertType     = DencelEparaksts::CERT_MOBILEID_SIGN;
    protected bool $withArchive        = false;
    protected array $batchSessions     = [];
    protected array $batchSignatures   = [];

    public function __construct(
        protected DencelEparaksts $connector,
        protected SessionStorage $sessionStorage,
        protected SignAPI $signAPI,
    ) {}

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
     * @param array|string $paths A single file path, a list of file paths, or a list of
     *                            associative arrays with 'path' and 'name' keys.
     */
    public function upload(array|string $paths): static
    {
        if (empty($paths)) {
            return $this;
        }

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
        return \redirect()->route('eparaksts.sign', [$this->getSession()]);
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

        if (empty($data['path']) || empty($data['name'])) {
            return false;
        }

        // ['path' => '/path/to/file', 'name' => 'name.ext']
        return $this->addFile($data['path'], $data['name']);
    }

    protected function addFile(string $path, ?string $name = null): bool
    {
        $path = $this->disk ? Storage::disk($this->disk)->path($path) : $path;

        if (!file_exists($path)) {
            $this->log('error', 'File does not exist: ' . $path);
            return false;
        }

        $name ??= $this->getFilename($path);

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
            if ($file['id'] === $id) {
                return $file;
            }
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
        return !empty($this->digestData);
    }

    public function sessionOk(): bool
    {
        return $this->sessionEstablished;
    }

    public function session(?string $id = null): static
    {
        $this->session            = $id;
        $this->sessionEstablished = false;
        $this->establishSession();
        return $this;
    }

    public function getSession(): ?string
    {
        return $this->session;
    }

    public function refreshFiles(): static
    {
        if (!$this->sessionEstablished) {
            $this->log('warning', 'refreshFiles() called without an established session.');
            return $this;
        }

        $list = $this->signAPI->storage()->list($this->getSession());

        if (empty($list) || !array_key_exists('data', $list)) {
            $this->log('error', 'Could not refresh file list.');
            return $this;
        }

        $this->files = $list['data'] ?? [];

        return $this;
    }

    public function close(): void
    {
        if ($this->sessionEstablished) {
            $this->signAPI->session()->close($this->getSession());
        }

        $this->sessionStorage->flushSessionData();
        $this->sessionEstablished = false;
        $this->session            = null;
    }

    public function signAs(string $type, ?bool $newContainer = null): bool
    {
        if ($this->canSignAs($type)) {
            $this->containerType = $type;
            $this->newContainer  = $newContainer ?? $this->newContainer;
            return true;
        }

        return false;
    }

    public function canSignAs(string $type): bool
    {
        if (!in_array($type, static::AVAILABLE_CONTAINER_TYPES)) {
            return false;
        }

        if ($type === 'pdf') {
            if (count($this->files) === 0) {
                return true;
            }
            if (count($this->files) > 1) {
                return false;
            }

            $file = Arr::first($this->files);
            if (str_ends_with((string) $file['name'], '.pdf') !== true) {
                return false;
            }
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

    public function qseal(): static
    {
        $this->signingCertType = DencelEparaksts::CERT_QSEAL;
        $this->authCertType    = DencelEparaksts::CERT_QSEAL;
        $this->sessionStorage->signingCertType($this->signingCertType);
        $this->sessionStorage->authCertType($this->authCertType);
        return $this;
    }

    public function withArchive(): static
    {
        $this->withArchive = true;
        $this->sessionStorage->withArchive(true);
        return $this;
    }

    public function batch(array $sessionIds): static
    {
        $this->batchSessions = array_values(array_filter($sessionIds));
        $this->sessionStorage->batchSessions($this->batchSessions);
        return $this;
    }

    public function getBatchSessions(): array
    {
        return $this->batchSessions;
    }

    public function disk(string $disk): static
    {
        $this->disk = $disk;
        return $this;
    }

    public function download(string $path = '', ?string $fileId = null, ?string $name = null, bool $keep = false): ?string
    {
        if (!$this->hasFiles()) {
            return null;
        }

        if (empty($fileId)) {
            $file = $this->getFiles()[0];
        } else {
            $file = $this->getFile($fileId);
        }

        $fileId ??= $file['id'];

        $contents = $this->signAPI
            ->storage()
            ->download($this->getSession(), $fileId)
            ->getBody();

        $name ??= $file['name'];
        $fullpath = rtrim($path, '/') . '/' . $name;

        if ($this->disk !== null) {
            $saved    = Storage::disk($this->disk)->put($fullpath, $contents);
            $fullpath = Storage::disk($this->disk)->path($fullpath);
        } else {
            $saved = file_put_contents($fullpath, $contents);
        }

        if ($saved !== false && !$keep) {
            $this->close();
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

        if (empty($heartbeat)) {
            return false;
        }

        return true;
    }

    protected function establishSession(): bool
    {
        if (!$this->establishConnection()) {
            $this->log('error', 'Could not establish connection to SignAPI.');
            return false;
        }

        if (empty($this->getSession())) {
            $this->sessionStorage->flushSessionData();
            $response = $this->signAPI->session()->start();

            if (empty($response) || empty($response['data']['sessionIds'])) {
                $this->log('error', 'Could not start new session');
                return false;
            }

            $this->session = $response['data']['sessionIds'][0];
        }

        $list = $this->signAPI->storage()->list($this->getSession());

        if (empty($list) || !array_key_exists('data', $list)) {
            $this->log('error', 'Could not connect to session');
            return false;
        }

        if (!empty($list['data'])) {
            $this->log('warning', 'Session not empty, overriding internal file data.');
            $this->files = $list['data'];
        }

        $this->sessionEstablished = true;
        $this->digestData         = $this->sessionStorage->getDigest() ?? [];
        $this->callbacks          = $this->sessionStorage->callbacks();
        $this->signingCertType    = $this->sessionStorage->signingCertType();
        $this->authCertType       = $this->sessionStorage->authCertType();
        $this->withArchive        = $this->sessionStorage->withArchive();
        $this->batchSessions      = $this->sessionStorage->batchSessions();

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

            $result = $this->signAPI->storage()->upload($this->getSession(), $file['path'], $file['name']);

            if (empty($result['data'])) {
                $this->log('error', 'Upload failed for: ' . $file['name']);
                continue;
            }

            $this->files[$key] = array_merge($this->files[$key], $result['data']);
            $newFiles          = true;
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

        $signingCert = $this->connector()->findCert($this->signingCertType, $this->sessionStorage()->signIdentities());
        if (empty($signingCert)) {
            $this->log('error', 'Could not find signing certificate.');
            return false;
        }

        $pdf = $this->canSignAs('pdf') && $this->allowPdf === true;

        $allSessions = empty($this->batchSessions)
            ? $this->getSession()
            : [$this->getSession(), ...$this->batchSessions];

        try {
            $response = $this->signAPI->signing()->calculateDigest(
                $allSessions,
                $signingCert,
                $pdf,
                // Pass null to omit the parameter when not set; true/false otherwise
                $this->newContainer ? true : null
            );
        } catch (ApiException $e) {
            $this->log('error', 'Could not calculate digest: ' . $e->getMessage());
            return false;
        }

        if (empty($response['data']) || empty($response['data']['sessionDigests'])) {
            $this->log('error', 'Could not calculate digest.');
            return false;
        }

        $sessionDigests = $response['data']['sessionDigests'];

        $this->digestData = [
            'digest'              => $sessionDigests[0]['digest'],
            'digests_summary'     => $response['data']['digests_summary'],
            'algorithm'           => $response['data']['algorithm'],
            'signature_algorithm' => $response['data']['signature_algorithm'],
        ];

        if (!empty($this->batchSessions)) {
            $allSessionIds             = [$this->getSession(), ...$this->batchSessions];
            $this->digestData['batch'] = array_map(
                fn($i) => ['sessionId' => $allSessionIds[$i], 'digest' => $sessionDigests[$i]['digest']],
                array_keys($sessionDigests)
            );
        }

        $this->sessionStorage->saveDigest($this->digestData);

        return true;
    }

    public function signDigest(): bool|string
    {
        if (!$this->hasDigestCalculated()) {
            $this->log('error', 'Can\'t sign digest if it is not generated.');
            return false;
        }

        $signIdentity = $this->connector()->findIdentity($this->signingCertType, $this->sessionStorage()->signIdentities());
        if (empty($signIdentity)) {
            $this->log('error', 'Could not find signing identity.');
            return false;
        }

        if (!empty($this->digestData['batch'])) {
            return $this->signBatchDigests($signIdentity);
        }

        $signature = $this->connector()->sign(
            $this->digestData['digest'],
            $this->digestData['signature_algorithm'],
            $signIdentity['id']
        );

        if ($signature === null) {
            $response = $this->connector()->getResponse();
            $error    = $response ? json_decode($response->getBody()->getContents(), true) : [];
            $message  = $error['error'] ?? 'Unknown signing error';
            $this->log('error', 'Signing error: ' . $message);
            return $message;
        }

        $this->signature = base64_encode($signature);

        return true;
    }

    protected function signBatchDigests(array $signIdentity): bool|string
    {
        $requests = array_map(
            fn($s) => ['digest_value' => $s['digest']],
            $this->digestData['batch']
        );

        $results = $this->connector()->signBatch(
            $requests,
            $this->digestData['signature_algorithm'],
            $signIdentity['id']
        );

        if ($results === null) {
            $this->log('error', 'Batch signing failed.');
            return false;
        }

        $this->batchSignatures = [];
        foreach ($this->digestData['batch'] as $i => $session) {
            $this->batchSignatures[] = [
                'sessionId'      => $session['sessionId'],
                'signatureValue' => base64_encode($results[$i]['signature'] ?? ''),
            ];
        }

        $this->signature = $this->batchSignatures[0]['signatureValue'] ?? null;

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

        $authCertificate = $this->connector()->findCert($this->authCertType, $this->sessionStorage()->signIdentities());

        if (empty($authCertificate)) {
            $this->log('error', 'Could not find auth certificate.');
            return false;
        }

        try {
            $finalized = $this->signAPI->signing()->finalizeSigning(
                $authCertificate,
                empty($this->batchSignatures) ? $this->getSession() : $this->batchSignatures,
                empty($this->batchSignatures) ? $this->signature : null
            );
        } catch (ApiException $e) {
            $this->log('error', 'Could not finalize signing for session ' . $this->getSession() . ': ' . $e->getMessage());
            return false;
        }

        // The API may return per-session errors at 200 HTTP status; check for them.
        if (empty($finalized['data']['results'])) {
            $this->log('error', 'Could not finalize signing for session: ' . $this->getSession());
            return false;
        }

        foreach ($finalized['data']['results'] as $result) {
            if (array_key_exists('error', $result)) {
                $this->log('error', 'Could not finalize signing for session: ' . ($result['sessionId'] ?? $this->getSession()));
                return false;
            }
        }

        if (empty($this->batchSignatures) && $finalized['data']['results'][0]['sessionId'] !== $this->getSession()) {
            $this->log('error', 'Could not finalize signing for session: ' . $this->getSession());
            return false;
        }

        if ($this->withArchive) {
            try {
                $archiveSessions = empty($this->batchSignatures)
                    ? $this->getSession()
                    : array_column($this->batchSignatures, 'sessionId');
                $this->signAPI->signing()->addArchive($authCertificate, $archiveSessions);
            } catch (ApiException $e) {
                $this->log('error', 'Could not add archive timestamp: ' . $e->getMessage());
            }
        }

        event(new DocumentSigned($this->getSession(), $this->batchSessions));

        return true;
    }

    public function getFileValidation(?string $fileId = null): ?array
    {
        $fileId ??= $this->files[0]['id'] ?? null;

        if (empty($fileId) || empty($this->getSession())) {
            return null;
        }

        return $this->signAPI->validation()->validate($this->getSession(), $fileId);
    }

    public function signatureAuthorizationData(): array
    {
        if (!$this->sessionOk() || !$this->hasDigestCalculated()) {
            $this->log('error', 'Could not generate authorization data for: ' . $this->getSession());
            return [];
        }

        $signIdentity = $this->connector()->findIdentity($this->signingCertType, $this->sessionStorage()->signIdentities());
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
        if (!config('eparaksts.logging', true)) {
            return;
        }

        match ($type) {
            'warning' => Log::warning('[eparaksts] ' . $text),
            'info'    => Log::info('[eparaksts] ' . $text),
            default   => Log::error('[eparaksts] ' . $text),
        };
    }
}
