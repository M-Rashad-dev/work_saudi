<?php

namespace App\Exceptions;

/** The model answered, but not with JSON matching the schema. */
class InvalidLlmOutputException extends LlmException {}
