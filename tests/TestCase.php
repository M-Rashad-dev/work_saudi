<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests must never hit the internet: every external call has to be faked explicitly.
        Http::preventStrayRequests();

        // Only the sources/drivers a test configures are active.
        config([
            'outreach.serper.api_key' => null,
            'outreach.overpass.url' => null,
            'outreach.llm.gemini.api_key' => null,
            'outreach.llm.ollama.url' => null,
            'outreach.llm.retry_backoff_ms' => [0, 0],
            'outreach.browsershot.enabled' => false,
        ]);
    }
}
