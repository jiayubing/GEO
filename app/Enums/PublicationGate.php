<?php

namespace App\Enums;

enum PublicationGate: string
{
    case LEGACY_AUTO = 'legacy_auto';
    case PLATFORM_APPROVAL = 'platform_approval';
}
