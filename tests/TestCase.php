<?php

namespace Nocwise\LaravelClient\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Nocwise\LaravelClient\NocwiseServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [NocwiseServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('nocwise.enabled', true);
        $app['config']->set('nocwise.api_key', 'test-api-key');
        $app['config']->set('nocwise.endpoint', 'https://nocwise.test');
        $app['config']->set('nocwise.signing_secret', 'test-signing-secret');
        $app['config']->set('nocwise.retries', 0);
        $app['config']->set('nocwise.circuit_breaker_seconds', 30);
        $app['config']->set('cache.default', 'array');
    }
}
