<?php

namespace Tests\Unit;

use App\Enums\PublicationBatchItemStatus;
use App\Enums\PublicationBatchStatus;
use App\Enums\PublicationTargetType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicationBatchSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_and_item_tables_have_project_scoped_contract(): void
    {
        $this->assertTrue(Schema::hasTable('publication_batches'));
        $this->assertTrue(Schema::hasTable('publication_batch_items'));
        $this->assertTrue(Schema::hasColumns('publication_batches', ['client_project_id', 'status', 'idempotency_key', 'submitted_by_admin_id', 'approved_by_admin_id']));
        $this->assertTrue(Schema::hasColumns('publication_batch_items', ['publication_batch_id', 'client_project_id', 'article_id', 'target_type', 'target_identity', 'article_content_hash', 'target_snapshot', 'idempotency_key', 'status', 'created_by_admin_id', 'approved_by_admin_id']));
    }

    public function test_status_matrix_distinguishes_terminal_outcomes(): void
    {
        $this->assertTrue(PublicationBatchStatus::DRAFT->canTransitionTo(PublicationBatchStatus::SUBMITTED));
        $this->assertFalse(PublicationBatchStatus::COMPLETED->canTransitionTo(PublicationBatchStatus::PUBLISHING));
        $this->assertTrue(PublicationBatchStatus::PUBLISHING->canTransitionTo(PublicationBatchStatus::PARTIAL));
        $this->assertTrue(PublicationBatchStatus::PUBLISHING->canTransitionTo(PublicationBatchStatus::UNCERTAIN));
        $this->assertTrue(PublicationBatchItemStatus::PUBLISHING->canTransitionTo(PublicationBatchItemStatus::FAILED));
        $this->assertFalse(PublicationBatchItemStatus::FAILED->canTransitionTo(PublicationBatchItemStatus::PUBLISHING));
        $this->assertSame('channel', PublicationTargetType::CHANNEL->value);
    }
}
