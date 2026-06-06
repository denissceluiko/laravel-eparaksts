# Changelog

## [0.4.4] — 2026-06-06

### Added
- **`refreshFiles()`** — lightweight re-list of files from SignAPI after `finalizeSigning()`, without re-running the full `session()` flow. Call it inside an `afterSigningFinalized` callback to get the signed file IDs without a reconnect penalty. Returns `$this` for chaining; logs a warning and no-ops if no session is established.

### Bug fixes
- **OAuth callback redirect loop** — `requestToken()` now includes `redirect_uri` in the token exchange request. RFC 6749 requires this parameter to match the one used in the authorization request; omitting it caused eParaksts to reject the token exchange, leaving the connector without a bearer token and sending `me()` into a loop back to identification.
- **Silent loop on token request failure** — when `requestToken()` returns `false` (any non-200 from the token endpoint), the callback now flashes `ep_error: token_request_failed` and redirects to `redirects.error` instead of falling through to `callbackIdentification()` with no bearer.
- **Identification / signing-identity loop in `signFlow()`** — replaced `isAuthenticated(SCOPE_IDENTIFICATION)` and `isAuthenticated(SCOPE_SIGNING_IDENTITY)` with session-data checks (`epsession()->me()` and `epsession()->signIdentities()`). The token-based check failed silently for age-gated identification (token stored under `SCOPE_IDENTIFICATION_WITH_AGE_*`, checked under `SCOPE_IDENTIFICATION`) and was susceptible to any token-loading race. The session `me` data is set unconditionally by the callback before any login logic runs and persists reliably across requests.
- **`TypeError` on non-string callbacks** — `HasCallbacks::push()` now accepts `mixed` and silently ignores non-string values (e.g. `[ClassName::class, 'method']`) rather than crashing with a PHP `TypeError`. Callbacks must still be class name strings extending `Callback` or `IdentificationCallback`.

## [0.4.1] — 2026-06-06

### Breaking changes
- **Laravel 11 is no longer supported.** Minimum supported version is now Laravel 12. Dependency resolution failures with `orchestra/testbench` ^9.x under PHP 8.5 make this combination untestable; Laravel 11 security support ends late 2026 in any case.

## [0.4.0] — 2026-06-06

### Breaking changes
- `HasCallbacks::push()` now only accepts `string $callback` (class name). Passing an object instance throws `TypeError`. Previously instances were `serialize()`d / `unserialize()`d — this is now removed entirely to eliminate PHP deserialization risk. Update any code that passed callback instances to pass the class name string instead.
- `config('eparaksts.redirects.signing_complete')` is a new required config key. Publishing the config (`php artisan vendor:publish --tag=eparaksts-config`) and running `php artisan eparaksts:install` will add it. Applications that manually copy the config file need to add `'signing_complete' => '/'` under the `redirects` array.

### Added
- **Batch signing** — `->batch([$sessionB, ...])` includes additional already-uploaded sessions in a single OAuth authorization round-trip; `signBatch()` signs all digests at once; `finalizeSigning()` finalizes all sessions
- **eZīmogs+ / qualified eSeal** — `->qseal()` switches both signing and auth cert types to `CERT_QSEAL` for the full signing flow
- **LTV archive timestamp** — `->withArchive()` adds a long-term validation timestamp after signing completes; failure is non-fatal (logged, signing still succeeds)
- **Age-gated identification** — `?age=14|16|18|21` on the `/ep/auth` route maps to the corresponding `SCOPE_IDENTIFICATION_WITH_AGE_*` OAuth scope
- **File validation** — `getFileValidation(?string $fileId)` calls the SignAPI validate endpoint and returns the raw result; intended for use after `finalizeSigning()` completes
- **Laravel Events** — `DocumentSigned(sessionId, batchSessionIds[])`, `UserIdentified(user, identity)`, `SigningFailed(sessionId, reason)` dispatched at the appropriate flow points; use queued listeners for async side-effects
- **Auto-close session after download** — `download()` calls `close()` on the SignAPI session after a successful save; pass `keep: true` to keep the session open (e.g. when downloading multiple files)
- **Laravel logger integration** — internal errors and warnings are forwarded to `Log::error/warning/info('[eparaksts] ...')`. Set `EPARAKSTS_LOGGING=false` in `.env` to suppress (e.g. if raw API error responses in logs are a concern)
- **`redirects.signing_complete` config key** — explicit fallback when `redirectAfter()` was never set and signing completes
- **Rector** — `composer rector` (dry-run) / `composer rector:fix` for deprecation and upgrade checks; configured to target PHP 8.5 + Laravel 13 (`LARAVEL_130_WITHOUT_ATTRIBUTES`)
- **Laravel 12 + 13 support** — illuminate constraint is now `^12.0 || ^13.0`; PHP `^8.4` already covers 8.5
- CI matrix expanded: PHP 8.5, Laravel 13 (testbench 11.x); `fail-fast: false`; install step now correctly pins testbench to exercise each Laravel version rather than running the lockfile

### Robustness
- `signFlow()` flushes the `SCOPE_SIGNATURE` token before every OAuth redirect so retry flows always re-authorize rather than potentially reusing a stale token
- `finalizeSigning()` controller redirects back to `signFlow` if the token is absent from the session (covers the case where the user navigates back to the callback URL directly)
- `redirectAfter` fallback — `finalizeSigning()` falls back to `config('eparaksts.redirects.signing_complete', '/')` instead of calling `redirect()->to(null)`

### Tests
- Added coverage for `download()` (happy path + auto-close, `keep: true`), `close()`, `getFileValidation()`, `onIdentificationReceived` returning a non-null response, and the `registration_enabled` path (SQLite in-memory)

## [0.3.0] — 2026-06-05

### Breaking changes
- Requires PHP ≥ 8.4 (up from 8.2)
- Requires `dencel/eparaksts` ^0.3 (up from ^0.2)
- `dencel/eparaksts` 0.3 now throws `ApiException` on non-2xx responses (was returning `null`); `calculateDigest()` and `finalizeSigning()` now catch and log these exceptions
- The eParaksts API response structure is **unchanged** — all responses remain wrapped in a `data` key; snake_case field names (`data.sessionIds`, `data.sessionDigests[0].digest`, `data.digests_summary`, `data.algorithm`) are the same as in 0.2

### Bug fixes
- `finalizeSigning()`: cert type `CERT_MOBILEID_SIGN` is correct and unchanged — it is the user's Mobile-ID cert proving they authorised the signing via SCOPE_SIGNATURE, distinct from the `CERT_SIGNING` serverid cert used in `calculateDigest()`
- `signAs()`: returned `false` even on success — now correctly returns `true`
- `canSignAs()`: replaced non-existent `array_first()` with `Arr::first()`
- `addFile()`: `Storage::disk()` was called without the disk name — now `Storage::disk($this->disk)`
- `calculateDigest()`: referenced non-existent property `$this->createNewEdoc` — now uses `$this->newContainer`
- `logoutFlow()`: called `$this->redirect($url)` (the OAuth callback handler) instead of `redirect($url)` (Laravel helper) — caused a fatal type error
- Logout flow redesigned: session is flushed in the callback after eParaksts redirects back, not before the redirect; no more state-mismatch abort on logout return

### Implemented
- `callbackDefault()`: redirects to `redirect()->intended('/')`
- `callbackError()`: flashes `ep_error` and `ep_error_description` from OAuth error params, then redirects
- `register()`: creates a new user from the mapped identity fields and logs them in

### Added
- PHPStan static analysis configuration
- PHP-CS-Fixer code style configuration
- GitHub Actions CI workflow
- PHPUnit test suite (Orchestra Testbench)
- Composer scripts: `test`, `fix`, `lint`, `analyse`
- `CLAUDE.md` — architecture and usage documentation
- `README.md` — public documentation
- `LICENSE` — MIT

## [0.2.x] — prior releases

Initial implementation of the signing and identification flows.
