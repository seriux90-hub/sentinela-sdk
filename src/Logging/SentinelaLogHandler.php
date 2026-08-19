<?php

namespace Sentinela\LaravelClient\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Sentinela\LaravelClient\SentinelaClient;

/**
 * Handler de Monolog que reenvía cada registro al cliente de Sentinela.
 * Se añade a un canal (normalmente dentro del "stack" de logging.php) para
 * que cualquier Log::error(...)/report(...) de la app llegue también aquí.
 */
class SentinelaLogHandler extends AbstractProcessingHandler
{
    public function __construct(private SentinelaClient $client, int|string|Level $level = Level::Error)
    {
        parent::__construct($level);
    }

    protected function write(LogRecord $record): void
    {
        $context = $record->context;

        // Si el registro trae una excepción (así es como Laravel reporta
        // por defecto), usamos reportException para el formato enriquecido
        // (clase, archivo:línea, traza) en vez de mandar el objeto crudo.
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $this->client->reportException($context['exception']);

            return;
        }

        $this->client->capture($record->level->toPsrLogLevel(), $record->message, $context);
    }
}
