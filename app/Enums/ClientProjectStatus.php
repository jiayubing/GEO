<?php

namespace App\Enums;

enum ClientProjectStatus: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case ARCHIVED = 'archived';
}
