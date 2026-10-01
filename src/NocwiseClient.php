<?php

namespace Nocwise\LaravelClient;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Nocwise\LaravelClient\Support\PayloadSigner;
use Nocwise\LaravelClient\Support\PiiScrubber;
use Throwable;

class NocwiseClient
{
    private const CIRCUIT_CACHE_KEY = 'nocwise:circuit-open';

    /** Máximo de eventos que acepta el servidor en una sola petición de lote. */
    public const MAX_BATCH_SIZE = 500;

    public function __construct(
        private ConfigRepository $config,
        private PiiScrubber $scrubber,
        private PayloadSigner $signer,
    ) {
    }

    /**
     * Envía un evento a Nocwise. Nunca lanza — cualquier fallo (red,
     * timeout, respuesta de error) se traga en silencio (o se loguea en
     * modo debug) para no romper ni ralentizar la app del cliente.
     *
     * @param  array<string, mixed>  $context
     */
    public function capture(string $level, string $message, array $context = []): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        if (! $this->passesSample()) {
            return;
        }

        $payload = $this->buildPayload($level, $message, $context);

        if ($this->config->get('nocwise.dry_run', false)) {
            $this->debugLog('dry-run: evento no enviado', $payload);

            return;
        }

        $this->send('/api/logs', $payload);
    }

    /**
     * Envía varios eventos en una sola petición a POST /api/logs/batch (troceando
     * en lotes de MAX_BATCH_SIZE si hace falta). Mismo contrato que capture():
     * nunca lanza, respeta dry_run, circuit breaker y PII scrubbing. El
     * sampling se aplica por evento, igual que si se llamase a capture() uno a
     * uno. Los eventos sin level o message válidos se descartan.
     *
     * @param  array<int, array{level: string, message: string, context?: array<string, mixed>}>  $events
     */
    public function captureBatch(array $events): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $payloads = [];

        foreach ($events as $event) {
            if (! is_array($event)
                || ! is_string($event['level'] ?? null)
                || ! is_string($event['message'] ?? null)) {
                $this->debugLog('evento de lote descartado: falta level o message');

                continue;
            }

            if (! $this->passesSample()) {
                continue;
            }

            $context = $event['context'] ?? [];
            $payloads[] = $this->buildPayload($event['level'], $event['message'], is_array($context) ? $context : []);
        }

        foreach (array_chunk($payloads, self::MAX_BATCH_SIZE) as $chunk) {
            if ($this->config->get('nocwise.dry_run', false)) {
                $this->debugLog('dry-run: lote no enviado', ['count' => count($chunk), 'logs' => $chunk]);

                continue;
            }

            $this->send('/api/logs/batch', ['logs' => $chunk]);
        }
    }

    /**
     * Reporta una excepción no controlada como evento de nivel error, con
     * contexto de clase/archivo/línea y una traza recortada.
     */
    public function reportException(Throwable $e): void
    {
        $this->capture('error', $e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => $this->truncatedTrace($e),
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->config->get('nocwise.enabled', false)
            && ! empty($this->config->get('nocwise.api_key'))
            && ! empty($this->config->get('nocwise.endpoint'));
    }

    /** @param  array<string, mixed>  $context */
    private function buildPayload(string $level, string $message, array $context): array
    {
        return [
            'level' => $level,
            'message' => mb_substr($message, 0, 2000),
            'context' => $this->scrubber->scrub($context),
            'occurred_at' => now()->toIso8601String(),
            'environment' => $this->config->get('nocwise.environment', 'production'),
            'meta' => [
                'hostname' => gethostname() ?: null,
                'sdk' => 'nocwise/laravel-client',
                'sdk_version' => NocwiseServiceProvider::VERSION,
            ],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function send(string $path, array $payload): void
    {
        if ($this->isCircuitOpen()) {
            $this->debugLog('circuito abierto: se omite el envío (fallos de red recientes hacia Nocwise)');

            return;
        }

        $body = json_encode($payload);

        if ($body === false) {
            // Un contexto no serializable (recursos, objetos raros, UTF-8
            // inválido...) no debe intentar enviarse como texto literal
            // "false" — se descarta el evento y se registra en debug.
            $this->debugLog('no se pudo serializar el payload a JSON', ['error' => json_last_error_msg()]);

            return;
        }

        $headers = ['Content-Type' => 'application/json', 'X-API-Key' => $this->config->get('nocwise.api_key')];

        $secret = $this->config->get('nocwise.signing_secret');
        if (! empty($secret)) {
            $headers = array_merge($headers, $this->signer->headersFor($body, $secret));
        }

        $attempts = 1 + max(0, (int) $this->config->get('nocwise.retries', 1));
        $endpoint = rtrim($this->config->get('nocwise.endpoint'), '/').$path;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::withHeaders($headers)
                    ->timeout((float) $this->config->get('nocwise.timeout', 2.0))
                    ->withBody($body, 'application/json')
                    ->post($endpoint);

                if ($response->successful()) {
                    $this->debugLog('evento enviado', ['status' => $response->status()]);

                    return;
                }

                // Un 4xx (api key inválida, cuota superada, rate limit...) es una
                // decisión del servidor: no tiene sentido reintentar el mismo envío.
                $this->debugLog('el servidor rechazó el evento', ['status' => $response->status(), 'body' => $response->body()]);

                return;
            } catch (Throwable $e) {
                $this->debugLog('fallo de red enviando el evento', ['attempt' => $attempt, 'error' => $e->getMessage()]);

                if ($attempt < $attempts) {
                    usleep((int) $this->config->get('nocwise.retry_backoff_ms', 100) * 1000);
                }
            }
        }

        // Todos los intentos fallaron por red (nunca por una respuesta HTTP,
        // esos casos ya han hecho return arriba): Nocwise probablemente
        // esté caída o inalcanzable. Abrimos el circuito para no repetir el
        // timeout completo en cada log que ocurra durante los próximos
        // segundos — evita que una caída de Nocwise ralentice la app.
        $this->openCircuit();
    }

    /**
     * El propio driver de caché de la app (Redis, memcached...) podría estar
     * caído a la vez que Nocwise, o fallar por cualquier otro motivo — el
     * circuit breaker es una optimización, nunca debe ser el motivo por el
     * que capture() incumple su promesa de no lanzar nunca.
     */
    private function isCircuitOpen(): bool
    {
        if ($this->circuitBreakerSeconds() <= 0) {
            return false;
        }

        try {
            return (bool) Cache::get(self::CIRCUIT_CACHE_KEY, false);
        } catch (Throwable) {
            return false;
        }
    }

    private function openCircuit(): void
    {
        $seconds = $this->circuitBreakerSeconds();

        if ($seconds <= 0) {
            return;
        }

        try {
            Cache::put(self::CIRCUIT_CACHE_KEY, true, $seconds);
        } catch (Throwable) {
            // No pasa nada: en el peor caso, el próximo log intenta la
            // conexión de nuevo en vez de aprovechar el circuit breaker.
        }
    }

    private function circuitBreakerSeconds(): int
    {
        return (int) $this->config->get('nocwise.circuit_breaker_seconds', 30);
    }

    private function passesSample(): bool
    {
        $rate = (float) $this->config->get('nocwise.sample_rate', 1.0);

        return $rate >= 1.0 || mt_rand() / mt_getrandmax() < $rate;
    }

    private function truncatedTrace(Throwable $e): string
    {
        return mb_substr($e->getTraceAsString(), 0, 4000);
    }

    private function debugLog(string $message, array $context = []): void
    {
        if ($this->config->get('nocwise.debug', false)) {
            Log::channel('single')->debug("[nocwise] {$message}", $context);
        }
    }
}
