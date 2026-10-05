<?php

namespace Tests\Feature;

use App\Exceptions\ProjectScanException;
use App\Jobs\BuildProjectJob;
use App\Models\Build;
use App\Models\Project;
use App\Services\BuildCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BuildTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deploy-build-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->directory);
        $this->project = Project::create([
            'name' => 'Fixture', 'type' => 'angular', 'target_platform' => 'web',
            'local_path' => $this->directory, 'angular_project' => 'fixture',
            'build_command' => 'npx ng build fixture --configuration production',
            'build_output_path' => $this->directory.DIRECTORY_SEPARATOR.'dist',
            'current_version' => '1.0.0', 'ignore_patterns' => [],
        ]);
        config(['queue.default' => 'database']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function build(): Build
    {
        return Build::create([
            'project_id' => $this->project->id, 'project_type' => $this->project->type,
            'local_path' => $this->directory, 'build_output_path' => $this->project->build_output_path,
            'command' => $this->project->build_command, 'status' => 'queued', 'log' => '',
        ]);
    }

    public function test_api_queues_without_running_and_resumes_latest(): void
    {
        Queue::fake();
        Process::preventStrayProcesses();
        $response = $this->postJson('/api/projects/'.$this->project->id.'/build')->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.duration_ms', null)
            ->assertJsonStructure(['data' => ['id', 'duration_ms', 'exit_code', 'started_at', 'finished_at']]);
        $id = $response->json('data.id');
        Queue::assertPushed(BuildProjectJob::class, fn ($job) => $job->buildId === $id && $job->npxPath === 'npx.cmd');
        $this->getJson('/api/builds/'.$id)->assertOk()->assertJsonPath('data.log', '');
        $this->getJson('/api/projects/'.$this->project->id.'/builds/latest')->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/projects/'.$this->project->id.'/build')->assertStatus(409);
        $this->deleteJson('/api/projects/'.$this->project->id)->assertStatus(409);
        Queue::assertPushed(BuildProjectJob::class, 1);
    }

    public function test_queue_insert_is_persisted_atomically(): void
    {
        $this->postJson('/api/projects/'.$this->project->id.'/build')->assertStatus(202);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('builds', 1);
    }

    public function test_unsafe_queue_retry_interval_is_rejected(): void
    {
        config(['queue.connections.database.retry_after' => 3660]);
        $this->postJson('/api/projects/'.$this->project->id.'/build')->assertUnprocessable()->assertJsonValidationErrors('queue');
        $this->assertDatabaseCount('builds', 0);
    }

    public function test_optional_laravel_build_and_sync_queue_are_rejected_explicitly(): void
    {
        $this->project->update(['type' => 'laravel', 'build_command' => null]);
        $this->postJson('/api/projects/'.$this->project->id.'/build')->assertUnprocessable()->assertJsonValidationErrors('build_command');
        $this->assertDatabaseCount('builds', 0);
        config(['queue.default' => 'sync']);
        $this->postJson('/api/projects/'.$this->project->id.'/build')->assertUnprocessable()->assertJsonValidationErrors('queue');
        $this->getJson('/api/projects/'.$this->project->id.'/builds/latest')->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/builds/9999')->assertNotFound();
    }

    public function test_success_stores_both_streams_and_validates_output(): void
    {
        File::ensureDirectoryExists($this->project->build_output_path);
        File::put($this->project->build_output_path.DIRECTORY_SEPARATOR.'index.html', '<html></html>');
        Process::fake(fn () => Process::describe()->output(['Compilando', 'Listo'])->errorOutput('Advertencia')->exitCode(0));
        $build = $this->build();
        (new BuildProjectJob($build->id, 'C:\\Node Tools\\npx.cmd'))->handle(new BuildCommand);
        $build->refresh();
        $this->assertSame('success', $build->status);
        $this->assertSame(0, $build->exit_code);
        $this->assertStringContainsString('Compilando', $build->log);
        $this->assertStringContainsString('Advertencia', $build->log);
        $this->assertNotNull($build->started_at);
        $this->assertNotNull($build->finished_at);
        $this->assertNotNull($build->duration_ms);
        Process::assertRan(fn ($process) => $process->path === $this->directory
            && $process->timeout === 3600 && $process->environment['CI'] === 'true'
            && $process->environment['NG_CLI_ANALYTICS'] === 'false'
            && str_contains(is_array($process->command) ? implode(' ', $process->command) : $process->command, '"C:\\Node Tools\\npx.cmd"'));
        (new BuildProjectJob($build->id, 'npx.cmd'))->handle(new BuildCommand);
        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_nonzero_missing_output_and_missing_directory_fail(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Compile error', exitCode: 2)]);
        $build = $this->build();
        (new BuildProjectJob($build->id, 'npx.cmd'))->handle(new BuildCommand);
        $this->assertSame('failed', $build->fresh()->status);
        $this->assertSame(2, $build->fresh()->exit_code);
        $this->assertStringContainsString('Compile error', $build->fresh()->log);

        Process::fake();
        $build = $this->build();
        (new BuildProjectJob($build->id, 'npx.cmd'))->handle(new BuildCommand);
        $this->assertSame('failed', $build->fresh()->status);
        $this->assertStringContainsString('index.html', $build->fresh()->log);

        $build = $this->build();
        $build->update(['local_path' => $this->directory.DIRECTORY_SEPARATOR.'missing']);
        Process::preventStrayProcesses();
        (new BuildProjectJob($build->id, 'npx.cmd'))->handle(new BuildCommand);
        $this->assertSame('failed', $build->fresh()->status);
        $this->assertStringContainsString('no existe', $build->fresh()->log);
    }

    public function test_laravel_custom_command_does_not_require_index_and_snapshot_survives_edits(): void
    {
        $this->project->update(['type' => 'laravel', 'build_command' => 'npm run build']);
        $build = $this->build();
        $this->project->update(['build_command' => 'should not execute']);
        Process::fake();
        (new BuildProjectJob($build->id, 'npx.cmd'))->handle(new BuildCommand);
        $this->assertSame('success', $build->fresh()->status);
        Process::assertRan(fn ($process) => str_contains(is_array($process->command) ? implode(' ', $process->command) : $process->command, 'npm run build'));
        $this->deleteJson('/api/projects/'.$this->project->id)->assertNoContent();
        $this->assertNull($build->fresh()->project_id);
    }

    public function test_failure_handler_and_log_limit(): void
    {
        $this->project->update(['type' => 'laravel']);
        Process::fake(fn () => Process::describe()->output(str_repeat('a', 2_100_000))->exitCode(1));
        $build = $this->build();
        (new BuildProjectJob($build->id, 'npx.cmd'))->handle(new BuildCommand);
        $this->assertLessThanOrEqual(2_000_000, strlen($build->fresh()->log));
        $this->assertStringContainsString('truncado', $build->fresh()->log);
        $build = $this->build();
        (new BuildProjectJob($build->id, 'npx.cmd'))->failed(new \RuntimeException('SECRET'));
        $this->assertSame('failed', $build->fresh()->status);
        $this->assertStringNotContainsString('SECRET', $build->fresh()->log);
    }

    public function test_npx_replacement_is_only_at_the_start(): void
    {
        $resolver = new BuildCommand;
        $command = $resolver->resolve('npm run build && echo npx', 'custom npx.cmd');
        $this->assertStringNotContainsString('custom npx.cmd', is_array($command) ? implode(' ', $command) : $command);
        $this->assertSame('"C:\\Node $1\\npx.cmd" ng build', $resolver->resolve('npx ng build', 'C:\\Node $1\\npx.cmd'));
        $this->expectException(ProjectScanException::class);
        $resolver->resolve('npx ng build', 'npx.cmd & echo bad');
    }

    public function test_real_windows_npx_shim_handles_spaces_and_arguments(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows command quoting integration test.');
        }
        $shimDirectory = $this->directory.DIRECTORY_SEPARATOR.'Node Tools (fixture)';
        File::ensureDirectoryExists($shimDirectory);
        $script = $this->directory.DIRECTORY_SEPARATOR.'build.php';
        File::put($script, '<?php fwrite(STDOUT, "ARG=".$argv[1]."\n"); fwrite(STDERR, "stderr fixture\n");');
        $shim = $shimDirectory.DIRECTORY_SEPARATOR.'npx.cmd';
        File::put($shim, '@echo off'."\r\n".'"'.PHP_BINARY.'" "'.$script.'" %*'."\r\n");
        $this->project->update(['type' => 'laravel', 'build_command' => 'npx "hello world"']);
        $build = $this->build();
        (new BuildProjectJob($build->id, $shim))->handle(new BuildCommand);
        $this->assertSame('success', $build->fresh()->status, $build->fresh()->log);
        $this->assertStringContainsString('ARG=hello world', $build->fresh()->log);
        $this->assertStringContainsString('stderr fixture', $build->fresh()->log);
    }
}
