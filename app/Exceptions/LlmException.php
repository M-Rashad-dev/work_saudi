<?php

namespace App\Exceptions;

use RuntimeException;

/** Any driver failure (HTTP error, quota, invalid output). The analyzer falls back to the next driver. */
class LlmException extends RuntimeException {}
