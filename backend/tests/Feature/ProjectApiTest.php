<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Server;
use App\Models\Setting;
use App\Services\LocalPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\CreatesAngularWorkspaces;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    use CreatesAngularWorkspaces, RefreshDatabase;

    private string $projectPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaceRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deploy-api-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->workspaceRoot);
        $this->projectPath = $this->createWorkspace('nested'.DIRECTORY_SEPARATOR.'frontend', ['site' => $this->application()]);
        Setting::create(['key' => 'projects_root', 'value' => $this->workspaceRoot]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspaceRoot);
        parent::tearDown();
    }

    public function test_settings_defaults_persist_and_validate_without_partial_writes(): void
    {
        $this->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.ftp_retries', 3)->assertJsonPath('data.npx_path', 'npx.cmd');
        $this->putJson('/api/settings', [
            'projects_root' => $this->workspaceRoot, 'npx_path' => 'C:\\Node\\npx.cmd',
            'ftp_retries' => 5, 'ftp_retry_delay_ms' => 500,
        ])->assertOk()->assertJsonPath('data.ftp_retries', 5)->assertJsonPath('data.ftp_retry_delay_ms', 500);
        $this->getJson('/api/settings')->assertJsonPath('data.npx_path', 'C:\\Node\\npx.cmd');
        $this->putJson('/api/settings', ['ftp_retry_delay_ms' => '250'])
            ->assertOk()->assertJsonPath('data.ftp_retry_delay_ms', 250);
        $this->putJson('/api/settings', ['projects_root' => 'relative', 'ftp_retries' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors('projects_root');
        $this->getJson('/api/settings')->assertJsonPath('data.ftp_retries', 5);
        $this->putJson('/api/settings', ['ftp_retries' => 0, 'ftp_retry_delay_ms' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors(['ftp_retries', 'ftp_retry_delay_ms']);
    }

    public function test_scan_detects_without_saving_and_marks_registered_workspaces(): void
    {
        $this->getJson('/api/projects/scan')->assertOk()->assertJsonCount(1, 'data.projects')
            ->assertJsonPath('data.projects.0.registered_id', null);
        $this->assertDatabaseCount('projects', 0);
        $id = $this->postJson('/api/projects', ['local_path' => $this->projectPath])->assertCreated()->json('data.id');
        $this->getJson('/api/projects/scan')->assertOk()->assertJsonPath('data.projects.0.registered_id', $id);
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_crud_uses_metadata_preserves_overrides_and_does_not_touch_source_files(): void
    {
        $angular = File::get($this->projectPath.DIRECTORY_SEPARATOR.'angular.json');
        $package = File::get($this->projectPath.DIRECTORY_SEPARATOR.'package.json');
        $id = $this->postJson('/api/projects', ['local_path' => $this->projectPath])
            ->assertCreated()->assertJsonPath('data.name', 'site')
            ->assertJsonPath('data.current_version', '1.2.3')
            ->assertJsonPath('data.ignore_patterns', ['*.map'])->json('data.id');
        $this->getJson('/api/projects')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/projects/$id")->assertOk()->assertJsonPath('data.angular_project', 'site');
        $this->putJson("/api/projects/$id", [
            'local_path' => $this->projectPath,
            'name' => 'Customized', 'build_output_path' => 'custom\\web',
            'build_command' => 'npm run special-build', 'ignore_patterns' => [],
        ])->assertOk()->assertJsonPath('data.build_output_path', LocalPath::absolute('custom/web', $this->projectPath));
        $this->getJson('/api/projects/scan')->assertOk();
        $this->patchJson("/api/projects/$id", ['local_path' => $this->projectPath])
            ->assertOk()->assertJsonPath('data.name', 'Customized')
            ->assertJsonPath('data.build_command', 'npm run special-build')->assertJsonPath('data.ignore_patterns', []);
        $this->assertSame($angular, File::get($this->projectPath.DIRECTORY_SEPARATOR.'angular.json'));
        $this->assertSame($package, File::get($this->projectPath.DIRECTORY_SEPARATOR.'package.json'));
        $this->deleteJson("/api/projects/$id")->assertNoContent();
        $this->assertDirectoryExists($this->projectPath);
        $this->getJson("/api/projects/$id")->assertNotFound();
    }

    public function test_rejects_duplicate_canonical_paths_invalid_workspaces_and_invalid_applications(): void
    {
        $this->postJson('/api/projects', ['local_path' => $this->projectPath])->assertCreated();
        $this->postJson('/api/projects', ['local_path' => $this->projectPath.DIRECTORY_SEPARATOR.'.'])
            ->assertUnprocessable()->assertJsonValidationErrors('local_path');
        $this->postJson('/api/projects', ['local_path' => $this->workspaceRoot])
            ->assertUnprocessable()->assertJsonValidationErrors('local_path');
        $this->postJson('/api/projects', ['local_path' => $this->projectPath.DIRECTORY_SEPARATOR.'missing'])
            ->assertUnprocessable()->assertJsonValidationErrors('local_path');
        $multi = $this->createWorkspace('multi', ['one' => $this->application(), 'two' => $this->application()]);
        $this->postJson('/api/projects', ['local_path' => $multi])
            ->assertUnprocessable()->assertJsonValidationErrors('angular_project');
        $this->postJson('/api/projects', ['local_path' => $multi, 'angular_project' => 'unknown'])
            ->assertUnprocessable()->assertJsonValidationErrors('angular_project');
        $this->postJson('/api/projects', ['local_path' => $multi, 'angular_project' => 'two'])
            ->assertCreated()->assertJsonPath('data.angular_project', 'two');
    }

    public function test_associates_servers_without_credentials_and_cascades_only_pivot_rows(): void
    {
        $server = Server::create([
            'name' => 'Production', 'host' => 'ftp.example.test', 'port' => 21,
            'username' => 'user', 'password' => 'secret', 'use_ftps' => true,
            'passive' => true, 'remote_path' => '/public_html',
        ]);
        $id = $this->postJson('/api/projects', [
            'local_path' => $this->projectPath,
            'servers' => [['server_id' => $server->id, 'label' => 'production', 'remote_path_override' => '/site']],
        ])->assertCreated()->assertJsonPath('data.servers.0.pivot.remote_path_override', '/site')
            ->assertJsonMissingPath('data.servers.0.password')->json('data.id');
        $this->getJson('/api/projects')->assertJsonMissingPath('data.0.servers.0.password');
        $this->patchJson("/api/projects/$id", ['name' => 'Renamed'])->assertOk()->assertJsonCount(1, 'data.servers');
        $this->patchJson("/api/projects/$id", ['servers' => [['server_id' => $server->id, 'label' => 'staging']]])
            ->assertOk()->assertJsonPath('data.servers.0.pivot.remote_path_override', null);
        $this->patchJson("/api/projects/$id", ['servers' => [
            ['server_id' => $server->id, 'label' => 'production'],
            ['server_id' => $server->id, 'label' => 'duplicate'],
        ]])->assertUnprocessable();
        $this->patchJson("/api/projects/$id", ['servers' => [['server_id' => 999, 'label' => 'missing']]])
            ->assertUnprocessable();
        $this->deleteJson("/api/servers/$server->id")->assertNoContent();
        $this->assertDatabaseCount('project_server', 0);
        $this->assertDatabaseHas('projects', ['id' => $id]);
        $this->deleteJson("/api/projects/$id")->assertNoContent();
        $this->assertDatabaseCount('project_server', 0);
    }

    public function test_missing_root_is_explicit_and_bad_workspaces_are_returned_as_issues(): void
    {
        $broken = $this->createWorkspace('broken', ['site' => $this->application()]);
        File::put($broken.DIRECTORY_SEPARATOR.'angular.json', '{broken');
        $this->getJson('/api/projects/scan')->assertOk()->assertJsonCount(1, 'data.projects')->assertJsonCount(1, 'data.issues');
        Setting::where('key', 'projects_root')->firstOrFail()->update(['value' => $this->workspaceRoot.DIRECTORY_SEPARATOR.'missing']);
        $this->getJson('/api/projects/scan')->assertUnprocessable()->assertJsonValidationErrors('projects_root');
        $this->assertSame(0, Project::count());
    }

    public function test_changing_the_application_uses_its_detected_build_defaults(): void
    {
        $path = $this->createWorkspace('switch', [
            'one' => $this->application('dist/one'),
            'two' => $this->application('dist/two'),
        ]);
        $id = $this->postJson('/api/projects', ['local_path' => $path, 'angular_project' => 'one'])
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/projects/$id", ['angular_project' => 'two'])
            ->assertOk()->assertJsonPath('data.angular_project', 'two')
            ->assertJsonPath('data.build_output_path', LocalPath::absolute('dist/two/browser', $path));
        $this->assertStringContainsString('two', Project::findOrFail($id)->build_command);
    }
}
