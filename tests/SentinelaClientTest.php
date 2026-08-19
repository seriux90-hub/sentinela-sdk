<?php

namespace Sentinela\LaravelClient\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Sentinela\LaravelClient\Facades\Sentinela;
use Sentinela\LaravelClient\SentinelaClient;

class SentinelaClientTest extends TestCase
{
    public function test_it_sends_the_expected_payload_with_api_key_and_signature_headers(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('', 200)]);

        Sentinela::capture('error', 'Algo falló', ['user_id' => 42]);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://sentinela.test/api/logs'
                && $request->hasHeader('X-API-Key', 'test-api-key')
                && $request->hasHeader('X-Sentinela-Signature')
                && $request->hasHeader('X-Sentinela-Timestamp')
                && $request->hasHeader('X-Sentinela-Nonce')
                && $request['level'] === 'error'
                && $request['message'] === 'Algo falló'
                && $request['context']['user_id'] === 42;
        });
    }

    public function test_it_scrubs_pii_before_sending(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('', 200)]);

        Sentinela::capture('error', 'Login fallido', ['password' => 'hunter2']);

        Http::assertSent(fn (Request $request) => $request['context']['password'] === '[redacted]');
    }

    public function test_it_does_nothing_when_disabled(): void
    {
        Http::fake();
        config(['sentinela.enabled' => false]);

        Sentinela::capture('error', 'x');

        Http::assertNothingSent();
    }

    public function test_it_does_nothing_when_the_api_key_is_missing(): void
    {
        Http::fake();
        config(['sentinela.api_key' => null]);

        Sentinela::capture('error', 'x');

        Http::assertNothingSent();
    }

    public function test_it_never_throws_when_the_endpoint_is_unreachable(): void
    {
        Http::fake(fn () => throw new RuntimeException('connection refused'));

        Sentinela::capture('error', 'x');

        $this->assertTrue(true, 'capture() no debe lanzar ninguna excepción');
    }

    public function test_it_never_throws_on_a_server_error_response(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('boom', 500)]);

        Sentinela::capture('error', 'x');

        $this->assertTrue(true, 'capture() no debe lanzar ninguna excepción');
    }

    public function test_it_does_not_retry_on_a_4xx_response(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('nope', 402)]);

        config(['sentinela.retries' => 3]);
        Sentinela::capture('error', 'x');

        Http::assertSentCount(1);
    }

    public function test_it_retries_on_a_network_failure(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            throw new RuntimeException('timeout');
        });
        config(['sentinela.retries' => 2, 'sentinela.retry_backoff_ms' => 0]);

        Sentinela::capture('error', 'x');

        $this->assertSame(3, $calls);
    }

    public function test_sample_rate_zero_never_sends(): void
    {
        Http::fake();
        config(['sentinela.sample_rate' => 0.0]);

        Sentinela::capture('error', 'x');

        Http::assertNothingSent();
    }

    public function test_dry_run_does_not_send_over_the_network(): void
    {
        Http::fake();
        config(['sentinela.dry_run' => true]);

        Sentinela::capture('error', 'x');

        Http::assertNothingSent();
    }

    public function test_report_exception_includes_class_file_line_and_trace(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('', 200)]);

        try {
            throw new RuntimeException('kaboom');
        } catch (RuntimeException $e) {
            Sentinela::reportException($e);
        }

        Http::assertSent(function (Request $request) {
            return $request['message'] === 'kaboom'
                && $request['context']['exception'] === RuntimeException::class
                && str_contains($request['context']['file'], __FILE__)
                && ! empty($request['context']['trace']);
        });
    }

    public function test_is_configured_reflects_live_config_changes(): void
    {
        $client = $this->app->make(SentinelaClient::class);

        $this->assertTrue($client->isConfigured());

        config(['sentinela.enabled' => false]);
        $this->assertFalse($client->isConfigured());
    }

    public function test_it_does_not_send_a_payload_that_cannot_be_json_encoded(): void
    {
        Http::fake();

        // Una cadena con bytes inválidos en UTF-8 hace fallar json_encode().
        Sentinela::capture('error', "bad utf8 \xB1\x31");

        Http::assertNothingSent();
    }

    // --- Circuit breaker --------------------------------------------

    public function test_a_network_failure_opens_the_circuit_breaker(): void
    {
        Http::fake(fn () => throw new RuntimeException('connection refused'));

        Sentinela::capture('error', 'first failure');

        $this->assertTrue(Cache::get('sentinela:circuit-open'));
    }

    public function test_no_request_is_attempted_while_the_circuit_is_open(): void
    {
        Http::fake();
        Cache::put('sentinela:circuit-open', true, 30);

        Sentinela::capture('error', 'should be skipped');

        Http::assertNothingSent();
    }

    public function test_the_circuit_breaker_can_be_disabled(): void
    {
        Http::fake(fn () => throw new RuntimeException('connection refused'));
        config(['sentinela.circuit_breaker_seconds' => 0]);

        Sentinela::capture('error', 'first failure');

        $this->assertFalse(Cache::has('sentinela:circuit-open'));
    }

    public function test_a_successful_send_does_not_open_the_circuit(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('', 200)]);

        Sentinela::capture('error', 'ok');

        $this->assertFalse(Cache::has('sentinela:circuit-open'));
    }

    public function test_a_rejected_4xx_response_does_not_open_the_circuit(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('nope', 402)]);

        Sentinela::capture('error', 'quota exceeded');

        $this->assertFalse(Cache::has('sentinela:circuit-open'));
    }

    public function test_it_never_throws_if_the_cache_driver_itself_is_broken(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('', 200)]);
        Cache::shouldReceive('get')->andThrow(new RuntimeException('redis is down'));

        Sentinela::capture('error', 'x');

        $this->assertTrue(true, 'capture() no debe lanzar ninguna excepción aunque falle la caché');
    }
}
