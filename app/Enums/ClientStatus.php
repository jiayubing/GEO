<?php

namespace App\Enums;

enum ClientStatus: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}
