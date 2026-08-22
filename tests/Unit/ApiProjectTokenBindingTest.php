<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Http\Middleware\EnsureApiProjectBinding;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientProject;
use App\Services\Api\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ApiProjectTokenBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_can_be_bound_to_one_project_and_metadata_is_redacted(): void
    {
        $admin = $this->admin('operator');
        $project = $this->project();
        $created = app(ApiTokenService::class)->createToken('bound', ['tasks:read'], $admin->id, null, $project->id);
        $record = $created['record'];

        $this->assertSame($project->id, $record['client_project_id']);
        $this->assertSame('project', $record['binding_mode']);
        $this->assertSame('', $record['token_hash']);
    }

    public function test_legacy_global_token_is_limited_to_super_admin_and_bound_target_must_match(): void
    {
        $admin = $this->admin('operator');
        $context = new ApiAuthContext(['client_project_id' => null], $admin->id);
        $request = Request::create('/');
        $request->attributes->set('api_auth', $context);

        try {
            app(EnsureApiProjectBinding::class)->handle($request, fn ($request) => response('ok'));
            $this->fail('Expected legacy token rejection.');
        } catch (ApiException $exception) {
            $this->assertSame('legacy_token_forbidden', $exception->getErrorCode());
        }

        $super = $this->admin('super_admin');
        $bound = new ApiAuthContext(['client_project_id' => 10], $super->id);
        $request->attributes->set('api_auth', $bound);
        $request->setRouteResolver(fn () => tap(new \Illuminate\Routing\Route('GET', '/', []), fn ($route) => $route->setParameter('project_id', 11)));
        $this->expectException(ApiException::class);
        app(EnsureApiProjectBinding::class)->handle($request, fn ($request) => response('ok'));
    }

    private function project(): ClientProject
    {
        $client = Client::create(['name' => 'Token Client', 'slug' => 'token-client-'.uniqid()]);

        return ClientProject::create(['client_id' => $client->id, 'name' => 'Token Project', 'slug' => 'token-project']);
    }

    private function admin(string $role): Admin
    {
        return Admin::create(['username' => $role.'-'.uniqid(), 'password' => 'password', 'email' => '', 'role' => $role, 'status' => 'active']);
    }
}
