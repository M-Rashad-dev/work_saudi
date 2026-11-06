<?php

namespace App\Exceptions;

use RuntimeException;

/** A source can no longer be used (e.g. Serper credits exhausted). It has disabled itself. */
class SourceDisabledException extends RuntimeException {}
