<?php

namespace App\Exceptions;

use RuntimeException;

final class LiejuRemoteResultUncertainException extends RuntimeException
{
    public function __construct(string $message = '列举网远端结果无法确认。', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
