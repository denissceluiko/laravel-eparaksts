# laravel-eparaksts

Laravel package for integrating the Latvian **eParaksts** e-signature and identity platform into your Laravel 12/13 application.

Wraps [dencel/eparaksts](https://github.com/denissceluiko/eparaksts) and provides:
- Fluent document-signing service with per-request session state
- OAuth identification and authentication flow
- Lifecycle callback hooks
- Blade logo components

## Requirements

- PHP ≥ 8.4
- Laravel 12 or 13
- eParaksts API credentials ([test environment](https://www.eparaksts.lv/en/for_developers/Application_test_environment))

## Installation

```bash
composer require dencel/laravel-eparaksts
php artisan eparaksts:install
php artisan migrate
```

Add to `.env`:

```
EPARAKSTS_USERNAME=your-client-id
EPARAKSTS_PASSWORD=your-client-secret
EPARAKSTS_HOST=https://eidas-demo.eparaksts.lv
SIGNAPI_HOST=https://signapi-prep.eparaksts.lv
EPARAKSTS_REDIRECT=/eparaksts/callback
```

`EPARAKSTS_REDIRECT` must exactly match the callback URI registered for your client in the eParaksts developer portal. The default `/eparaksts/callback` is pre-registered for the test and/or production environment.

For production:

```
EPARAKSTS_HOST=https://eidas.eparaksts.lv
SIGNAPI_HOST=https://signapi.eparaksts.lv
```

## Quick start

### Document signing

```php
use Dencel\LaravelEparaksts\Facades\Eparaksts;

// In your controller — upload file(s) and redirect the user into the signing flow.
return Eparaksts::upload('/path/to/document.pdf')
    ->redirectAfter(route('documents.show', $doc))
    ->sign();
```

After signing completes the user is redirected back to your `redirectAfter` URL. Download the signed document:

```php
$path = Eparaksts::session($sessionId)
    ->download(storage_path('signed/'));
```

Or save to a named disk:

```php
$path = Eparaksts::session($sessionId)
    ->disk('s3')
    ->download('signed/', name: 'contract-signed.pdf');
```

### Identity verification / login

Point a login button to:

```blade
<a href="{{ route('eparaksts.identification', ['flow' => 'mobile']) }}">
    Log in with eParaksts Mobile
</a>
```

Available `flow` values: `mobile`, `eid`, `sc`, `cross`.

On success the user is matched against the `authentication_match` fields in `config/eparaksts.php` and logged in via `Auth::login()`. Flash variable `ep_error` is set to `user_not_found` if no match.

Set `registration_enabled => true` in config to auto-create users on first login.

### Logout

```blade
<a href="{{ route('eparaksts.logout') }}">Log out</a>
```

This redirects through eParaksts logout, then flushes the local session.

## Lifecycle callbacks

Hook into any point of the signing flow:

```php
use Dencel\LaravelEparaksts\Callbacks\Callback;

class AuditSigningComplete extends Callback
{
    public function handle(): void
    {
        AuditLog::create([
            'session' => $this->eparaksts->getSession(),
            'user_id' => auth()->id(),
        ]);
    }
}
```

Register on the service:

```php
Eparaksts::upload($path)
    ->afterSigningFinalized(AuditSigningComplete::class)
    ->redirectAfter(route('done'))
    ->sign();
```

Available hooks: `before/afterSignFlowSessionEstablished`, `before/afterIdentificationObtained`, `before/afterSigningIdentityObtained`, `afterDigestCalculated`, `beforeSignatureAuthorizationRedirect`, `before/afterSigningDigest`, `afterSigningFinalized`, `beforeFinalRedirect`, `onIdentificationReceived`, `onError`.

## Container format

By default documents are wrapped in an `.edoc` container. Override:

```php
Eparaksts::upload($path)->edoc()->sign();    // .edoc (default)
Eparaksts::upload($path)->asice()->sign();   // .asice
Eparaksts::upload($path)->pdf()->sign();     // PAdES (single PDF only)
```

## Advanced signing

### eZīmogs+ / qualified eSeal

For server-side signing with a qualified certificate (`CERT_QSEAL`):

```php
Eparaksts::upload($path)->qseal()->sign();
```

### Archive timestamp (LTV)

Add a long-term validation (LTV) archive timestamp after signing:

```php
Eparaksts::upload($path)->withArchive()->sign();
```

Timestamp failure is non-fatal — signing completes, the error is logged internally.

### Batch signing

Sign documents from multiple sessions in a single OAuth authorization. Each session must already have files uploaded.

```php
// Upload to separate sessions first.
$sessionA = Eparaksts::upload($pathA)->getSession();
$sessionB = Eparaksts::upload($pathB)->getSession();

// Redirect the user into the signing flow for both sessions at once.
return Eparaksts::session($sessionA)
    ->batch([$sessionB])
    ->redirectAfter(route('documents.index'))
    ->sign();
```

After signing, download each session's file independently:

```php
Eparaksts::session($sessionA)->download(storage_path('signed/'));
Eparaksts::session($sessionB)->download(storage_path('signed/'));
```

### File validation

Validate a signed file's signatures after `finalizeSigning()` completes, while still attached to the session:

```php
$validation = Eparaksts::session($sessionId)->getFileValidation();
// or for a specific file:
$validation = Eparaksts::session($sessionId)->getFileValidation($fileId);
```

Returns the raw validation response from the SignAPI, or `null` if the session or file ID is missing. The shape and interpretation of the result is application-specific — there is no automatic pass/fail handling.

### Age-gated identification

Require users to confirm they meet a minimum age during the identification flow:

```blade
<a href="{{ route('eparaksts.identification', ['age' => 18]) }}">
    Log in (18+ only)
</a>
```

Supported values: `14`, `16`, `18`, `21`. Omitting `age` performs standard identification with no age assertion.

## Blade components

```blade
<x-eparaksts::logo />                        {{-- eParaksts logo --}}
<x-eparaksts::logo type="mobile" />          {{-- eParaksts Mobile logo --}}
```

Available logo types: `eparaksts`, `mobile`, `mobile-full`, `mobile-small`, `eid`, `eid-scan`, `eid-scan-small`, `karte`, `ezimogs`, `api`.

## Configuration

Publish the config:

```bash
php artisan vendor:publish --tag=eparaksts-config
```

See `config/eparaksts.php` for all options with inline documentation.

## Development

```bash
composer test      # run PHPUnit tests
composer lint      # check code style (dry-run)
composer fix       # apply code style fixes
composer analyse   # run PHPStan
composer rector    # check for deprecations (dry-run)
```

## License

MIT — see [LICENSE](LICENSE).
