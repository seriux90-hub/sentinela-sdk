<?php

namespace Sentinela\LaravelClient\Facades;

use Illuminate\Support\Facades\Facade;
use Sentinela\LaravelClient\SentinelaClient;

/**
 * @method static void capture(string $level, string $message, array $context = [])
 * @method static void reportException(\Throwable $e)
 * @method static bool isConfigured()
 *
 * @see \Sentinela\LaravelClient\SentinelaClient
 */
class Sentinela extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SentinelaClient::class;
    }
}
