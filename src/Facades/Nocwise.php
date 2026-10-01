<?php

namespace Nocwise\LaravelClient\Facades;

use Illuminate\Support\Facades\Facade;
use Nocwise\LaravelClient\NocwiseClient;

/**
 * @method static void capture(string $level, string $message, array $context = [])
 * @method static void captureBatch(array $events)
 * @method static void reportException(\Throwable $e)
 * @method static bool isConfigured()
 *
 * @see \Nocwise\LaravelClient\NocwiseClient
 */
class Nocwise extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return NocwiseClient::class;
    }
}
