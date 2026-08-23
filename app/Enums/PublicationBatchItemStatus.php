<?php

namespace App\Enums;

enum PublicationBatchItemStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case PUBLISHING = 'publishing';
    case LOCAL_PUBLISHED = 'local_published';
    case REMOTE_SYNCED = 'remote_synced';
    case MANUAL_READY = 'manual_ready';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case UNCERTAIN = 'uncertain';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::APPROVED],
            self::APPROVED => [self::PUBLISHING],
            self::PUBLISHING => [self::LOCAL_PUBLISHED, self::REMOTE_SYNCED, self::MANUAL_READY, self::COMPLETED, self::FAILED, self::UNCERTAIN],
            default => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}
