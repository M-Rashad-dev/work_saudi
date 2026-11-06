<?php

namespace App\Exceptions;

use RuntimeException;

/** The search backend failed after retries (connection error, 5xx, bad JSON…). */
class SearchUnavailableException extends RuntimeException {}
