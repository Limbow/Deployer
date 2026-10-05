<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\CreatesAngularWorkspaces;
use Tests\TestCase;

class LaravelProjectsTest extends TestCase
{
    use CreatesAngularWorkspaces, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaceRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deploy-laravel-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->workspaceRoot);
        Setting::create(['key' => 'projects_root', 'value' => $this->workspaceRoot]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspaceRoot);
        parent::tearDown();
    }

    private function laravel(): string
    {
        $path = $this->workspaceRoot.DIRECTORY_SEPARATOR.'my-api';
        File::ensureDirectoryExists($path.DIRECTORY_SEPARATOR.'public');
        File::put($path.DIRECTORY_SEPARATOR.'artisan', '<?php');
        File::put($path.DIRECTORY_SEPARATOR.'composer.json', '{"require":{"laravel/framework":"^13.0"}}');
        File::put($path.DIRECTORY_SEPARATOR.'.env', 'SECRET=must-not-read-or-publish');

        return $path;
    }

    private function server(): Server
    {
        return Server::create([
            'name' => 'Hosting', 'host' => 'ftp.example.test', 'port' => 21,
            'username' => 'user', 'password' => 'secret', 'use_ftps' => true,
            'passive' => true, 'remote_path' => '/public_html',
        ]);
    }

    public function test_detects_and_saves_laravel_without_package_json_or_a_build(): void
    {
        $path = $this->laravel();
        $this->getJson('/api/projects/scan')->assertOk()
            ->assertJsonPath('data.projects.0.type', 'laravel')
            ->assertJsonPath('data.projects.0.build_output_path', $path)
            ->assertJsonPath('data.projects.0.build_command', null);
        $response = $this->postJson('/api/projects', ['local_path' => $path])->assertCreated()
            ->assertJsonPath('data.type', 'laravel')->assertJsonPath('data.angular_project', null)
            ->assertJsonPath('data.build_command', null)->assertJsonPath('data.current_version', '0.0.0');
        $this->assertContains('.env', $response->json('data.ignore_patterns'));
        $this->assertContains('storage/*', $response->json('data.ignore_patterns'));
        $this->assertNotContains('vendor/*', $response->json('data.ignore_patterns'));
        $this->assertSame('SECRET=must-not-read-or-publish', File::get($path.DIRECTORY_SEPARATOR.'.env'));
        $this->postJson('/api/projects', ['local_path' => $path])->assertUnprocessable();
    }

    public function test_saves_backend_and_optional_public_destinations_without_leaking_credentials(): void
    {
        $path = $this->laravel();
        $server = $this->server();
        $id = $this->postJson('/api/projects', [
            'local_path' => $path, 'servers' => [[
                'server_id' => $server->id, 'label' => 'production',
                'remote_path_override' => '/my-api', 'public_remote_path' => '/public_html/api',
            ]],
        ])->assertCreated()->assertJsonPath('data.servers.0.pivot.public_remote_path', '/public_html/api')
            ->assertJsonMissingPath('data.servers.0.password')->json('data.id');
        $this->patchJson("/api/projects/$id", ['name' => 'Updated'])->assertOk()
            ->assertJsonPath('data.servers.0.pivot.remote_path_override', '/my-api');
        $this->patchJson("/api/projects/$id", ['servers' => [[
            'server_id' => $server->id, 'label' => 'production', 'remote_path_override' => '/my-api',
        ]]])->assertOk()->assertJsonPath('data.servers.0.pivot.public_remote_path', null);
        foreach ([null, '/', '/public_html/api', '/my-api/../public_html'] as $destination) {
            $this->patchJson("/api/projects/$id", ['servers' => [[
                'server_id' => $server->id, 'label' => 'production', 'remote_path_override' => $destination,
            ]]])->assertUnprocessable()->assertJsonValidationErrors('servers');
        }
        $this->patchJson("/api/projects/$id", ['build_output_path' => 'public'])
            ->assertUnprocessable()->assertJsonValidationErrors('build_output_path');
        foreach (['/my-api', '/my-api/public', '/', '/public_html/../my-api'] as $publicPath) {
            $this->patchJson("/api/projects/$id", ['servers' => [[
                'server_id' => $server->id, 'label' => 'production',
                'remote_path_override' => '/my-api', 'public_remote_path' => $publicPath,
            ]]])->assertUnprocessable()->assertJsonValidationErrors('servers');
        }
    }

    public function test_laravel_version_and_optional_build_are_editable_and_type_is_detected(): void
    {
        $path = $this->laravel();
        File::put($path.DIRECTORY_SEPARATOR.'composer.json', '{"version":"2.3.4","require":{"laravel/framework":"^13.0"}}');
        $id = $this->postJson('/api/projects', ['local_path' => $path])->assertCreated()
            ->assertJsonPath('data.current_version', '2.3.4')->json('data.id');
        $this->patchJson("/api/projects/$id", ['current_version' => '2.3.5', 'build_command' => 'npm run build'])
            ->assertOk()->assertJsonPath('data.build_command', 'npm run build');
        $this->patchJson("/api/projects/$id", ['type' => 'angular'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->patchJson("/api/projects/$id", ['build_command' => null])->assertOk();
    }

    public function test_mobile_and_desktop_are_hidden_by_default_with_reversible_manual_visibility(): void
    {
        $mobile = $this->createWorkspace('mobile'.DIRECTORY_SEPARATOR.'app', ['site' => $this->application()]);
        $desktop = $this->createWorkspace('desktop-app', ['site' => $this->application()]);
        File::put($desktop.DIRECTORY_SEPARATOR.'package.json', '{"version":"1.0.0","devDependencies":{"electron":"^30"}}');
        $web = $this->createWorkspace('website', ['site' => $this->application()]);
        $this->getJson('/api/projects/scan')->assertOk()->assertJsonCount(1, 'data.projects')
            ->assertJsonPath('data.hidden_count', 2);
        $this->getJson('/api/projects/scan?include_hidden=1')->assertOk()->assertJsonCount(3, 'data.projects');
        $id = $this->postJson('/api/projects', ['local_path' => $desktop])->assertCreated()->json('data.id');
        $this->getJson('/api/projects')->assertJsonCount(0, 'data');
        $this->getJson('/api/projects?include_hidden=1')->assertJsonCount(1, 'data');
        $this->postJson('/api/projects/visibility', ['local_path' => $desktop, 'hidden' => false])->assertOk();
        $this->getJson('/api/projects')->assertJsonCount(1, 'data');
        $this->postJson('/api/projects/visibility', ['local_path' => $desktop, 'hidden' => true])->assertOk();
        $this->getJson('/api/projects')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('projects', ['id' => $id]);
        $this->postJson('/api/projects/visibility', ['local_path' => $mobile, 'hidden' => false])->assertOk();
        $this->getJson('/api/projects/scan')->assertJsonCount(2, 'data.projects');
        $this->postJson('/api/projects/visibility', ['local_path' => $web, 'hidden' => true])->assertOk();
        $this->getJson('/api/projects/scan')->assertJsonCount(1, 'data.projects');
    }

    public function test_detection_of_capacitor_and_configurable_folder_filters(): void
    {
        $path = $this->createWorkspace('ionic-web', ['site' => $this->application()]);
        File::put($path.DIRECTORY_SEPARATOR.'capacitor.config.ts', 'export default {};');
        $this->getJson('/api/projects/scan')->assertJsonCount(0, 'data.projects');
        $this->putJson('/api/settings', ['hide_non_web_projects' => false, 'excluded_project_folders' => []])->assertOk();
        $this->getJson('/api/projects/scan')->assertJsonCount(1, 'data.projects');
        $this->putJson('/api/settings', ['excluded_project_folders' => ['ionic-web']])->assertOk();
        $this->getJson('/api/projects/scan')->assertJsonCount(0, 'data.projects');
        $this->putJson('/api/settings', ['excluded_project_folders' => ['bad/folder']])->assertUnprocessable();
    }
}
