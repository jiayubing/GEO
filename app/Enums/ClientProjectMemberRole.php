<?php

namespace App\Enums;

enum ClientProjectMemberRole: string
{
    case OWNER = 'owner';
    case MANAGER = 'manager';
    case OPERATOR = 'operator';
    case VIEWER = 'viewer';
}
