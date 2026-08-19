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

    public function test_the_configured_level_is_case_insensitive(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('', 200)]);
        config(['logging.channels.sentinela.level' => 'ERROR']);

        Log::channel('sentinela')->error('mayúsculas en la config');

        Http::assertSent(fn (Request $request) => $request['message'] === 'mayúsculas en la config');
    }

    public function test_an_invalid_level_falls_back_to_error_instead_of_crashing(): void
    {
        Http::fake(['sentinela.test/*' => Http::response('', 200)]);
        config(['logging.channels.sentinela.level' => 'not-a-real-level']);

        Log::channel('sentinela')->error('no debe romper la resolución del canal');

        Http::assertSent(fn (Request $request) => $request['message'] === 'no debe romper la resolución del canal');
    }
}
