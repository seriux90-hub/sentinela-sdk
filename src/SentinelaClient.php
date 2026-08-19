<?php

namespace Sentinela\LaravelClient;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sentinela\LaravelClient\Support\PayloadSigner;
use Sentinela\LaravelClient\Support\PiiScrubber;
use Throwable;

class SentinelaClient
{
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
        $body = json_encode($payload);
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
