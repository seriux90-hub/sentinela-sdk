<?php

namespace Sentinela\LaravelClient\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Sentinela\LaravelClient\Facades\Sentinela;
use Sentinela\LaravelClient\SentinelaClient;

class CaptureBatchTest extends TestCase
{
    public function test_it_sends_all_events_in_a_single_signed_request(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('', 200)]);

        Sentinela::captureBatch([
            ['level' => 'info', 'message' => 'uno'],
            ['level' => 'error', 'message' => 'dos', 'context' => ['order_id' => 7]],
        ]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://sentinela.test/api/logs/batch'
                && $request->hasHeader('X-API-Key', 'test-api-key')
                && $request->hasHeader('X-Sentinela-Signature')
                && count($request['logs']) === 2
                && $request['logs'][0]['message'] === 'uno'
                && $request['logs'][1]['level'] === 'error'
                && $request['logs'][1]['context']['order_id'] === 7
                && $request['logs'][1]['meta']['sdk'] === 'sentinela/laravel-client';
        });
    }

    public function test_it_splits_batches_larger_than_the_server_limit(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('', 200)]);

        $events = array_fill(0, SentinelaClient::MAX_BATCH_SIZE + 1, ['level' => 'info', 'message' => 'x']);

        Sentinela::captureBatch($events);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => count($request['logs']) === SentinelaClient::MAX_BATCH_SIZE);
        Http::assertSent(fn (Request $request) => count($request['logs']) === 1);
    }

    public function test_it_scrubs_pii_in_every_event(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('', 200)]);

        Sentinela::captureBatch([
            ['level' => 'error', 'message' => 'a', 'context' => ['password' => 'hunter2']],
        ]);

        Http::assertSent(fn (Request $request) => $request['logs'][0]['context']['password'] === '[redacted]');
    }

    public function test_it_drops_invalid_events_and_sends_the_rest(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('', 200)]);

        Sentinela::captureBatch([
            ['level' => 'info'],
            'no-es-un-array',
            ['message' => 'sin nivel'],
            ['level' => 'info', 'message' => 'válido'],
        ]);

        Http::assertSent(fn (Request $request) => count($request['logs']) === 1
            && $request['logs'][0]['message'] === 'válido');
    }

    public function test_it_sends_nothing_when_every_event_is_invalid_or_the_batch_is_empty(): void
    {
        Http::fake();

        Sentinela::captureBatch([]);
        Sentinela::captureBatch([['level' => 'info']]);

        Http::assertNothingSent();
    }

    public function test_it_applies_sampling_per_event(): void
    {
        Http::fake();
        config(['sentinela.sample_rate' => 0.0]);

        Sentinela::captureBatch([['level' => 'info', 'message' => 'x']]);

        Http::assertNothingSent();
    }

    public function test_it_does_nothing_when_disabled(): void
    {
        Http::fake();
        config(['sentinela.enabled' => false]);

        Sentinela::captureBatch([['level' => 'info', 'message' => 'x']]);

        Http::assertNothingSent();
    }

    public function test_it_does_not_send_in_dry_run_mode(): void
    {
        Http::fake();
        config(['sentinela.dry_run' => true]);

        Sentinela::captureBatch([['level' => 'info', 'message' => 'x']]);

        Http::assertNothingSent();
    }

    public function test_it_never_throws_and_opens_the_circuit_on_network_failure(): void
    {
        Http::fake(fn () => throw new RuntimeException('connection refused'));

        Sentinela::captureBatch([['level' => 'info', 'message' => 'x']]);

        $this->assertTrue(Cache::get('sentinela:circuit-open'));
    }

    public function test_it_skips_sending_while_the_circuit_is_open(): void
    {
        Http::fake();
        Cache::put('sentinela:circuit-open', true, 30);

        Sentinela::captureBatch([['level' => 'info', 'message' => 'x']]);

        Http::assertNothingSent();
    }
}
