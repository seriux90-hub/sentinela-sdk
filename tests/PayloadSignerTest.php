<?php

namespace Sentinela\LaravelClient\Tests;

use Sentinela\LaravelClient\Support\PayloadSigner;

class PayloadSignerTest extends TestCase
{
    public function test_it_signs_the_body_with_the_timestamp_and_nonce(): void
    {
        $signer = new PayloadSigner();

        $signature = $signer->sign('{"a":1}', '1700000000', 'abc123', 'my-secret');

        $expected = hash_hmac('sha256', '1700000000.abc123.{"a":1}', 'my-secret');
        $this->assertSame($expected, $signature);
    }

    public function test_the_signature_changes_if_the_body_changes(): void
    {
        $signer = new PayloadSigner();

        $a = $signer->sign('{"a":1}', '1700000000', 'abc123', 'my-secret');
        $b = $signer->sign('{"a":2}', '1700000000', 'abc123', 'my-secret');

        $this->assertNotSame($a, $b);
    }

    public function test_headers_for_includes_timestamp_nonce_and_signature(): void
    {
        $signer = new PayloadSigner();

        $headers = $signer->headersFor('{"a":1}', 'my-secret');

        $this->assertArrayHasKey('X-Sentinela-Timestamp', $headers);
        $this->assertArrayHasKey('X-Sentinela-Nonce', $headers);
        $this->assertArrayHasKey('X-Sentinela-Signature', $headers);
        $this->assertSame(
            hash_hmac('sha256', "{$headers['X-Sentinela-Timestamp']}.{$headers['X-Sentinela-Nonce']}.".'{"a":1}', 'my-secret'),
            $headers['X-Sentinela-Signature'],
        );
    }

    public function test_two_calls_produce_different_nonces(): void
    {
        $signer = new PayloadSigner();

        $a = $signer->headersFor('{"a":1}', 'my-secret');
        $b = $signer->headersFor('{"a":1}', 'my-secret');

        $this->assertNotSame($a['X-Sentinela-Nonce'], $b['X-Sentinela-Nonce']);
    }
}
