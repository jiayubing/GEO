<?php

namespace App\Enums;

enum ClientProjectMemberStatus: string
{
    case ACTIVE = 'active';
    case REVOKED = 'revoked';
}
