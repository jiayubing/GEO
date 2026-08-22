<?php

namespace App\Exceptions;

use RuntimeException;

final class PublicationGateException extends RuntimeException
{
    public function __construct(
        public readonly string $gateCode,
        public readonly string $target,
        public readonly string $gate,
    ) {
        parent::__construct("Publication gate rejected transition [{$this->gateCode}].");
    }
}
