<?php

namespace Nocwise\LaravelClient;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\ServiceProvider;
use Nocwise\LaravelClient\Console\Commands\NocwiseTestCommand;
use Nocwise\LaravelClient\Listeners\ForwardLoggedExceptions;
use Nocwise\LaravelClient\Support\PayloadSigner;
use Nocwise\LaravelClient\Support\PiiScrubber;

class NocwiseServiceProvider extends ServiceProvider
{
    public const VERSION = '0.3.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nocwise.php', 'nocwise');

        $this->app->singleton(PiiScrubber::class, fn () => new PiiScrubber(
            config('nocwise.scrub_keys', []),
            config('nocwise.scrub_value_patterns', []),
        ));

        $this->app->singleton(PayloadSigner::class);

        $this->app->singleton(NocwiseClient::class, fn ($app) => new NocwiseClient(
            $app->make('config'),
            $app->make(PiiScrubber::class),
            $app->make(PayloadSigner::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nocwise.php' => config_path('nocwise.php'),
            ], 'nocwise-config');

            $this->commands([NocwiseTestCommand::class]);
        }

        // El propio listener comprueba nocwise.enabled/report_exceptions en
        // caliente en cada evento (no aquí), para que cambiarlos en runtime
        // no requiera un reinicio de la app.
        $this->app['events']->listen(MessageLogged::class, ForwardLoggedExceptions::class);
    }
}
