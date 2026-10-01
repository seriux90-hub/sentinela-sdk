<?php

namespace Nocwise\LaravelClient\Console\Commands;

use Illuminate\Console\Command;
use Nocwise\LaravelClient\NocwiseClient;

class NocwiseTestCommand extends Command
{
    protected $signature = 'nocwise:test';

    protected $description = 'Envía un evento de prueba a Nocwise para verificar la configuración';

    public function handle(NocwiseClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('Nocwise no está configurado: revisa NOCWISE_ENABLED, NOCWISE_KEY y NOCWISE_URL en tu .env.');

            return self::FAILURE;
        }

        $client->capture('info', 'Evento de prueba desde nocwise:test', [
            'source' => 'nocwise:test',
            'app' => config('app.name'),
        ]);

        $this->info('Evento de prueba enviado. Compruébalo en tu proyecto de Nocwise (puede tardar unos segundos).');

        return self::SUCCESS;
    }
}
