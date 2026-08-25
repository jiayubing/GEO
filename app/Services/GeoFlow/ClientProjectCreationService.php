<?php

namespace App\Services\GeoFlow;

use App\Enums\ClientProjectMemberRole;
use App\Enums\ClientProjectMemberStatus;
use App\Enums\ClientStatus;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ClientProjectCreationService
{
    public function __construct(private readonly ProjectAccessService $access) {}

    public function create(Admin $admin, string $clientName, string $projectName, Request $request, ?string $clientSlug = null, ?string $projectSlug = null): ClientProject
    {
        if ($admin->isSuperAdmin() || ! in_array(strtolower((string) $admin->role), ['admin', 'operator'], true)) {
            throw new AccessDeniedHttpException('只有普通管理员可以自助创建客户和项目');
        }

        $clientSlug = $clientSlug ?: Str::slug($clientName);
        $projectSlug = $projectSlug ?: Str::slug($projectName);

        // A retried browser submission is idempotent for the creating operator.
        $existing = ClientProject::query()
            ->where('created_by_admin_id', $admin->getKey())
            ->where('slug', $projectSlug)
            ->whereHas('client', fn ($q) => $q->where('slug', $clientSlug))
            ->first();
        if ($existing !== null) {
            $this->access->switchContext($request, $admin, (int) $existing->getKey());

            return $existing;
        }

        try {
            $project = DB::transaction(function () use ($admin, $clientName, $clientSlug, $projectName, $projectSlug): ClientProject {
            $client = Client::query()->create([
                'name' => $clientName,
                'slug' => $clientSlug,
                'status' => ClientStatus::ACTIVE,
                'created_by_admin_id' => $admin->getKey(),
                'updated_by_admin_id' => $admin->getKey(),
            ]);
            $project = ClientProject::query()->create([
                'client_id' => $client->getKey(),
                'name' => $projectName,
                'slug' => $projectSlug,
                'created_by_admin_id' => $admin->getKey(),
                'updated_by_admin_id' => $admin->getKey(),
            ]);
            ClientProjectMember::query()->create([
                'client_project_id' => $project->getKey(),
                'admin_id' => $admin->getKey(),
                'role' => ClientProjectMemberRole::OPERATOR,
                'status' => ClientProjectMemberStatus::ACTIVE,
                'created_by_admin_id' => $admin->getKey(),
                'updated_by_admin_id' => $admin->getKey(),
            ]);

                return $project;
            });
        } catch (QueryException $exception) {
            // A concurrent retry may lose the unique-slug race; read back its committed fact.
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $project = ClientProject::query()
                ->where('created_by_admin_id', $admin->getKey())
                ->where('slug', $projectSlug)
                ->whereHas('client', fn ($q) => $q->where('slug', $clientSlug))
                ->first();
            if ($project === null) {
                throw $exception;
            }
        }

        $this->access->switchContext($request, $admin, (int) $project->getKey());

        return $project;
    }
}
