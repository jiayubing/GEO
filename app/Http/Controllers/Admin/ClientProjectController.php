<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateClientProjectRequest;
use App\Models\Admin;
use App\Services\GeoFlow\ClientProjectCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ClientProjectController extends Controller
{
    public function __construct(private readonly ClientProjectCreationService $creator) {}

    public function create(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && ! $admin->isSuperAdmin(), 403);

        return view('admin.client-projects.create');
    }

    public function storePage(CreateClientProjectRequest $request): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $data = $request->validated();

        $this->creator->create(
            $admin,
            (string) $data['client_name'],
            (string) $data['project_name'],
            $request,
            $data['client_slug'] ?? null,
            $data['project_slug'] ?? null,
        );

        return redirect()->route('admin.project-context.show')
            ->with('message', '客户和项目已创建，当前项目上下文已切换。');
    }

    public function store(CreateClientProjectRequest $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $data = $request->validated();
        $project = $this->creator->create($admin, (string) $data['client_name'], (string) $data['project_name'], $request, $data['client_slug'] ?? null, $data['project_slug'] ?? null);

        return response()->json([
            'client_id' => (int) $project->client_id,
            'project_id' => (int) $project->id,
            'current_project_id' => (int) $project->id,
        ], 201);
    }
}
