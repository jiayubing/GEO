<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * A stable domain outcome for project-scoped channel-site identity operations.
 *
 * The code is intentionally safe to persist or return to an operator. It never
 * carries endpoint configuration, channel secrets, or request input.
 */
final class ProjectSiteIdentityException extends DomainException implements ShouldntReport
{
    public function __construct(public readonly string $identityCode)
    {
        parent::__construct($identityCode);
    }
}
