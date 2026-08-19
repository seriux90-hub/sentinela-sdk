<?php

namespace Sentinela\LaravelClient\Console\Commands;

use Illuminate\Console\Command;
use Sentinela\LaravelClient\SentinelaClient;

class SentinelaTestCommand extends Command
{
    protected $signature = 'sentinela:test';

    protected $description = 'Envía un evento de prueba a Sentinela para verificar la configuración';

    public function handle(SentinelaClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('Sentinela no está configurado: revisa SENTINELA_ENABLED, SENTINELA_KEY y SENTINELA_URL en tu .env.');

            return self::FAILURE;
        }

        $client->capture('info', 'Evento de prueba desde sentinela:test', [
            'source' => 'sentinela:test',
            'app' => config('app.name'),
        ]);

        $this->info('Evento de prueba enviado. Compruébalo en tu proyecto de Sentinela (puede tardar unos segundos).');

        return self::SUCCESS;
    }
}
