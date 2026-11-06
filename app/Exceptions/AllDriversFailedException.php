<?php

namespace App\Exceptions;

use RuntimeException;

class AllDriversFailedException extends RuntimeException
{
    /** @param array<string, string> $errors driver => reason */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('All LLM drivers failed: '.json_encode($errors, JSON_UNESCAPED_UNICODE));
    }
}
