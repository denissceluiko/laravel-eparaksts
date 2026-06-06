---
name: laravel-eparaksts
description: "Apply this skill whenever writing, reviewing, or refactoring code in the dencel/laravel-eparaksts package. This includes editing the signing service, OAuth callback controller, session storage, lifecycle callbacks, service provider bindings, middleware, routes, and config. Triggers for the full eParaksts signing flow (upload → sign → download), identification and signing-identity OAuth flows, logout flow, digest calculation, finalizeSigning, HasCallbacks hook dispatch, and SessionStorage serialization. Also use when debugging API response parsing, certificate type selection (CERT_SIGNING vs CERT_MOBILEID_SIGN), or base library (dencel/eparaksts ^0.3) integration issues."
license: MIT
metadata:
  author: Deniss Celuiko
---

# /laravel-eparaksts skill

Gives Claude context for working on `dencel/laravel-eparaksts` — the Laravel service-provider wrapper around `dencel/eparaksts` (^0.3).

## Quick orientation

- **Working directory**: `/var/www/laravel-eparaksts/`
- **Base library docs**: `vendor/dencel/eparaksts/CLAUDE.md` — authoritative for all raw API calls
- **This package's docs**: `/var/www/laravel-eparaksts/CLAUDE.md`

## Key files

| File | Purpose |
|---|---|
| `src/Services/Eparaksts.php` | Fluent service: upload → sign → download lifecycle |
| `src/Controllers/EparakstsController.php` | OAuth callbacks + flow entrypoints |
| `src/Services/SessionStorage.php` | Session wrapper (base64 JSON blob) |
| `src/Concerns/HasCallbacks.php` | before*/after* hook registration via __call |
| `src/Callbacks/Callback.php` | Abstract base for signing lifecycle hooks (void return) |
| `src/Callbacks/IdentificationCallback.php` | Abstract base for identification hook — `handle(): ?RedirectResponse`; non-null response bypasses default login logic |
| `src/EparakstsServiceProvider.php` | DI bindings + middleware + routes |
| `src/Middleware/HandlesSessionStorage.php` | Load/save session blob on every request |
| `config/eparaksts.php` | All config keys |
| `routes/web.php` | Package routes (prefix: ep, callback: /eparaksts/callback) |

## IoC bindings

- `eparaksts-connector` → `Dencel\Eparaksts\Eparaksts` (singleton) — OAuth/identity
- `eparaksts-signapi` → `Dencel\Eparaksts\SignAPI\v1\SignAPI` (singleton) — document signing
- `ep-session` → `Services\SessionStorage` (singleton) — session state
- `eparaksts` → `Services\Eparaksts` (bind, fresh each resolve) — main service
- Facade `Eparaksts` → `eparaksts` binding

## Signing flow (high-level)

1. `Eparaksts::upload($paths)->redirectAfter($url)->sign()` — upload files, redirect to `/ep/sign/{id}`
2. `signFlow()` — checks `epsession()->me()` (not token state) for identification; checks `epsession()->signIdentities()` for signing identity; calculates digest → redirects to eParaksts for SCOPE_SIGNATURE
3. eParaksts redirects back → `redirect()` callback dispatches to `finalizeSigning()`
4. `finalizeSigning()` — signs digest server-side, calls `signing()->finalizeSigning()`, redirects to `redirectAfter` URL
5. App downloads signed file with `Eparaksts::session($id)->download($path)`; call `->refreshFiles()` first if inside an `afterSigningFinalized` callback to get the post-signing file list without a full reconnect

**Cancellation:** if the user cancels on the eParaksts side the callback receives `?error=access_denied`. `ep_error` is flashed and the user is redirected to `redirectAfter` — the same page as a successful signing. For identification/identity-flow OAuth errors the fallback is `redirect()->intended()` back toward the sign flow.

## Known response structure (eParaksts API — unchanged between 0.2 and 0.3)

All API responses are wrapped in a `data` key. The 0.3 base library change was only in error handling (throws `ApiException` on non-2xx instead of returning `null`).

- `session()->start()` → `['data' => ['sessionIds' => ['sess-id', ...]]]` or throws `ApiException`
- `storage()->list($id)` → `['data' => [{id, name, size, type}, ...]]` (flat file array) or throws `ApiException`
- `storage()->upload($id, $path, $name)` → `['data' => ['id' => '...', 'name' => '...', ...]]` or throws `ApiException`
- `signing()->calculateDigest(...)` → `['data' => ['sessionDigests' => [['sessionId'=>..., 'digest'=>...]], 'digests_summary'=>..., 'algorithm'=>..., 'signature_algorithm'=>...]]` or throws `ApiException`
- `signing()->finalizeSigning(...)` → `['data' => ['results' => [['sessionId' => '...']]]]` or throws `ApiException`

## Certificate roles in the signing flow

- `calculateDigest()` uses `CERT_SIGNING` — the serverid certificate that will physically sign the document
- `finalizeSigning()` uses `CERT_MOBILEID_SIGN` — the user's Mobile-ID cert proving they authorised the signing action via SCOPE_SIGNATURE OAuth
- These are two *different* certs from two *different* sign identities; both come from `sessionStorage()->signIdentities()`

## Session storage keys

Stored as `{prefix}_ep_storage` (base64 JSON). Fields: `action`, `state`, `me`, `tokens`, `digests`, `redirectAfter`, `callbacks`, `batch_sessions`, `signingCertType`, `authCertType`, `withArchive`.

Separate Laravel session key (not in the blob): `{prefix}_signing_{oauthState}` — maps the SCOPE_SIGNATURE OAuth state token to the SignAPI session ID. Written in `signFlow()`, consumed via `pull()` in both the success and error callbacks. Keyed by state so concurrent signing flows in the same browser session don't collide.

## API docs

- Full developer docs: https://developers.eparaksts.lv/
- LLM-friendly index of all pages: https://developers.eparaksts.lv/llms.txt  
  Fetch individual pages from this index when you need exact request/response schemas (e.g. `signing-api.md`, `storage-api.md`, `session-api.md`).

## Running

```bash
composer test      # PHPUnit
composer lint      # PHP-CS-Fixer dry-run
composer fix       # PHP-CS-Fixer apply
composer analyse   # PHPStan
composer rector    # deprecation/upgrade check dry-run (PHP 8.5 + Laravel 13 targets)
```
