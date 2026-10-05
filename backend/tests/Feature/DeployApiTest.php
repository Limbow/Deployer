<?php

namespace Tests\Feature;

use App\Jobs\DeployJob;
use App\Models\Build;
use App\Models\Deploy;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeployApiTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private Project $project;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 3700]);
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deploy-api-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->directory.DIRECTORY_SEPARATOR.'dist');
        $this->project = Project::create([
            'name' => 'Angular Site', 'type' => 'angular', 'target_platform' => 'web',
            'local_path' => $this->directory, 'angular_project' => 'site',
            'build_output_path' => $this->directory.DIRECTORY_SEPARATOR.'dist',
            'build_command' => 'npx ng build site', 'ignore_patterns' => [], 'current_version' => '1.2.3',
        ]);
        $this->server = Server::create([
            'name' => 'Production', 'host' => 'ftp.example.test', 'port' => 21, 'username' => 'fixture',
            'password' => 'private-password', 'use_ftps' => true, 'passive' => true, 'remote_path' => '/public_html',
        ]);
        $this->project->servers()->attach($this->server->id, ['label' => 'production', 'remote_path_override' => '/public_html/site']);
        File::put($this->directory.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'index.html', '<html>fixture</html>');
        File::put($this->directory.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'main-new.js', 'new bundle');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function previousSuccessfulDeploy(): Deploy
    {
        $deploy = Deploy::create([
            'project_id' => $this->project->id, 'server_id' => $this->server->id,
            'project_name' => $this->project->name, 'project_type' => 'angular', 'server_name' => $this->server->name,
            'source_path' => $this->directory.DIRECTORY_SEPARATOR.'dist',
            'version' => '1.2.2', 'status' => 'success', 'progress' => 100,
            'remote_path' => '/public_html/site', 'public_remote_path' => null, 'finished_at' => now(),
        ]);
        $deploy->files()->create([
            'relative_path' => 'old-chunk-abc.js', 'remote_path' => '/public_html/site/old-chunk-abc.js',
            'hash' => sha1('old chunk'), 'size' => 9, 'status' => 'uploaded',
        ]);
        $deploy->files()->create([
            'relative_path' => 'index.html', 'remote_path' => '/public_html/site/index.html',
            'hash' => sha1('<html>fixture</html>'), 'size' => strlen('<html>fixture</html>'), 'status' => 'uploaded',
        ]);

        return $deploy;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'project_id' => $this->project->id, 'server_id' => $this->server->id,
            'version' => '1.2.4', 'changes' => 'Ajuste de interfaz',
            'files' => ['index.html', 'main-new.js'], 'delete_obsolete' => false,
        ], $overrides);
    }

    public function test_creates_async_deploy_with_verified_snapshot_and_secure_response(): void
    {
        $previous = $this->previousSuccessfulDeploy();
        $response = $this->postJson('/api/deploys', $this->payload())->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.project_name', 'Angular Site')->assertJsonPath('data.server_name', 'Production')
            ->assertJsonPath('data.remote_path', '/public_html/site')
            ->assertJsonPath('data.delete_obsolete', false)
            ->assertJsonCount(2, 'data.files')->assertDontSee('private-password');
        $id = $response->json('data.id');
        $this->assertDatabaseHas('deploy_files', [
            'deploy_id' => $id, 'relative_path' => 'main-new.js', 'status' => 'queued',
            'local_path' => $this->directory.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'main-new.js',
        ]);
        $this->assertDatabaseMissing('deploy_files', ['deploy_id' => $id, 'status' => 'pending_delete']);
        Queue::assertPushed(DeployJob::class, fn ($job) => $job->deployId === $id);
        $this->getJson('/api/deploys/'.$id)->assertOk()->assertJsonCount(2, 'data.files')->assertDontSee('private-password');
        $this->postJson('/api/deploys', $this->payload())->assertStatus(409);
        $this->assertGreaterThan(0, $previous->id);
    }

    public function test_cleanup_requires_all_changed_files_and_records_only_tool_owned_obsolete_paths(): void
    {
        $this->previousSuccessfulDeploy();
        $this->getJson('/api/projects/'.$this->project->id.'/files?server_id='.$this->server->id)->assertOk()
            ->assertJsonPath('data.summary.obsolete', 1)
            ->assertJsonPath('data.obsolete_files.0.path', 'old-chunk-abc.js');
        $this->postJson('/api/deploys', $this->payload(['delete_obsolete' => true, 'files' => ['index.html']]))
            ->assertUnprocessable()->assertJsonValidationErrors('delete_obsolete');
        $this->assertDatabaseCount('deploys', 1);
        $response = $this->postJson('/api/deploys', $this->payload(['delete_obsolete' => true]))->assertStatus(202)
            ->assertJsonPath('data.delete_obsolete', true)->assertJsonCount(3, 'data.files');
        $id = $response->json('data.id');
        $this->assertDatabaseHas('deploy_files', ['deploy_id' => $id, 'relative_path' => 'old-chunk-abc.js', 'status' => 'pending_delete']);
        $this->assertDatabaseHas('deploy_files', ['deploy_id' => $id, 'relative_path' => 'index.html', 'status' => 'queued']);
    }

    public function test_obsolete_files_can_be_cleaned_without_an_upload_when_nothing_changed(): void
    {
        $previous = $this->previousSuccessfulDeploy();
        $previous->files()->create([
            'relative_path' => 'main-new.js', 'remote_path' => '/public_html/site/main-new.js',
            'hash' => sha1('new bundle'), 'size' => strlen('new bundle'), 'status' => 'uploaded',
        ]);

        $this->postJson('/api/deploys', $this->payload(['files' => [], 'delete_obsolete' => true]))
            ->assertStatus(202)->assertJsonPath('data.delete_obsolete', true)->assertJsonCount(1, 'data.files')
            ->assertJsonPath('data.files.0.status', 'pending_delete');
        $this->postJson('/api/deploys', $this->payload(['files' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('files');
    }

    public function test_active_deploy_can_be_recovered_only_for_an_associated_server(): void
    {
        $this->getJson('/api/projects/'.$this->project->id.'/deploys/active?server_id='.$this->server->id)
            ->assertOk()->assertJsonPath('data', null);
        $deploy = Deploy::create([
            'project_id' => $this->project->id, 'server_id' => $this->server->id,
            'project_name' => $this->project->name, 'project_type' => 'angular', 'server_name' => $this->server->name,
            'source_path' => $this->project->build_output_path, 'version' => '1.2.4',
            'status' => 'running', 'remote_path' => '/public_html/site',
        ]);
        $deploy->files()->create([
            'relative_path' => 'index.html', 'remote_path' => '/public_html/site/index.html',
            'hash' => sha1('<html>fixture</html>'), 'size' => strlen('<html>fixture</html>'), 'status' => 'uploaded',
        ]);

        $this->getJson('/api/projects/'.$this->project->id.'/deploys/active?server_id='.$this->server->id)
            ->assertOk()->assertJsonPath('data.id', $deploy->id)->assertJsonCount(1, 'data.files')
            ->assertDontSee('private-password');
        $other = Server::create([
            'name' => 'Other', 'host' => 'ftp.other.test', 'port' => 21, 'username' => 'fixture',
            'password' => 'secret', 'use_ftps' => true, 'passive' => true, 'remote_path' => '/public_html',
        ]);
        $this->getJson('/api/projects/'.$this->project->id.'/deploys/active?server_id='.$other->id)
            ->assertUnprocessable()->assertJsonValidationErrors('server_id');
        $deploy->update(['status' => 'success']);
        $this->getJson('/api/projects/'.$this->project->id.'/deploys/active?server_id='.$this->server->id)
            ->assertOk()->assertJsonPath('data', null);
    }

    public function test_selection_and_version_validation_are_server_recomputed(): void
    {
        $this->previousSuccessfulDeploy();
        $this->postJson('/api/deploys', $this->payload(['files' => ['../.env']]))
            ->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->postJson('/api/deploys', $this->payload(['files' => ['.env']]))
            ->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->postJson('/api/deploys', $this->payload(['files' => ['index.html', 'index.html']]))
            ->assertUnprocessable()->assertJsonValidationErrors('files.1');
        $this->postJson('/api/deploys', $this->payload(['version' => '../unsafe']))
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson('/api/deploys', $this->payload(['files' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->assertDatabaseCount('deploys', 1);
    }

    public function test_requires_associated_server_queue_configuration_and_successful_build(): void
    {
        $other = Server::create([
            'name' => 'Other', 'host' => 'ftp.other.test', 'port' => 21, 'username' => 'fixture',
            'password' => 'secret', 'use_ftps' => true, 'passive' => true, 'remote_path' => '/public_html',
        ]);
        $this->postJson('/api/deploys', $this->payload(['server_id' => $other->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('server_id');
        $build = Build::create([
            'project_id' => $this->project->id, 'status' => 'failed', 'project_type' => 'angular',
            'local_path' => $this->directory, 'build_output_path' => $this->project->build_output_path,
            'command' => 'fixture', 'log' => '',
        ]);
        $this->postJson('/api/deploys', $this->payload(['build_id' => $build->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('build_id');
        config(['queue.connections.database.retry_after' => 3660]);
        $this->postJson('/api/deploys', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('queue');
        $this->assertDatabaseCount('deploys', 0);
    }

    public function test_active_build_and_queue_sync_are_rejected(): void
    {
        Build::create([
            'project_id' => $this->project->id, 'status' => 'running', 'project_type' => 'angular',
            'local_path' => $this->directory, 'build_output_path' => $this->project->build_output_path,
            'command' => 'fixture', 'log' => '',
        ]);
        $this->postJson('/api/deploys', $this->payload())->assertStatus(409);
        config(['queue.default' => 'sync', 'queue.connections.database.retry_after' => 3700]);
        $this->postJson('/api/deploys', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('queue');
    }

    public function test_optional_successful_build_must_match_project_and_output_path(): void
    {
        $build = Build::create([
            'project_id' => $this->project->id, 'status' => 'success', 'project_type' => 'angular',
            'local_path' => $this->directory, 'build_output_path' => $this->directory.DIRECTORY_SEPARATOR.'other',
            'command' => 'fixture', 'log' => '',
        ]);
        $this->postJson('/api/deploys', $this->payload(['build_id' => $build->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('build_id');
    }

    public function test_project_and_server_cannot_be_deleted_during_active_deploy(): void
    {
        $deploy = Deploy::create([
            'project_id' => $this->project->id, 'server_id' => $this->server->id,
            'project_name' => $this->project->name, 'project_type' => 'angular', 'server_name' => $this->server->name,
            'source_path' => $this->project->build_output_path, 'version' => '1.2.4',
            'status' => 'running', 'remote_path' => '/public_html/site',
        ]);
        $this->deleteJson('/api/projects/'.$this->project->id)->assertStatus(409);
        $this->deleteJson('/api/servers/'.$this->server->id)->assertStatus(409);
        $deploy->update(['status' => 'success']);
        $this->deleteJson('/api/projects/'.$this->project->id)->assertNoContent();
        $this->assertNull($deploy->fresh()->project_id);
    }

    public function test_history_filters_orders_paginates_and_returns_file_counts_without_logs(): void
    {
        $previous = $this->previousSuccessfulDeploy();
        $latest = Deploy::create([
            'project_id' => $this->project->id, 'server_id' => $this->server->id,
            'project_name' => $this->project->name, 'project_type' => 'angular', 'server_name' => $this->server->name,
            'source_path' => $this->project->build_output_path, 'version' => '1.2.4',
            'status' => 'failed', 'progress' => 40, 'log' => 'Upload failed',
            'remote_path' => '/public_html/site', 'finished_at' => now(),
        ]);
        $latest->files()->createMany([
            ['relative_path' => 'old.js', 'remote_path' => '/public_html/site/old.js', 'hash' => sha1('old'), 'size' => 3, 'status' => 'deleted'],
            ['relative_path' => 'missing.js', 'remote_path' => '/public_html/site/missing.js', 'hash' => sha1('missing'), 'size' => 7, 'status' => 'absent'],
        ]);
        $otherProject = Project::create([
            'name' => 'Other project', 'type' => 'laravel', 'target_platform' => 'web',
            'local_path' => $this->directory.DIRECTORY_SEPARATOR.'other',
            'angular_project' => null, 'build_output_path' => $this->directory.DIRECTORY_SEPARATOR.'other',
            'build_command' => null, 'ignore_patterns' => [], 'current_version' => '1.0.0',
        ]);
        Deploy::create([
            'project_id' => $otherProject->id, 'project_name' => $otherProject->name,
            'project_type' => 'laravel', 'server_name' => 'Other server', 'version' => '1.0.1',
            'status' => 'success', 'progress' => 100, 'log' => 'private log',
            'remote_path' => '/other', 'finished_at' => now(),
        ]);

        $this->getJson('/api/deploys?project_id='.$this->project->id.'&page=1&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $latest->id)
            ->assertJsonPath('data.0.deleted_files_count', 1)
            ->assertJsonPath('data.0.absent_files_count', 1)
            ->assertJsonMissingPath('data.0.log')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/deploys?project_id='.$this->project->id.'&page=2&per_page=1')
            ->assertOk()->assertJsonPath('data.0.id', $previous->id)
            ->assertJsonPath('data.0.uploaded_files_count', 2);
        $this->getJson('/api/deploys')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/deploys?project_id=999999')->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->getJson('/api/deploys?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }
}
