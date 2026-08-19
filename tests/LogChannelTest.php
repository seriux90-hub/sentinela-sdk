<?php

namespace Sentinela\LaravelClient\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LogChannelTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.channels.sentinela', [
            'driver' => 'custom',
            'via' => \Sentinela\LaravelClient\Logging\SentinelaLogChannelFactory::class,
            'level' => 'error',
        ]);
    }

    public function test_writing_to_the_sentinela_channel_forwards_the_event(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('', 200)]);

        Log::channel('sentinela')->error('algo se rompió', ['project' => 'demo']);

        Http::assertSent(fn (Request $request) => $request['message'] === 'algo se rompió'
            && $request['context']['project'] === 'demo');
    }

    public function test_records_below_the_configured_level_are_not_forwarded(): void
    {
        Http::fake();

        Log::channel('sentinela')->info('esto es solo informativo');

        Http::assertNothingSent();
    }
}
