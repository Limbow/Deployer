<?php

namespace Tests\Feature;

use App\Models\Deploy;
use App\Models\Server;
use App\Services\RemoteFileBrowserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerFilesApiTest extends TestCase
{
    use RefreshDatabase;

    private function server(): Server
    {
        return Server::create([
            'name' => 'Production',
            'host' => 'ftp.example.test',
            'port' => 21,
            'username' => 'deploy-user',
            'password' => 'private-password',
            'use_ftps' => true,
            'passive' => true,
            'remote_path' => '/public_html',
        ]);
    }

    public function test_lists_files_through_the_server_files_route_without_exposing_credentials(): void
    {
        $server = $this->server();
        $this->mock(RemoteFileBrowserService::class, function ($mock) use ($server): void {
            $mock->shouldReceive('browse')->once()
                ->withArgs(fn (Server $actual, string $path) => $actual->is($server) && $path === 'assets')
                ->andReturn([
                    'server_name' => 'Production',
                    'root_path' => '/home/ftp-user',
                    'relative_path' => 'assets',
                    'path' => '/home/ftp-user/assets',
                    'parent_relative_path' => '',
                    'entries' => [['name' => 'app.js', 'relative_path' => 'assets/app.js', 'type' => 'file', 'size' => 10, 'modified' => null]],
                ]);
        });

        $this->getJson("/api/servers/{$server->id}/files?path=assets")
            ->assertOk()
            ->assertJsonPath('data.server_name', 'Production')
            ->assertJsonPath('data.root_path', '/home/ftp-user')
            ->assertJsonPath('data.path', '/home/ftp-user/assets')
            ->assertJsonPath('data.entries.0.name', 'app.js')
            ->assertJsonMissingPath('data.password')
            ->assertDontSee('private-password');
    }

    public function test_deletes_a_file_through_the_api(): void
    {
        $server = $this->server();
        $this->mock(RemoteFileBrowserService::class, function ($mock) use ($server): void {
            $mock->shouldReceive('deleteFile')->once()
                ->withArgs(fn (Server $actual, string $path) => $actual->is($server) && $path === 'assets/old.js')
                ->andReturn(['path' => '/home/ftp-user/assets/old.js']);
        });

        $this->deleteJson("/api/servers/{$server->id}/files", ['path' => 'assets/old.js'])
            ->assertOk()
            ->assertJsonPath('data.path', '/home/ftp-user/assets/old.js');
    }

    public function test_blocks_file_deletion_while_a_deploy_is_active(): void
    {
        $server = $this->server();
        Deploy::create([
            'server_id' => $server->id,
            'version' => '1.0.0',
            'status' => 'running',
            'remote_path' => '/public_html',
        ]);
        $this->mock(RemoteFileBrowserService::class, fn ($mock) => $mock->shouldNotReceive('deleteFile'));

        $this->deleteJson("/api/servers/{$server->id}/files", ['path' => 'old.js'])
            ->assertConflict()
            ->assertJsonPath('message', 'Espera a que termine el deploy activo de este servidor antes de borrar archivos.');
    }
}
