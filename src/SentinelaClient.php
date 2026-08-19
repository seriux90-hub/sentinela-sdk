<?php

namespace Sentinela\LaravelClient;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sentinela\LaravelClient\Support\PayloadSigner;
use Sentinela\LaravelClient\Support\PiiScrubber;
use Throwable;

class SentinelaClient
{
    private const CIRCUIT_CACHE_KEY = 'sentinela:circuit-open';

    public function __construct(
        private ConfigRepository $config,
        private PiiScrubber $scrubber,
        private PayloadSigner $signer,
    ) {
    }

    /**
     * Envía un evento a Sentinela. Nunca lanza — cualquier fallo (red,
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

        if ($this->config->get('sentinela.dry_run', false)) {
            $this->debugLog('dry-run: evento no enviado', $payload);

            return;
        }

        $this->send($payload);
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
        return $this->config->get('sentinela.enabled', false)
            && ! empty($this->config->get('sentinela.api_key'))
            && ! empty($this->config->get('sentinela.endpoint'));
    }

    /** @param  array<string, mixed>  $context */
    private function buildPayload(string $level, string $message, array $context): array
    {
        return [
            'level' => $level,
            'message' => mb_substr($message, 0, 2000),
            'context' => $this->scrubber->scrub($context),
            'occurred_at' => now()->toIso8601String(),
            'environment' => $this->config->get('sentinela.environment', 'production'),
            'meta' => [
                'hostname' => gethostname() ?: null,
                'sdk' => 'sentinela/laravel-client',
                'sdk_version' => SentinelaServiceProvider::VERSION,
            ],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function send(array $payload): void
    {
        if ($this->isCircuitOpen()) {
            $this->debugLog('circuito abierto: se omite el envío (fallos de red recientes hacia Sentinela)');

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

        $headers = ['Content-Type' => 'application/json', 'X-API-Key' => $this->config->get('sentinela.api_key')];

        $secret = $this->config->get('sentinela.signing_secret');
        if (! empty($secret)) {
            $headers = array_merge($headers, $this->signer->headersFor($body, $secret));
        }

        $attempts = 1 + max(0, (int) $this->config->get('sentinela.retries', 1));
        $endpoint = rtrim($this->config->get('sentinela.endpoint'), '/').'/api/logs';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::withHeaders($headers)
                    ->timeout((float) $this->config->get('sentinela.timeout', 2.0))
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
                    usleep((int) $this->config->get('sentinela.retry_backoff_ms', 100) * 1000);
                }
            }
        }

        // Todos los intentos fallaron por red (nunca por una respuesta HTTP,
        // esos casos ya han hecho return arriba): Sentinela probablemente
        // esté caída o inalcanzable. Abrimos el circuito para no repetir el
        // timeout completo en cada log que ocurra durante los próximos
        // segundos — evita que una caída de Sentinela ralentice la app.
        $this->openCircuit();
    }

    /**
     * El propio driver de caché de la app (Redis, memcached...) podría estar
     * caído a la vez que Sentinela, o fallar por cualquier otro motivo — el
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
        return (int) $this->config->get('sentinela.circuit_breaker_seconds', 30);
    }

    private function passesSample(): bool
    {
        $rate = (float) $this->config->get('sentinela.sample_rate', 1.0);

        return $rate >= 1.0 || mt_rand() / mt_getrandmax() < $rate;
    }

    private function truncatedTrace(Throwable $e): string
    {
        return mb_substr($e->getTraceAsString(), 0, 4000);
    }

    private function debugLog(string $message, array $context = []): void
    {
        if ($this->config->get('sentinela.debug', false)) {
            Log::channel('single')->debug("[sentinela] {$message}", $context);
        }
    }
}
