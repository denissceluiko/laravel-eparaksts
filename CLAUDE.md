# laravel-eparaksts — Laravel package for eParaksts

Laravel 12/13 service-provider that wraps **dencel/eparaksts** (^0.3) and orchestrates the full document-signing and identity-verification flow for the Latvian eParaksts platform.

Namespace: `Dencel\LaravelEparaksts`. Requires PHP ≥ 8.4.

Cross-reference the base library CLAUDE.md for raw API contracts — this package is a Laravel orchestration layer on top of that client. Read `vendor/dencel/eparaksts/CLAUDE.md`.

Official eParaksts developer docs: https://developers.eparaksts.lv/  
LLM-friendly docs index (all API pages): https://developers.eparaksts.lv/llms.txt

---

## Architecture

```
EparakstsServiceProvider   — registers singletons + boots middleware/routes
HandlesSessionStorage      — middleware: loads ep_storage blob into SessionStorage singleton → saves back after response
EparakstsController        — OAuth callback handler + flow entrypoints
Services/Eparaksts         — fluent service: upload, sign, download lifecycle
Services/SessionStorage    — typed wrapper around Laravel's session (base64-encoded JSON blob)
Concerns/HasCallbacks      — __call magic: before*/after* hooks, callXxx dispatch
Callbacks/Callback         — abstract base for user-defined lifecycle hooks
Facades/Eparaksts          — resolves 'eparaksts' binding
helpers.php                — epsession() helper → resolves 'ep-session' binding
```

### IoC bindings (singletons unless noted)

| Key | Class | Notes |
|---|---|---|
| `eparaksts-connector` | `Dencel\Eparaksts\Eparaksts` | OAuth + identity client |
| `eparaksts-signapi` | `Dencel\Eparaksts\SignAPI\v1\SignAPI` | Document session/signing client |
| `ep-session` | `Services\SessionStorage` | Per-request session wrapper |
| `eparaksts` | `Services\Eparaksts` | **bind** (not singleton) — new instance per resolve |

---

## Full signing flow (code walkthrough)

### 1. Upload files and redirect to eParaksts

In your controller or job:

```php
use Dencel\LaravelEparaksts\Facades\Eparaksts;

// Upload file(s) and redirect user to the signing flow.
return Eparaksts::upload('/path/to/document.pdf')
    ->redirectAfter(route('documents.show', $doc))
    ->sign();
// sign() returns redirect()->route('eparaksts.sign', [$sessionId])

// Optional modifiers before sign():
->qseal()          // use CERT_QSEAL for signing and auth (eZīmogs+)
->withArchive()    // add LTV archive timestamp after signing (non-fatal if it fails)
->batch([$sessB])  // include additional already-uploaded sessions in one OAuth round-trip
```

`upload()` accepts:
- `'/path/to/file'` — single string path
- `['path' => '/path/to/file', 'name' => 'display.pdf']` — assoc array
- `[['/path/a', 'a.pdf'], ['/path/b', 'b.pdf']]` — list of pairs
- `['/path/a', '/path/b']` — list of paths

### 2. The signing flow (automatic, via routes)

`GET /ep/sign/{session}` → `EparakstsController::signFlow()`

The controller orchestrates sequentially:
1. Establish SignAPI session (reconnect if needed)
2. If not identified → redirect to `GET /ep/auth` (identification OAuth)
3. If session has no files → error + `back()`
4. If no signing identities → redirect to `GET /ep/identities` (signing identity OAuth)
5. Calculate digest using `CERT_SIGNING` certificate
6. Authorize signing via `SCOPE_SIGNATURE` OAuth → redirect to eParaksts
7. eParaksts redirects back to callback → `finalizeSigning()`
8. Redirect to `redirectAfter()` URL

**Cancellation / OAuth error:** if the user cancels on the eParaksts side, the callback receives `?error=access_denied`. `ep_error` is flashed with the error code and the user is redirected to `redirectAfter()` (same destination as a successful signing, so the initiating page handles the error). For identification and signing-identity errors the user is redirected via `redirect()->intended()` back to the sign flow.

### 3. Batch signing

`->batch(array $sessionIds)` adds additional sessions to the signing round-trip. All sessions are included in the single `calculateDigest()` call (one OAuth authorization covers all), then `signBatch()` signs all digests at once, and `finalizeSigning()` finalizes all sessions in one call.

- `digestData['batch']` in the session blob holds `[{sessionId, digest}]` for all sessions.
- `batchSignatures` (`[{sessionId, signatureValue}]`) is built in memory during `signDigest()` and consumed immediately by `finalizeSigning()` — not persisted.
- `batch_sessions` is cleared from the session blob by `flushSessionData()`.
- Archive timestamps (`withArchive()`) are applied to all sessions when the flag is set.
- File validation (`getFileValidation()`) must be called per session after signing.

### 4. Download the signed document

After signing completes and the user is redirected back to your app:

```php
// Re-attach to the session to download
$path = Eparaksts::session($sessionId)
    ->download('/storage/signed/');

// Download a specific file to an S3 disk
$path = Eparaksts::session($sessionId)
    ->disk('s3')
    ->download('signed/', fileId: $fileId, name: 'signed-contract.pdf');

// Download without auto-closing the session (e.g. to download multiple files)
$path = Eparaksts::session($sessionId)->download('/storage/signed/', keep: true);
```

---

## Routes

All routes carry the `web` middleware and the `eparaksts.` name prefix.

| Method | URI | Name | Handler |
|---|---|---|---|
| GET | `{eparaksts.redirect}` (config) | `eparaksts.redirect` | OAuth callback dispatcher |
| GET | `/{route_prefix}/auth/{flow?}` | `eparaksts.identification` | Start identification OAuth |
| GET | `/{route_prefix}/logout` | `eparaksts.logout` | Start logout |
| GET | `/{route_prefix}/identities` | `eparaksts.identities` | Start signing-identity OAuth |
| GET | `/{route_prefix}/sign/{session}` | `eparaksts.sign` | Signing flow orchestrator |

`route_prefix` defaults to `ep`. `eparaksts.redirect` defaults to `/eparaksts/callback`.

### OAuth flows available

| Route | `?flow=` | ACR value |
|---|---|---|
| `/ep/auth` | `mobile` | Mobile-ID |
| `/ep/auth` | `sc` | Smart Card plugin |
| `/ep/auth` | `eid` | Mobile eID |
| `/ep/auth` | `cross` | Mobile-ID cross-device |

---

## Config (`config/eparaksts.php`)

| Key | Default | Description |
|---|---|---|
| `user_model` | `App\Models\User` | Eloquent model for auth/registration |
| `fields.full_name` | `full_name` | DB column for full name |
| `fields.first_name` | `first_name` | DB column for first name |
| `fields.last_name` | `last_name` | DB column for last name |
| `fields.personal_number` | `personal_number` | DB column for `PNOXX-YYYYY-ZZZZZ` |
| `normalize_names` | `false` | Normalize UPPERCASE names from API |
| `authentication_match` | `[personal_number, full_name, first_name, last_name]` | Fields to match for login |
| `registration_enabled` | `false` | Auto-create users on first login |
| `username` | `env(EPARAKSTS_USERNAME)` | OAuth client ID |
| `password` | `env(EPARAKSTS_PASSWORD)` | OAuth client secret |
| `host` | demo host | eIDAS host (production: `https://eidas.eparaksts.lv`) |
| `signapi_host` | demo host | SignAPI host (production: `https://signapi.eparaksts.lv`) |
| `session_prefix` | `eparaksts_` | Laravel session key prefix |
| `route_prefix` | `ep` | URI prefix for package routes |
| `redirect` | `/eparaksts/callback` | OAuth callback URI |
| `redirects.login` | `/` | Fallback for `redirect()->intended()` after identification, signing-identity callback, registration, and default OAuth callback |
| `redirects.logout` | `/` | Destination after logout completes (no `intended()` — session already flushed) |
| `redirects.error` | `/` | Destination on state mismatch and identification/identity OAuth errors (signing-flow cancellations redirect to `redirectAfter` instead) |
| `redirects.signing_complete` | `/` | Fallback when `redirectAfter()` was never set and signing completes |
| `logging` | `true` | Forward internal errors/warnings to `Log::error/warning/info`. Set `EPARAKSTS_LOGGING=false` to suppress (e.g. if raw API error responses in logs are a concern) |

---

## Callback system (`HasCallbacks` + `Callback`)

Lifecycle hooks fire at specific points in the signing flow. Implement `Callback::handle()`:

```php
use Dencel\LaravelEparaksts\Callbacks\Callback;

class NotifyAfterSigning extends Callback
{
    public function handle(): void
    {
        // $this->eparaksts is the Services\Eparaksts instance
        $session = $this->eparaksts->getSession();
        // send notification, update DB, etc.
    }
}
```

Register hooks fluently before calling `sign()`:

```php
Eparaksts::upload($path)
    ->afterSigningFinalized(NotifyAfterSigning::class)
    ->beforeFinalRedirect(AuditLog::class)
    ->redirectAfter(route('done'))
    ->sign();
```

### Available hook points

Callbacks are named by convention: `before*` / `after*` register them; `call*` dispatches them.

| Method to register | Fires in |
|---|---|
| `beforeSignFlowSessionEstablished` | `signFlow()` start |
| `afterSignFlowSessionEstablished` | after session connected |
| `beforeIdentificationObtained` | before identity check |
| `afterIdentificationObtained` | after identity confirmed |
| `beforeSigningIdentityObtained` | before signing-identity check |
| `afterSigningIdentityObtained` | after signing identity confirmed |
| `afterDigestCalculated` | after digest computed |
| `beforeSignatureAuthorizationRedirect` | before eParaksts OAuth redirect |
| `beforeSigningDigest` | before server-side sign call |
| `afterSigningDigest` | after digest signed |
| `afterSigningFinalized` | after `finalizeSigning()` succeeds |
| `beforeFinalRedirect` | before redirecting to `redirectAfter` URL |
| `onIdentificationReceived` | after identity fetched in `callbackIdentification()`; callback is `IdentificationCallback` subclass with `handle(): ?RedirectResponse` — return a response to bypass default login logic, `null` to fall through |
| `onError` | on OAuth error callback |

### Serialization note

Callback class names are stored as strings in the session and instantiated via `new $class()` on dispatch. Only class name strings are accepted — passing anything other than a string causes a `TypeError`. This eliminates PHP object deserialization risk entirely.

---

## SessionStorage

`Services\SessionStorage` stores all transient state as a single base64-encoded JSON blob keyed `{prefix}_ep_storage` in the Laravel session. The `HandlesSessionStorage` middleware loads it on every request and saves it after the response.

Fields stored:
- `action` — current OAuth scope / 'logout'
- `state` — CSRF token for OAuth state parameter
- `me` — user identity + sign_identities from eParaksts `/me`
- `tokens` — per-scope bearer tokens + expiry
- `digests` — computed digest data (`digest`, `digests_summary`, `algorithm`, `signature_algorithm`)
- `redirectAfter` — URL to redirect to after signing completes
- `callbacks` — registered callback class names
- `batch_sessions` — additional session IDs included in a batch signing round-trip
- `signingCertType` — cert type for `calculateDigest()` (default `CERT_SIGNING`; `CERT_QSEAL` when `qseal()` set)
- `authCertType` — cert type for `finalizeSigning()` (default `CERT_MOBILEID_SIGN`; `CERT_QSEAL` when `qseal()` set)
- `withArchive` — whether to add an LTV archive timestamp after signing

---

## Installation

```bash
composer require dencel/laravel-eparaksts
php artisan eparaksts:install   # publishes config + migrations
php artisan migrate
```

Add to `.env`:
```
EPARAKSTS_USERNAME=your-client-id
EPARAKSTS_PASSWORD=your-client-secret
EPARAKSTS_HOST=https://eidas-demo.eparaksts.lv
SIGNAPI_HOST=https://signapi-prep.eparaksts.lv
```

The package auto-discovers the service provider and `Eparaksts` facade alias.

---

## Running tests

```bash
composer test       # phpunit
composer lint       # php-cs-fixer dry-run
composer fix        # php-cs-fixer apply
composer analyse    # phpstan
```
