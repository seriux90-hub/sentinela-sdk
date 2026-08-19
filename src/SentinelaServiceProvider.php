<?php

namespace Sentinela\LaravelClient;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\ServiceProvider;
use Sentinela\LaravelClient\Console\Commands\SentinelaTestCommand;
use Sentinela\LaravelClient\Listeners\ForwardLoggedExceptions;
use Sentinela\LaravelClient\Support\PayloadSigner;
use Sentinela\LaravelClient\Support\PiiScrubber;

class SentinelaServiceProvider extends ServiceProvider
{
    public const VERSION = '0.2.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sentinela.php', 'sentinela');

        $this->app->singleton(PiiScrubber::class, fn () => new PiiScrubber(
            config('sentinela.scrub_keys', []),
            config('sentinela.scrub_value_patterns', []),
        ));

        $this->app->singleton(PayloadSigner::class);

        $this->app->singleton(SentinelaClient::class, fn ($app) => new SentinelaClient(
            $app->make('config'),
            $app->make(PiiScrubber::class),
            $app->make(PayloadSigner::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sentinela.php' => config_path('sentinela.php'),
            ], 'sentinela-config');

            $this->commands([SentinelaTestCommand::class]);
        }

        // El propio listener comprueba sentinela.enabled/report_exceptions en
        // caliente en cada evento (no aquí), para que cambiarlos en runtime
        // no requiera un reinicio de la app.
        $this->app['events']->listen(MessageLogged::class, ForwardLoggedExceptions::class);
    }
}
