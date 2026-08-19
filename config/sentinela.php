<?php

return [

    /*
    |--------------------------------------------------------------------
    | Activado
    |--------------------------------------------------------------------
    | Si es false, o si falta la api_key/endpoint, el cliente no hace nada
    | (no-op) — nunca debe romper ni ralentizar la app del proyecto.
    */
    'enabled' => env('SENTINELA_ENABLED', true),

    /*
    |--------------------------------------------------------------------
    | API key y endpoint
    |--------------------------------------------------------------------
    | api_key: la de tu proyecto en Sentinela (Integraciones -> API key).
    | endpoint: la URL base de TU instancia de Sentinela (cada cliente
    | puede tener la suya, por eso es configurable y no está hardcodeada).
    */
    'api_key' => env('SENTINELA_KEY'),

    'endpoint' => env('SENTINELA_URL', 'https://sentinela.example.com'),

    /*
    |--------------------------------------------------------------------
    | Secreto para firmar el envío (HMAC-SHA256)
    |--------------------------------------------------------------------
    | Además de la api_key, cada proyecto tiene un secreto de firma que da
    | integridad al payload y protege contra repetición (replay). Se
    | consigue en el mismo sitio que la api_key. No es la autorización en
    | sí (eso lo decide siempre el servidor) — ver el README, sección
    | "Seguridad".
    */
    'signing_secret' => env('SENTINELA_SIGNING_SECRET'),

    /*
    |--------------------------------------------------------------------
    | Entorno reportado
    |--------------------------------------------------------------------
    */
    'environment' => env('SENTINELA_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------
    | Nivel mínimo capturado por el canal de logging
    |--------------------------------------------------------------------
    | Cualquier nivel de Monolog/PSR-3: debug, info, notice, warning,
    | error, critical, alert, emergency.
    */
    'min_level' => env('SENTINELA_MIN_LEVEL', 'error'),

    /*
    |--------------------------------------------------------------------
    | Muestreo (sampling)
    |--------------------------------------------------------------------
    | Fracción de eventos que se envían realmente, entre 0.0 y 1.0. Útil
    | para no saturar la cuota del plan en apps muy ruidosas. 1.0 = todos.
    */
    'sample_rate' => (float) env('SENTINELA_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------
    | Captura de excepciones no controladas
    |--------------------------------------------------------------------
    */
    'report_exceptions' => env('SENTINELA_REPORT_EXCEPTIONS', true),

    /*
    |--------------------------------------------------------------------
    | Envío
    |--------------------------------------------------------------------
    | timeout: segundos de espera máxima por petición HTTP al endpoint.
    | retries: reintentos ante fallo de red/timeout (no ante 4xx: esos no
    | se reintentan, el servidor ya ha decidido). Por defecto 0: capture()
    | suele ejecutarse en mitad de una petición real del usuario (p. ej. al
    | reportar una excepción), así que cada reintento añade timeout+backoff
    | de espera SÍNCRONA a esa respuesta. Sólo súbelo si tienes claro que
    | asumes ese coste (o si llamas a Sentinela desde un job en cola).
    | retry_backoff_ms: espera entre reintentos, en milisegundos.
    */
    'timeout' => (float) env('SENTINELA_TIMEOUT', 2.0),

    'retries' => (int) env('SENTINELA_RETRIES', 0),

    'retry_backoff_ms' => (int) env('SENTINELA_RETRY_BACKOFF_MS', 100),

    /*
    |--------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------
    | Si el envío falla por red (timeout, conexión rechazada...), se deja
    | de intentar durante esta cantidad de segundos — evita que una caída
    | de Sentinela añada el timeout completo a cada log que ocurra mientras
    | tanto. 0 desactiva el circuit breaker (siempre lo intenta). Usa el
    | driver de caché por defecto de la app (config/cache.php).
    */
    'circuit_breaker_seconds' => (int) env('SENTINELA_CIRCUIT_BREAKER_SECONDS', 30),

    /*
    |--------------------------------------------------------------------
    | Modo debug / dry-run
    |--------------------------------------------------------------------
    | En dry-run el cliente arma el payload y lo registra en el log local
    | (canal 'single') en vez de enviarlo — útil para probar la
    | configuración sin gastar cuota ni depender de red.
    */
    'debug' => env('SENTINELA_DEBUG', false),

    'dry_run' => env('SENTINELA_DRY_RUN', false),

    /*
    |--------------------------------------------------------------------
    | PII scrubbing
    |--------------------------------------------------------------------
    | Antes de enviar cualquier evento, se redactan (reemplazan por
    | "[redacted]") las claves del contexto que coincidan (insensible a
    | mayúsculas) con estos nombres o patrones. Aplica de forma recursiva
    | a arrays anidados.
    */
    'scrub_keys' => [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'api_key',
        'authorization',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
    ],

    /*
    |--------------------------------------------------------------------
    | Patrones adicionales de PII (regex, aplicados sobre el VALOR)
    |--------------------------------------------------------------------
    | Por defecto vacío: activar solo si el proyecto quiere redactar
    | también valores que parezcan tarjetas o emails, aunque la clave no
    | coincida con scrub_keys.
    */
    'scrub_value_patterns' => [
        // 'card' => '/\b(?:\d[ -]*?){13,19}\b/',
        // 'email' => '/[\w.+-]+@[\w-]+\.[a-zA-Z]{2,}/',
    ],

];
