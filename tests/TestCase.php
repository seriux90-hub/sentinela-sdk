<?php

namespace Sentinela\LaravelClient\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Sentinela\LaravelClient\SentinelaServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [SentinelaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('sentinela.enabled', true);
        $app['config']->set('sentinela.api_key', 'test-api-key');
        $app['config']->set('sentinela.endpoint', 'https://sentinela.test');
        $app['config']->set('sentinela.signing_secret', 'test-signing-secret');
        $app['config']->set('sentinela.retries', 0);
        $app['config']->set('sentinela.circuit_breaker_seconds', 30);
        $app['config']->set('cache.default', 'array');
    }
}
