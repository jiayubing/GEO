<?php

namespace App\Enums;

enum PublicationBatchStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case RETURNED = 'returned';
    case REJECTED = 'rejected';
    case PUBLISHING = 'publishing';
    case COMPLETED = 'completed';
    case PARTIAL = 'partial';
    case UNCERTAIN = 'uncertain';
    case FAILED = 'failed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT, self::RETURNED => [self::SUBMITTED],
            self::SUBMITTED => [self::APPROVED, self::RETURNED, self::REJECTED, self::PARTIAL],
            self::APPROVED => [self::PUBLISHING],
            self::PUBLISHING => [self::COMPLETED, self::PARTIAL, self::UNCERTAIN, self::FAILED],
            default => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}
