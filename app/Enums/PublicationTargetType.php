<?php

namespace App\Enums;

enum PublicationTargetType: string
{
    case LOCAL = 'local';
    case CHANNEL = 'channel';
    case MANUAL = 'manual';
}
