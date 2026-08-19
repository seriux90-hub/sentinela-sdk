<?php

namespace Sentinela\LaravelClient\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ExceptionForwardingTest extends TestCase
{
    public function test_an_exception_logged_by_laravels_default_reporting_is_forwarded_automatically(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('', 200)]);

        try {
            throw new RuntimeException('fallo automático');
        } catch (RuntimeException $e) {
            // Así es como Laravel reporta por defecto una excepción sin
            // renderer/reporter custom: Log::error($msg, ['exception' => $e]).
            Log::error($e->getMessage(), ['exception' => $e]);
        }

        Http::assertSent(fn (Request $request) => $request['message'] === 'fallo automático'
            && $request['context']['exception'] === RuntimeException::class);
    }

    public function test_a_log_entry_without_an_exception_in_context_is_not_forwarded_by_the_listener(): void
    {
        Http::fake();

        Log::error('mensaje normal sin excepción');

        Http::assertNothingSent();
    }

    public function test_it_is_not_forwarded_when_report_exceptions_is_disabled(): void
    {
        config(['sentinela.report_exceptions' => false]);
        Http::fake();

        try {
            throw new RuntimeException('no debería llegar');
        } catch (RuntimeException $e) {
            Log::error($e->getMessage(), ['exception' => $e]);
        }

        Http::assertNothingSent();
    }
}
