<?php

namespace Sentinela\LaravelClient\Tests;

use Sentinela\LaravelClient\Support\PiiScrubber;

class PiiScrubberTest extends TestCase
{
    public function test_it_redacts_configured_keys(): void
    {
        $scrubber = new PiiScrubber(['password', 'token']);

        $result = $scrubber->scrub(['password' => 'secret123', 'username' => 'alice']);

        $this->assertSame('[redacted]', $result['password']);
        $this->assertSame('alice', $result['username']);
    }

    public function test_it_is_case_insensitive(): void
    {
        $scrubber = new PiiScrubber(['password']);

        $result = $scrubber->scrub(['PASSWORD' => 'secret123']);

        $this->assertSame('[redacted]', $result['PASSWORD']);
    }

    public function test_it_redacts_nested_arrays(): void
    {
        $scrubber = new PiiScrubber(['token']);

        $result = $scrubber->scrub(['user' => ['token' => 'abc', 'name' => 'bob']]);

        $this->assertSame('[redacted]', $result['user']['token']);
        $this->assertSame('bob', $result['user']['name']);
    }

    public function test_it_applies_value_patterns(): void
    {
        $scrubber = new PiiScrubber([], ['card' => '/\b4111\d{12}\b/']);

        $result = $scrubber->scrub(['note' => 'card is 4111111111111111']);

        $this->assertSame('card is [redacted]', $result['note']);
    }

    public function test_it_does_not_mutate_unrelated_values(): void
    {
        $scrubber = new PiiScrubber(['password']);

        $result = $scrubber->scrub(['count' => 5, 'active' => true, 'tags' => ['a', 'b']]);

        $this->assertSame(['count' => 5, 'active' => true, 'tags' => ['a', 'b']], $result);
    }
}
