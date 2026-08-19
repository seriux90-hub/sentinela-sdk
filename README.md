# Sentinela — Cliente oficial para Laravel

[![Tests](https://github.com/seriux90-hub/sentinela-sdk/actions/workflows/tests.yml/badge.svg)](https://github.com/seriux90-hub/sentinela-sdk/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/sentinela/laravel-client.svg)](https://packagist.org/packages/sentinela/laravel-client)
[![License](https://img.shields.io/packagist/l/sentinela/laravel-client.svg)](LICENSE)

Captura logs y excepciones no controladas de tu app Laravel y los envía a tu
instancia de [Sentinela](https://github.com/seriux90-hub/sentinela). Pensado
para no romper ni ralentizar tu app nunca: si Sentinela no responde, tu
aplicación ni se entera.

## Instalación

```bash
composer require sentinela/laravel-client
```

Publica la configuración:

```bash
php artisan vendor:publish --tag=sentinela-config
```

Añade a tu `.env`:

```
SENTINELA_KEY=tu-api-key-del-proyecto
SENTINELA_URL=https://tu-instancia-de-sentinela.com
SENTINELA_SIGNING_SECRET=tu-secreto-de-firma
```

`SENTINELA_KEY` y `SENTINELA_SIGNING_SECRET` los encuentras en tu proyecto de
Sentinela, en **Integraciones**. `SENTINELA_URL` es la URL de tu instancia
(cada cliente de Sentinela tiene la suya, por eso es configurable).

Comprueba que todo funciona:

```bash
php artisan sentinela:test
```

## Uso

### Captura manual

```php
use Sentinela\LaravelClient\Facades\Sentinela;

Sentinela::capture('warning', 'Stock bajo', ['product_id' => 42, 'stock' => 3]);
```

### Excepciones no controladas — automático

Por defecto, **no tienes que hacer nada**: el paquete escucha el evento
`Illuminate\Log\Events\MessageLogged` y reenvía cualquier excepción que
Laravel reporte de forma estándar (que es exactamente lo que hace
`report()` cuando no hay un reporter custom para esa excepción).

Si prefieres desactivar el envío automático y capturar tú explícitamente
(por ejemplo, para filtrar qué excepciones sí quieres mandar), pon
`SENTINELA_REPORT_EXCEPTIONS=false` y engánchate a mano en
`bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Exceptions;
use Sentinela\LaravelClient\Facades\Sentinela;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (Throwable $e) {
        Sentinela::reportException($e);
    });
})
```

### Como canal de logging

Añade el canal a `config/logging.php`:

```php
'channels' => [
    'sentinela' => [
        'driver' => 'custom',
        'via' => \Sentinela\LaravelClient\Logging\SentinelaLogChannelFactory::class,
        'level' => 'error',
    ],
],
```

Y súmalo a tu stack (`LOG_STACK=stack,sentinela` en `.env`, o directamente en
la config) para que cualquier `Log::error(...)` de tu app también llegue a
Sentinela.

## Configuración

Todas las opciones viven en `config/sentinela.php` tras publicarlo. Las más
relevantes:

| Variable | Por defecto | Qué hace |
|---|---|---|
| `SENTINELA_ENABLED` | `true` | Apaga el paquete por completo (no-op) sin desinstalarlo |
| `SENTINELA_MIN_LEVEL` | `error` | Nivel mínimo capturado por el canal de logging |
| `SENTINELA_SAMPLE_RATE` | `1.0` | Fracción de eventos realmente enviados (0.0–1.0), para no gastar cuota en apps muy ruidosas |
| `SENTINELA_DEBUG` | `false` | Registra en tu log local qué está haciendo el cliente |
| `SENTINELA_DRY_RUN` | `false` | Arma el payload pero no lo envía — para probar la config sin gastar cuota |
| `SENTINELA_TIMEOUT` / `SENTINELA_RETRIES` | `2.0` / `1` | Timeout por petición y reintentos ante fallo de red (nunca ante un 4xx: eso ya lo ha decidido el servidor) |

El paquete es **no-op** si falta `SENTINELA_KEY` o `SENTINELA_URL`, o si
`SENTINELA_ENABLED=false` — nunca lanza ni bloquea tu app.

### Privacidad — PII scrubbing

Antes de enviar cualquier evento, se redactan (`[redacted]`) las claves del
contexto que coincidan con `scrub_keys` en `config/sentinela.php`
(`password`, `token`, `api_key`, `credit_card`... — lista editable), de
forma recursiva en arrays anidados. También puedes definir
`scrub_value_patterns` (regex) para redactar valores que parezcan tarjetas o
emails aunque la clave no lo delate.

```php
// config/sentinela.php
'scrub_keys' => ['password', 'token', 'dni', /* ... */],
```

## Seguridad

- **Solo HTTPS.** Nunca expongas `SENTINELA_KEY` ni `SENTINELA_SIGNING_SECRET`
  en el frontend — este paquete corre en el servidor.
- Cada envío incluye la API key del proyecto (`X-API-Key`) y, si has
  configurado `SENTINELA_SIGNING_SECRET`, una **firma HMAC-SHA256** del
  cuerpo (`X-Sentinela-Signature`) junto con un timestamp y un nonce
  (`X-Sentinela-Timestamp`, `X-Sentinela-Nonce`) que el servidor usa para
  rechazar timestamps antiguos y nonces repetidos (anti-replay).
- **Importante:** la firma da *integridad* del mensaje y protección
  anti-replay, **no autoriza por sí sola** el envío — este paquete es
  público y de código abierto, así que cualquiera puede leer cómo firma.
  La decisión real de aceptar o rechazar la ingesta (suscripción activa,
  cuota del plan, proyecto pausado...) la toma **siempre el servidor**.
  Un secreto filtrado permite falsificar la firma, pero no salta ese
  control — de eso se encarga la propia instancia de Sentinela.

## Requisitos

PHP 8.1+ y Laravel 10, 11 o 12.

## Tests

```bash
composer install
vendor/bin/phpunit
```

## Licencia

MIT. Ver [LICENSE](LICENSE).
