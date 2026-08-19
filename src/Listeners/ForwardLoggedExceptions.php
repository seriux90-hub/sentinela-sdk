<?php

namespace Sentinela\LaravelClient\Listeners;

use Illuminate\Log\Events\MessageLogged;
use Psr\Log\LogLevel;
use Sentinela\LaravelClient\SentinelaClient;

/**
 * Captura automática de excepciones no controladas: cuando Laravel no tiene
 * un reporter custom para una excepción, su comportamiento por defecto es
 * registrarla con Log::error($mensaje, ['exception' => $e]) — este listener
 * escucha ese evento y la reenvía, sin que el proyecto tenga que tocar
 * bootstrap/app.php. (Puede desactivarse con sentinela.report_exceptions=false).
 */
class ForwardLoggedExceptions
{
    private const LEVELS = [
        LogLevel::EMERGENCY => 7, LogLevel::ALERT => 6, LogLevel::CRITICAL => 5,
        LogLevel::ERROR => 4, LogLevel::WARNING => 3, LogLevel::NOTICE => 2,
        LogLevel::INFO => 1, LogLevel::DEBUG => 0,
    ];

    public function __construct(private SentinelaClient $client)
    {
    }

    public function handle(MessageLogged $event): void
    {
        if (! config('sentinela.report_exceptions', true)) {
            return;
        }

        if (! ($event->context['exception'] ?? null) instanceof \Throwable) {
            return;
        }

        if (! $this->meetsMinLevel($event->level)) {
            return;
        }

        $this->client->reportException($event->context['exception']);
    }

    private function meetsMinLevel(string $level): bool
    {
        $min = config('sentinela.min_level', 'error');

        return (self::LEVELS[$level] ?? 0) >= (self::LEVELS[$min] ?? 4);
    }
}
