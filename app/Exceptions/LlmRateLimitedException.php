<?php

namespace App\Exceptions;

/** 429 / quota exhausted after retries with backoff. */
class LlmRateLimitedException extends LlmException {}
