<?php

namespace Tests\Unit;

use App\Models\Author;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectDistributionChannel;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\Task;
use App\Services\GeoFlow\ProjectResourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class ProjectResourceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_owned_resources_are_resolved_by_database_owner_and_cross_project_is_rejected(): void
    {
        [$one, $two] = [$this->project('one'), $this->project('two')];
        $knowledge = KnowledgeBase::create(['name' => 'One knowledge', 'client_project_id' => $one->id]);
        $author = Author::create(['name' => 'Two author', 'client_project_id' => $two->id]);
        $resolver = app(ProjectResourceResolver::class);

        $this->assertTrue($resolver->requireOwned(KnowledgeBase::class, $knowledge->id, $one)->is($knowledge));
        $this->expectException(AccessDeniedHttpException::class);
        $resolver->requireOwned(Author::class, $author->id, $one);
    }

    public function test_task_references_and_channel_membership_must_belong_to_same_project(): void
    {
        [$one, $two] = [$this->project('one'), $this->project('two')];
        $task = Task::create(['name' => 'Task', 'client_project_id' => $one->id]);
        $knowledge = KnowledgeBase::create(['name' => 'Knowledge', 'client_project_id' => $two->id]);
        $channel = DistributionChannel::create([
            'name' => 'Channel', 'domain' => 'example.test', 'endpoint_url' => 'https://example.test',
        ]);
        $task->knowledgeBases()->attach($knowledge->id);
        $task->distributionChannels()->attach($channel->id);
        $resolver = app(ProjectResourceResolver::class);

        $this->expectException(AccessDeniedHttpException::class);
        $resolver->requireTaskReferences($task, $one);
    }

    public function test_active_channel_membership_is_required(): void
    {
        [$one, $two] = [$this->project('one'), $this->project('two')];
        $channel = DistributionChannel::create([
            'name' => 'Channel', 'domain' => 'example.test', 'endpoint_url' => 'https://example.test',
        ]);
        $resolver = app(ProjectResourceResolver::class);
        ClientProjectDistributionChannel::create([
            'client_project_id' => $two->id, 'distribution_channel_id' => $channel->id,
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $resolver->requireChannelMembership($one, $channel);
    }

    private function project(string $slug): ClientProject
    {
        $client = Client::create(['name' => 'Client '.$slug, 'slug' => 'client-'.$slug]);

        return ClientProject::create(['client_id' => $client->id, 'name' => 'Project '.$slug, 'slug' => $slug]);
    }
}
