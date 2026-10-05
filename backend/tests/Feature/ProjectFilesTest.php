<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Deploy;
use App\Models\Project;
use App\Models\Server;
use App\Services\PublishIgnore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProjectFilesTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private Project $project;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deploy-files-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->directory.DIRECTORY_SEPARATOR.'dist');
        $this->project = Project::create([
            'name' => 'Fixture', 'type' => 'angular', 'target_platform' => 'web',
            'local_path' => $this->directory, 'angular_project' => 'fixture',
            'build_output_path' => $this->directory.DIRECTORY_SEPARATOR.'dist',
            'build_command' => 'npx ng build fixture', 'ignore_patterns' => ['*.map'],
            'current_version' => '1.0.0',
        ]);
        $this->server = $this->server('Hosting');
        $this->project->servers()->attach($this->server->id, ['label' => 'production', 'remote_path_override' => '/public_html/site']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function server(string $name): Server
    {
        return Server::create([
            'name' => $name, 'host' => 'ftp.example.test', 'port' => 21, 'username' => 'fixture',
            'password' => 'private-password', 'use_ftps' => true, 'passive' => true, 'remote_path' => '/public_html',
        ]);
    }

    private function write(string $path, string $content = 'fixture'): void
    {
        $file = $this->project->build_output_path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        File::ensureDirectoryExists(dirname($file));
        File::put($file, $content);
    }

    private function url(?int $serverId = null): string
    {
        return '/api/projects/'.$this->project->id.'/files?server_id='.($serverId ?? $this->server->id);
    }

    private function deploy(array $files, string $status = 'success', ?int $serverId = null, string $destination = '/public_html/site'): Deploy
    {
        $deploy = Deploy::create([
            'project_id' => $this->project->id, 'server_id' => $serverId ?? $this->server->id,
            'version' => '1.0.0', 'status' => $status, 'remote_path' => $destination,
            'public_remote_path' => null, 'finished_at' => now(),
        ]);
        foreach ($files as $path => $content) {
            $deploy->files()->create([
                'relative_path' => $path, 'remote_path' => rtrim($destination, '/').'/'.$path,
                'hash' => sha1($content), 'size' => strlen($content), 'status' => 'uploaded',
            ]);
        }

        return $deploy;
    }

    public function test_first_deploy_returns_sorted_tree_with_sizes_hashes_and_new_files(): void
    {
        $this->write('index.html', 'hello');
        $this->write('assets/logo.svg', 'logo');
        $this->write('main.js.map', 'private map');
        $this->write('.env', 'SECRET=do-not-read');
        $this->write('node_modules/dependency.js', 'not public');
        $response = $this->getJson($this->url())->assertOk()->assertJsonPath('data.last_deploy_id', null)
            ->assertJsonPath('data.hash_algorithm', 'sha1')
            ->assertJsonPath('data.summary.files', 2)->assertJsonPath('data.summary.changed', 2)
            ->assertJsonPath('data.summary.bytes', 9)
            ->assertJsonPath('data.files.0.path', 'assets/logo.svg')
            ->assertJsonPath('data.files.0.hash', sha1('logo'))
            ->assertJsonPath('data.files.0.remote_path', '/public_html/site/assets/logo.svg')
            ->assertJsonPath('data.files.1.size', 5);
        $tree = collect($response->json('data.tree'))->keyBy('path');
        $this->assertTrue($tree['.env']['ignored']);
        $this->assertArrayNotHasKey('hash', $tree['.env']);
        $this->assertSame([], $tree['node_modules']['children']);
        $this->assertTrue($tree['main.js.map']['ignored']);
        $this->assertSame('directory', $tree['assets']['kind']);
        $response->assertDontSee('SECRET=do-not-read')->assertDontSee('private-password');
    }

    public function test_only_successful_uploaded_history_of_same_project_server_destination_is_used(): void
    {
        $this->write('index.html', 'same');
        $this->write('main.js', 'new');
        $old = $this->deploy(['index.html' => 'same', 'main.js' => 'old']);
        $this->deploy(['main.js' => 'new'], 'failed');
        $this->deploy(['main.js' => 'new'], 'rolled_back');
        $this->deploy(['main.js' => 'new'], 'running');
        $other = $this->server('Other');
        $this->deploy(['main.js' => 'new'], serverId: $other->id);
        $this->deploy(['main.js' => 'new'], destination: '/another');
        $response = $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.last_deploy_id', $old->id)->assertJsonPath('data.summary.changed', 1);
        $files = collect($response->json('data.files'))->keyBy('path');
        $this->assertFalse($files['index.html']['changed']);
        $this->assertTrue($files['main.js']['changed']);

        $foreign = $this->deploy(['main.js' => 'new']);
        $foreign->update(['project_id' => null]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.summary.changed', 1);
    }

    public function test_partial_deploys_preserve_untouched_baselines_and_skipped_files_do_not_replace_hashes(): void
    {
        $this->write('index.html', 'v1');
        $this->write('main.js', 'v2');
        $this->deploy(['index.html' => 'v1', 'main.js' => 'v1']);
        $latest = $this->deploy(['main.js' => 'v2']);
        $latest->files()->create([
            'relative_path' => 'index.html', 'remote_path' => '/public_html/site/index.html',
            'hash' => sha1('wrong'), 'size' => 5, 'status' => 'skipped',
        ]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.last_deploy_id', $latest->id)
            ->assertJsonPath('data.summary.changed', 0);
        $this->project->servers()->updateExistingPivot($this->server->id, ['remote_path_override' => '/public_html/new']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.last_deploy_id', null)
            ->assertJsonPath('data.summary.changed', 2);
    }

    public function test_later_absent_and_deleted_records_remove_files_from_the_owned_baseline(): void
    {
        $this->write('index.html', 'current index');
        $first = $this->deploy(['old-chunk.js' => 'old chunk', 'deleted-chunk.js' => 'deleted chunk']);
        $absent = Deploy::create([
            'project_id' => $this->project->id, 'server_id' => $this->server->id,
            'version' => '1.0.1', 'status' => 'success', 'remote_path' => '/public_html/site',
            'public_remote_path' => null, 'finished_at' => now()->addSecond(),
        ]);
        $absent->files()->create([
            'relative_path' => 'old-chunk.js', 'remote_path' => '/public_html/site/old-chunk.js',
            'hash' => sha1('old chunk'), 'size' => strlen('old chunk'), 'status' => 'absent',
        ]);
        $deleted = Deploy::create([
            'project_id' => $this->project->id, 'server_id' => $this->server->id,
            'version' => '1.0.2', 'status' => 'success', 'remote_path' => '/public_html/site',
            'public_remote_path' => null, 'finished_at' => now()->addSeconds(2),
        ]);
        $deleted->files()->create([
            'relative_path' => 'deleted-chunk.js', 'remote_path' => '/public_html/site/deleted-chunk.js',
            'hash' => sha1('deleted chunk'), 'size' => strlen('deleted chunk'), 'status' => 'deleted',
        ]);

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.last_deploy_id', $deleted->id)
            ->assertJsonPath('data.obsolete_files', [])->assertJsonPath('data.summary.obsolete', 0);
        $this->assertGreaterThan($absent->id, $deleted->id);
        $this->assertSame(2, $first->files()->count());
    }

    public function test_server_is_required_existing_and_associated(): void
    {
        $this->getJson('/api/projects/'.$this->project->id.'/files')->assertUnprocessable()->assertJsonValidationErrors('server_id');
        $this->getJson($this->url(9999))->assertUnprocessable();
        $other = $this->server('Not associated');
        $this->getJson($this->url($other->id))->assertUnprocessable()->assertJsonValidationErrors('server_id');
        $this->getJson('/api/projects/9999/files?server_id='.$this->server->id)->assertNotFound();
    }

    public function test_active_build_conflicts_but_failed_build_can_use_existing_files(): void
    {
        $this->write('index.html');
        $build = Build::create([
            'project_id' => $this->project->id, 'project_type' => 'angular',
            'local_path' => $this->directory, 'build_output_path' => $this->project->build_output_path,
            'command' => 'fixture', 'log' => '', 'status' => 'queued',
        ]);
        $this->getJson($this->url())->assertStatus(409);
        $build->update(['status' => 'running']);
        $this->getJson($this->url())->assertStatus(409);
        $build->update(['status' => 'failed']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.summary.files', 1);
    }

    public function test_missing_output_empty_output_and_limits_are_explicit(): void
    {
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.files', [])->assertJsonPath('data.tree', []);
        $this->project->update(['build_output_path' => $this->directory.DIRECTORY_SEPARATOR.'missing']);
        $this->getJson($this->url())->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->project->update(['build_output_path' => $this->directory.DIRECTORY_SEPARATOR.'dist']);
        $this->write('one.txt');
        $this->write('two.txt');
        config(['deploy.files.max_entries' => 2]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.summary.files', 2);
        config(['deploy.files.max_entries' => 1]);
        $this->getJson($this->url())->assertUnprocessable()->assertJsonValidationErrors('files');
        config(['deploy.files.max_entries' => 50000, 'deploy.files.timeout_seconds' => -1]);
        $this->getJson($this->url())->assertUnprocessable()->assertJsonValidationErrors('files');
    }

    public function test_laravel_mapping_and_mandatory_protection_cannot_be_removed_with_empty_patterns(): void
    {
        $this->project->update(['type' => 'laravel', 'build_output_path' => $this->directory, 'ignore_patterns' => []]);
        $this->project->servers()->updateExistingPivot($this->server->id, [
            'remote_path_override' => '/my-api', 'public_remote_path' => '/public_html',
        ]);
        foreach (['.env', '.env.production', 'storage/app/private.txt', 'bootstrap/cache/config.php',
            'public/storage/user-upload.jpg', '.git/config', 'node_modules/index.js', 'database/database.sqlite'] as $path) {
            $this->write($path, 'PRIVATE');
        }
        $this->write('app/Example.php', 'code');
        $this->write('public/index.php', 'entry');
        $this->write('public/build/assets/app.js', 'asset');
        $this->write('vendor/laravel/framework/Example.php', 'dependency');
        $response = $this->getJson($this->url())->assertOk()->assertJsonPath('data.summary.files', 4);
        $files = collect($response->json('data.files'))->keyBy('path');
        $this->assertSame('/my-api/app/Example.php', $files['app/Example.php']['remote_path']);
        $this->assertSame('/public_html/index.php', $files['public/index.php']['remote_path']);
        $this->assertSame('/public_html/build/assets/app.js', $files['public/build/assets/app.js']['remote_path']);
        $this->assertArrayHasKey('vendor/laravel/framework/Example.php', $files);
        $response->assertDontSee('PRIVATE');
        $this->assertCount(0, array_filter(array_keys($files->all()), fn ($path) => str_contains($path, '.env') || str_starts_with($path, 'storage/')));
        $this->project->servers()->updateExistingPivot($this->server->id, ['public_remote_path' => null]);
        $response = $this->getJson($this->url())->assertOk();
        $files = collect($response->json('data.files'))->keyBy('path');
        $this->assertSame('/my-api/public/index.php', $files['public/index.php']['remote_path']);
    }

    public function test_custom_globs_basename_and_directory_subtrees_are_excluded(): void
    {
        $this->project->update(['ignore_patterns' => ['*.map', 'assets/private/*', 'tests', 'cache/*', '*.log']]);
        foreach (['assets/main.js.map', 'assets/main.js', 'assets/private/key.txt', 'tests/test.php', 'cache/file.php', 'debug.log'] as $path) {
            $this->write($path);
        }
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.summary.files', 1)
            ->assertJsonPath('data.files.0.path', 'assets/main.js');
    }

    public function test_invalid_remote_destination_and_laravel_root_fail(): void
    {
        $this->project->servers()->updateExistingPivot($this->server->id, ['remote_path_override' => '/public_html/../other']);
        $this->getJson($this->url())->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->project->update(['type' => 'laravel']);
        $this->getJson($this->url())->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->project->update(['build_output_path' => $this->directory]);
        $this->project->servers()->updateExistingPivot($this->server->id, ['remote_path_override' => '/public_html/api']);
        $this->getJson($this->url())->assertUnprocessable();
        $this->project->servers()->updateExistingPivot($this->server->id, ['remote_path_override' => '/api', 'public_remote_path' => '/api/public']);
        $this->getJson($this->url())->assertUnprocessable();
    }

    public function test_sensitive_nested_paths_are_protected_case_insensitively_and_sqlite_sidecars_are_excluded(): void
    {
        $policy = new PublishIgnore;
        foreach (['nested/.ENV.production', 'storage/a', 'public/storage/a', 'bootstrap/cache/a', 'database/db.sqlite-wal'] as $path) {
            $this->assertNotNull($policy->reason($path, 'laravel', []));
        }
        $this->assertNull($policy->reason('vendor/laravel/Example.php', 'laravel', []));
        $this->assertNull($policy->reason('database/migrations/table.php', 'laravel', []));
    }

    public function test_windows_directory_junction_is_not_traversed(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows junction integration.');
        }
        $target = $this->directory.DIRECTORY_SEPARATOR.'outside';
        $link = $this->project->build_output_path.DIRECTORY_SEPARATOR.'linked';
        File::ensureDirectoryExists($target);
        File::put($target.DIRECTORY_SEPARATOR.'private.txt', 'do not publish');
        $create = new Process([getenv('ComSpec') ?: 'cmd.exe', '/c', 'mklink', '/J', $link, $target]);
        $create->mustRun();
        try {
            $response = $this->getJson($this->url())->assertOk()->assertJsonPath('data.summary.files', 0);
            $node = collect($response->json('data.tree'))->firstWhere('path', 'linked');
            $this->assertTrue($node['ignored']);
            $this->assertSame([], $node['children']);
            $response->assertDontSee('private.txt');
        } finally {
            (new Process([getenv('ComSpec') ?: 'cmd.exe', '/c', 'rmdir', $link]))->mustRun();
        }
    }
}
