<?php

namespace App\Exceptions;

use RuntimeException;

final class ProjectQuotaExceeded extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $kind)
    {
        parent::__construct('project_quota_exceeded:'.$reason);
    }
}
