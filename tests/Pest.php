<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Unit tests still get the app container (config, Http::fake), without a database.
pest()->extend(TestCase::class)->in('Unit');
