<?php

namespace Sentinela\LaravelClient\Logging;

use Monolog\Level;
use Monolog\Logger;
use Sentinela\LaravelClient\SentinelaClient;

/**
 * Canal de logging custom de Laravel. Añádelo a config/logging.php:
 *
 *   'sentinela' => [
 *       'driver' => 'custom',
 *       'via' => \Sentinela\LaravelClient\Logging\SentinelaLogChannelFactory::class,
 *   ],
 *
 * Y súmalo a tu "stack" (LOG_STACK=stack,sentinela en .env, o directamente
 * en la config) para que los logs de tu app también lleguen a Sentinela.
 */
class SentinelaLogChannelFactory
{
    public function __invoke(array $config): Logger
    {
        $client = app(SentinelaClient::class);
        $level = $config['level'] ?? config('sentinela.min_level', 'error');

        $logger = new Logger('sentinela');
        $logger->pushHandler(new SentinelaLogHandler($client, $this->resolveLevel($level)));

        return $logger;
    }

    /**
     * Level::fromName() espera el nombre del caso del enum tal cual
     * (p. ej. "Error", no "error" ni "ERROR") y lanza ValueError si no
     * coincide exactamente. Un valor de config mal escrito no debe tumbar
     * el arranque de la app entera, así que normalizamos la mayús/minús y
     * caemos a Error si aun así no es un nivel válido.
     */
    private function resolveLevel(mixed $level): Level
    {
        if ($level instanceof Level) {
            return $level;
        }

        try {
            return Level::fromName(ucfirst(strtolower((string) $level)));
        } catch (\ValueError|\UnhandledMatchError) {
            return Level::Error;
        }
    }
}
