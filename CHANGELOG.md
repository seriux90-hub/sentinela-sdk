# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and versioning
follows [SemVer](https://semver.org/).

## [0.4.0] - 2026-10-01

### Changed (breaking)
- The product was renamed from Sentinela to **Nocwise**, and every public
  identifier of the package follows the new name. Upgrading from 0.3 means
  replacing the package and updating your `.env` and config:

  | Before (0.3) | Now (0.4) |
  | --- | --- |
  | `sentinela/laravel-client` | `nocwise/laravel-client` |
  | `Sentinela\LaravelClient\…` | `Nocwise\LaravelClient\…` |
  | `config/sentinela.php` (tag `sentinela-config`) | `config/nocwise.php` (tag `nocwise-config`) |
  | `SENTINELA_*` env variables | `NOCWISE_*` env variables |
  | `sentinela` log channel in the README examples | `nocwise` log channel |
  | `php artisan sentinela:test` | `php artisan nocwise:test` |
  | `X-Sentinela-Signature` / `-Timestamp` / `-Nonce` headers | `X-Nocwise-Signature` / `-Timestamp` / `-Nonce` |

- The signature headers changed name, so 0.4 only works against a Nocwise
  server that expects `X-Nocwise-*`. A Sentinela server rejects its requests.

## [0.3.0] - 2026-09-26

### Added
- Laravel 13 support: `illuminate/support` and `illuminate/contracts` now
  accept `^13.0`. Previously the package could not be installed at all in a
  fresh `laravel/laravel` app, which ships with Laravel 13.
- `guzzlehttp/guzzle` now accepts `^8.0` as well: new Laravel 13 apps ship
  with Guzzle 8, which also blocked installation. The package only uses
  Laravel's `Http` facade, never Guzzle directly.
- `Sentinela::captureBatch(array $events)`: sends many events in a single
  request to `POST /api/logs/batch`, signed like `capture()`. Splits into
  chunks of 500 (the server limit), applies sampling and PII scrubbing per
  event, drops events missing `level`/`message`, and keeps the same
  never-throw / `dry_run` / circuit-breaker guarantees.

### Changed
- CI now also runs against Laravel 13 (PHP 8.3).

## [0.2.0] - 2026-08-19

### Added
- Circuit breaker: after a network failure, the client stops trying to reach
  Sentinela for `SENTINELA_CIRCUIT_BREAKER_SECONDS` (default 30s, `0`
  disables it) instead of paying the full timeout on every subsequent log
  while Sentinela is down. Uses the app's default cache store, and never
  throws even if that cache driver itself is unavailable.

### Fixed
- `SentinelaLogChannelFactory` could crash the app's entire logging
  pipeline if `min_level`/the channel's `level` config was set with
  different casing than Monolog's enum case names (e.g. `"ERROR"` instead
  of `"error"`), or to an invalid value — `Level::fromName()` throws
  `UnhandledMatchError` in both cases, which wasn't being caught. Now
  normalizes the casing and falls back to `Error` on any invalid value.
- `send()` no longer POSTs the literal string `"false"` when a payload
  can't be JSON-encoded (e.g. invalid UTF-8, a non-serializable object in
  the context array) — the event is now dropped and logged in debug mode
  instead.

### Changed
- Default `SENTINELA_RETRIES` is now `0` (was `1`). `capture()` usually
  runs synchronously in the middle of a real request (e.g. right when an
  exception is reported); each retry adds a full `timeout` +
  `retry_backoff_ms` of blocking wait to that response. Raise it explicitly
  if you're fine with that cost.

### Documentation
- README translated to English.
- Documented the double-report gotcha when both the `sentinela` log channel
  and automatic exception forwarding are enabled at the same time.
- Documented that `Throwable::getTraceAsString()` can include fragments of
  scalar argument values, which aren't covered by `scrub_keys`-based
  redaction (only `scrub_value_patterns` catches those).

## [0.1.0] - 2026-08-19

First release.

### Added
- `SentinelaClient`: captures and sends events to `POST /api/logs`,
  fail-safe (never throws), with retries on network failure and a
  configurable timeout.
- Laravel logging channel (`SentinelaLogChannelFactory` + Monolog handler).
- Automatic capture of unhandled exceptions via the `MessageLogged` event,
  plus an explicit `Sentinela::reportException()` method.
- HMAC-SHA256 payload signing with timestamp + anti-replay nonce
  (`X-Sentinela-Signature`, `X-Sentinela-Timestamp`, `X-Sentinela-Nonce`).
- Configurable PII scrubbing (keys + value patterns), sampling, debug and
  dry-run modes.
- `sentinela:test` command.
- Publishable config (`sentinela-config`), no-op if unconfigured.
