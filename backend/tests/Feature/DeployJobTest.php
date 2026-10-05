<?php

namespace Tests\Feature;

use App\Exceptions\FtpTransferException;
use App\Jobs\DeployJob;
use App\Models\Deploy;
use App\Models\Project;
use App\Models\Server;
use App\Services\FtpTransferService;
use App\Services\PublishPath;
use App\Services\VersionFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class DeployJobTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $source;

    private Project $project;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deploy-job-'.bin2hex(random_bytes(8));
        $this->source = $this->directory.DIRECTORY_SEPARATOR.'dist';
        File::ensureDirectoryExists($this->source);
        $this->project = Project::create([
            'name' => 'Site', 'type' => 'angular', 'target_platform' => 'web',
            'local_path' => $this->directory, 'angular_project' => 'site', 'build_output_path' => $this->source,
            'build_command' => 'npx ng build site', 'ignore_patterns' => [], 'current_version' => '1.2.3',
        ]);
        $this->server = Server::create([
            'name' => 'Fixture server', 'host' => 'ftp.example.test', 'port' => 21,
            'username' => 'fixture', 'password' => 'fixture-secret', 'use_ftps' => true, 'passive' => true,
            'remote_path' => '/public_html',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (Deploy::all() as $deploy) {
            File::deleteDirectory(storage_path('app/backups'.DIRECTORY_SEPARATOR.$deploy->id));
        }
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function deployment(array $files, bool $cleanup = false): Deploy
    {
        $deploy = Deploy::create([
            'project_id' => $this->project->id, 'server_id' => $this->server->id,
            'project_name' => $this->project->name, 'project_type' => 'angular', 'server_name' => $this->server->name,
            'source_path' => $this->source, 'version' => '1.2.4', 'changes' => 'Fixture update',
            'status' => 'queued', 'progress' => 0, 'log' => '', 'remote_path' => '/public_html/site',
            'public_remote_path' => null, 'delete_obsolete' => $cleanup,
        ]);
        foreach ($files as $path => $status) {
            $localPath = $status === 'queued' ? $this->source.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path) : null;
            if ($localPath) {
                File::ensureDirectoryExists(dirname($localPath));
                File::put($localPath, 'content:'.$path);
            }
            $remote = '/public_html/site/'.$path;
            $deploy->files()->create([
                'relative_path' => $path, 'remote_path' => $remote, 'local_path' => $localPath,
                'hash' => $status === 'queued' ? sha1('content:'.$path) : sha1('old:'.$path),
                'size' => $status === 'queued' ? strlen('content:'.$path) : strlen('old:'.$path),
                'status' => $status,
            ]);
        }

        return $deploy;
    }

    public function test_uploads_index_last_writes_protected_version_manifest_and_updates_current_version(): void
    {
        $deploy = $this->deployment(['main.js' => 'queued', 'index.html' => 'queued']);
        $ftp = Mockery::mock(FtpTransferService::class);
        $serverMatches = Mockery::on(fn ($server) => $server->id === $this->server->id);
        $ftp->shouldReceive('ensureDirectory')->times(3)->withArgs(fn ($server, $path) => $server->id === $this->server->id && in_array($path, ['/public_html/site', '/public_html/site/_deploys'], true));
        $ftp->shouldReceive('fileExists')->times(4)->with($serverMatches, Mockery::type('string'))->andReturn(false);
        $captured = [];
        $uploaded = [];
        $ftp->shouldReceive('upload')->times(4)->with($serverMatches, Mockery::type('string'), Mockery::type('string'))->andReturnUsing(function ($server, $local, $remote) use (&$captured, &$uploaded): void {
            $uploaded[] = $remote;
            $captured[$remote] = File::get($local);
        });
        $ftp->shouldReceive('disconnect')->once();

        (new DeployJob($deploy->id))->handle($ftp, new VersionFileService($ftp), new PublishPath);
        $deploy->refresh();
        $this->assertSame('success', $deploy->status, $deploy->log);
        $this->assertSame(100, $deploy->progress);
        $this->assertSame('1.2.4', $this->project->fresh()->current_version);
        $this->assertStringContainsString('deploy_v1.2.4_', $deploy->version_file_name);
        $sourceUploads = array_values(array_filter($uploaded, fn ($path) => ! str_contains($path, '/_deploys/')));
        $this->assertSame(['/public_html/site/main.js', '/public_html/site/index.html'], $sourceUploads);
        $this->assertSame('uploaded', $deploy->files()->where('relative_path', 'main.js')->value('status'));
        $this->assertSame('uploaded', $deploy->files()->where('relative_path', 'index.html')->value('status'));
        $this->assertSame("Require all denied\n", $captured['/public_html/site/_deploys/.htaccess']);
        $versionPath = '/public_html/site/_deploys/'.$deploy->version_file_name;
        $metadata = json_decode($captured[$versionPath], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Fixture server', $metadata['server']['name']);
        $this->assertSame('1.2.4', $metadata['project']['version']);
        $this->assertCount(2, $metadata['files_uploaded']);
        $this->assertSame([], $metadata['files_deleted']);
        $this->assertArrayNotHasKey('password', $metadata['server']);
        $this->assertStringNotContainsString('fixture-secret', $captured[$versionPath]);
        $this->assertStringContainsString('Deploy completado correctamente.', $deploy->log);
    }

    public function test_backup_before_overwrite_and_cleanup_only_unchanged_obsolete_uploads(): void
    {
        $deploy = $this->deployment(['main-new.js' => 'queued', 'old-chunk.js' => 'pending_delete'], cleanup: true);
        $ftp = Mockery::mock(FtpTransferService::class);
        $serverMatches = Mockery::on(fn ($server) => $server->id === $this->server->id);
        $ftp->shouldReceive('ensureDirectory')->twice()->withArgs(fn ($server, $path) => $server->id === $this->server->id && in_array($path, ['/public_html/site', '/public_html/site/_deploys'], true));
        $ftp->shouldReceive('fileExists')->times(4)->with($serverMatches, Mockery::type('string'))->andReturnUsing(fn ($server, $path) => ! str_ends_with($path, '.json'));
        $ftp->shouldReceive('download')->twice()->with($serverMatches, Mockery::type('string'), Mockery::type('string'))->andReturnUsing(
            fn ($server, $remote, $local) => File::put($local, $remote === '/public_html/site/old-chunk.js' ? 'old:old-chunk.js' : 'old remote version'),
        );
        $metadataJson = null;
        $ftp->shouldReceive('upload')->twice()->with($serverMatches, Mockery::type('string'), Mockery::type('string'))->andReturnUsing(function ($server, $local, $remote) use (&$metadataJson): void {
            if (str_ends_with($remote, '.json')) {
                $metadataJson = File::get($local);
            }
        });
        $ftp->shouldReceive('delete')->once()->with($serverMatches, '/public_html/site/old-chunk.js')->andReturn(true);
        $ftp->shouldReceive('disconnect')->once();

        (new DeployJob($deploy->id))->handle($ftp, new VersionFileService($ftp), new PublishPath);
        $deploy->refresh();
        $this->assertSame('success', $deploy->status, $deploy->log);
        $this->assertSame('uploaded', $deploy->files()->where('relative_path', 'main-new.js')->value('status'));
        $obsolete = $deploy->files()->where('relative_path', 'old-chunk.js')->first();
        $this->assertSame('deleted', $obsolete->status);
        $this->assertSame('backups/'.$deploy->id.'/files/main-new.js', $deploy->files()->where('relative_path', 'main-new.js')->value('backup_path'));
        $this->assertSame('backups/'.$deploy->id.'/obsolete/old-chunk.js', $obsolete->backup_path);
        $this->assertSame('old remote version', File::get(storage_path('app/'.$deploy->files()->where('relative_path', 'main-new.js')->value('backup_path'))));
        $metadata = json_decode($metadataJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $metadata['files_uploaded']);
        $this->assertSame('old-chunk.js', $metadata['files_deleted'][0]['path']);
        $this->assertTrue($metadata['cleanup_obsolete_requested']);
    }

    public function test_modified_remote_obsolete_file_is_backed_up_and_preserved(): void
    {
        $deploy = $this->deployment(['old-chunk.js' => 'pending_delete'], cleanup: true);
        $ftp = Mockery::mock(FtpTransferService::class);
        $ftp->shouldReceive('fileExists')->once()->andReturn(true);
        $ftp->shouldReceive('download')->once()->andReturnUsing(fn ($server, $remote, $local) => File::put($local, 'changed elsewhere'));
        $ftp->shouldNotReceive('delete');
        $ftp->shouldReceive('ensureDirectory')->once()->with(Mockery::on(fn ($server) => $server->id === $this->server->id), '/public_html/site/_deploys');
        $ftp->shouldReceive('fileExists')->once()->andReturn(true);
        $ftp->shouldReceive('fileExists')->once()->andReturn(false);
        $ftp->shouldReceive('upload')->once()->andReturnUsing(function ($server, $local, $remote): void {
            $paths = array_column(json_decode(File::get($local), true, flags: JSON_THROW_ON_ERROR)['files_preserved'], 'path');
            $this->assertContains('old-chunk.js', $paths);
        });
        $ftp->shouldReceive('disconnect')->once();
        (new DeployJob($deploy->id))->handle($ftp, new VersionFileService($ftp), new PublishPath);
        $this->assertSame('success', $deploy->fresh()->status, $deploy->fresh()->log);
        $this->assertSame('skipped', $deploy->files()->where('relative_path', 'old-chunk.js')->value('status'));
    }

    public function test_a_failed_upload_marks_the_deploy_failed_and_never_uploads_a_version_record(): void
    {
        $deploy = $this->deployment(['main.js' => 'queued']);
        $ftp = Mockery::mock(FtpTransferService::class);
        $ftp->shouldReceive('ensureDirectory')->once();
        $ftp->shouldReceive('fileExists')->once()->andReturn(false);
        $ftp->shouldReceive('upload')->once()->andThrow(new FtpTransferException('El servidor rechazo el archivo.'));
        $ftp->shouldNotReceive('upload')->withArgs(fn ($server, $local, $remote) => str_contains($remote, '/_deploys/'));
        $ftp->shouldReceive('disconnect')->once();
        (new DeployJob($deploy->id))->handle($ftp, new VersionFileService($ftp), new PublishPath);
        $this->assertSame('failed', $deploy->fresh()->status);
        $this->assertSame('failed', $deploy->files()->where('relative_path', 'main.js')->value('status'));
        $this->assertStringContainsString('servidor rechazo', $deploy->files()->where('relative_path', 'main.js')->value('error'));
        $this->assertStringContainsString('servidor rechazo', $deploy->fresh()->log);
        $this->assertNull($deploy->fresh()->version_file_name);
    }
}
