# Nocwise — Official Laravel Client

[![Tests](https://github.com/seriux90-hub/nocwise-sdk/actions/workflows/tests.yml/badge.svg)](https://github.com/seriux90-hub/nocwise-sdk/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/nocwise/laravel-client.svg)](https://packagist.org/packages/nocwise/laravel-client)
[![License](https://img.shields.io/packagist/l/nocwise/laravel-client.svg)](LICENSE)

Captures logs and unhandled exceptions from your Laravel app and sends them
to your [Nocwise](https://github.com/seriux90-hub/nocwise) instance.
Built to never break or slow down your app: if Nocwise doesn't respond,
your application doesn't even notice.

## Installation

```bash
composer require nocwise/laravel-client
```

Publish the config:

```bash
php artisan vendor:publish --tag=nocwise-config
```

Add to your `.env`:

```
NOCWISE_KEY=your-project-api-key
NOCWISE_URL=https://your-nocwise-instance.com
NOCWISE_SIGNING_SECRET=your-signing-secret
```

You'll find `NOCWISE_KEY` and `NOCWISE_SIGNING_SECRET` on your Nocwise
project, under **Integrations**. `NOCWISE_URL` is the URL of your instance
(each Nocwise client has their own, which is why it's configurable).

Check that everything works:

```bash
php artisan nocwise:test
```

## Usage

### Manual capture

```php
use Nocwise\LaravelClient\Facades\Nocwise;

Nocwise::capture('warning', 'Low stock', ['product_id' => 42, 'stock' => 3]);
```

### Batch capture

When you have many events at once (imports, queued jobs, high-volume apps),
send them in a single request to `POST /api/logs/batch` instead of one HTTP
call per event:

```php
Nocwise::captureBatch([
    ['level' => 'info', 'message' => 'Import started'],
    ['level' => 'warning', 'message' => 'Row skipped', 'context' => ['row' => 17]],
]);
```

Same guarantees as `capture()`: it never throws, and it honours `dry_run`,
the circuit breaker and PII scrubbing. Sampling is applied per event.
Batches larger than 500 events (the server limit) are split automatically,
and events missing `level` or `message` are dropped.

### Unhandled exceptions — automatic

By default, **you don't have to do anything**: the package listens to the
`Illuminate\Log\Events\MessageLogged` event and forwards any exception that
Laravel reports the standard way (which is exactly what `report()` does when
there's no custom reporter for that exception).

If you'd rather disable automatic forwarding and capture explicitly yourself
(for example, to filter which exceptions actually get sent), set
`NOCWISE_REPORT_EXCEPTIONS=false` and hook in manually in
`bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Exceptions;
use Nocwise\LaravelClient\Facades\Nocwise;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (Throwable $e) {
        Nocwise::reportException($e);
    });
})
```

### As a logging channel

Add the channel to `config/logging.php`:

```php
'channels' => [
    'nocwise' => [
        'driver' => 'custom',
        'via' => \Nocwise\LaravelClient\Logging\NocwiseLogChannelFactory::class,
        'level' => 'error',
    ],
],
```

And add it to your stack (`LOG_STACK=stack,nocwise` in `.env`, or directly
in the config) so any `Log::error(...)` in your app also reaches Nocwise.

## Configuration

All options live in `config/nocwise.php` once published. The most relevant
ones:

| Variable | Default | What it does |
|---|---|---|
| `NOCWISE_ENABLED` | `true` | Turns the package off entirely (no-op) without uninstalling it |
| `NOCWISE_MIN_LEVEL` | `error` | Minimum level captured by the logging channel |
| `NOCWISE_SAMPLE_RATE` | `1.0` | Fraction of events actually sent (0.0–1.0), to avoid burning through your quota on very noisy apps |
| `NOCWISE_DEBUG` | `false` | Logs what the client is doing to your local log |
| `NOCWISE_DRY_RUN` | `false` | Builds the payload but doesn't send it — for testing the config without spending quota |
| `NOCWISE_TIMEOUT` / `NOCWISE_RETRIES` | `2.0` / `0` | Per-request timeout and retries on network failure (never on a 4xx: the server has already decided) |
| `NOCWISE_CIRCUIT_BREAKER_SECONDS` | `30` | After a network failure, stop trying to reach Nocwise for this many seconds — avoids adding a full timeout to every single log call while Nocwise is down. `0` disables it |

The package is a **no-op** if `NOCWISE_KEY` or `NOCWISE_URL` is missing,
or if `NOCWISE_ENABLED=false` — it never throws or blocks your app.

`capture()` typically runs synchronously in the middle of a real request
(e.g. right when an exception is reported), so retries default to `0`: each
retry adds a full `timeout` + `retry_backoff_ms` of *blocking* wait to that
response. Only raise it if you're fine with that cost, or if you call
Nocwise from a queued job instead. Either way, after a network failure the
circuit breaker keeps the client from retrying on every subsequent log for
`NOCWISE_CIRCUIT_BREAKER_SECONDS` — an outage on the Nocwise side won't
slow your app down beyond the first failed attempt.

> ⚠️ If you both add the `nocwise` channel to your `LOG_STACK` **and**
> leave automatic exception forwarding enabled (`NOCWISE_REPORT_EXCEPTIONS`,
> on by default), an unhandled exception will be reported **twice** — once
> by the log channel handler, once by the `MessageLogged` listener. Pick one:
> either use the log channel and set `NOCWISE_REPORT_EXCEPTIONS=false`, or
> keep automatic forwarding and don't add `nocwise` to the stack.

### Privacy — PII scrubbing

Before sending any event, context keys matching `scrub_keys` in
`config/nocwise.php` (`password`, `token`, `api_key`, `credit_card`... —
editable list) are redacted (`[redacted]`), recursively through nested
arrays. You can also define `scrub_value_patterns` (regex) to redact values
that look like card numbers or emails even when the key doesn't give it away.

```php
// config/nocwise.php
'scrub_keys' => ['password', 'token', 'ssn', /* ... */],
```

## Security

- **HTTPS only.** Never expose `NOCWISE_KEY` or `NOCWISE_SIGNING_SECRET`
  in the frontend — this package runs server-side.
- Every request includes the project's API key (`X-API-Key`) and, if you've
  configured `NOCWISE_SIGNING_SECRET`, an **HMAC-SHA256 signature** of the
  body (`X-Nocwise-Signature`) along with a timestamp and a nonce
  (`X-Nocwise-Timestamp`, `X-Nocwise-Nonce`) that the server uses to
  reject old timestamps and repeated nonces (anti-replay).
- **Important:** the signature provides message *integrity* and anti-replay
  protection — it does **not** authorize the request on its own. This
  package is public and open source, so anyone can read exactly how it
  signs. The real decision to accept or reject ingestion (active
  subscription, plan quota, paused project...) is **always made by the
  server**. A leaked secret lets someone forge a valid signature, but it
  doesn't bypass that check — that's the Nocwise instance's job.
- **Stack traces can contain argument values.** PHP's
  `Throwable::getTraceAsString()` includes up to 15 characters of each
  scalar argument passed to the functions in the trace — if a password or
  token was passed as a plain argument somewhere in the call stack, a
  fragment of it can end up in the reported `trace`. `scrub_key`-based
  redaction only matches array *keys*, which a raw trace string doesn't
  have; if this is a concern for your app, configure
  `scrub_value_patterns` in `config/nocwise.php` to redact matching
  substrings in every string value sent, trace included.

## Requirements

PHP 8.2+ and Laravel 11, 12 or 13 (Laravel 13 itself requires PHP 8.3+).

## Tests

```bash
composer install
vendor/bin/phpunit
```

## License

MIT. See [LICENSE](LICENSE).
