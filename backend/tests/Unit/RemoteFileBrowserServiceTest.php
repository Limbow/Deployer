<?php

namespace Tests\Unit;

use App\Exceptions\FtpConnectionException;
use App\Models\Server;
use App\Services\FtpConnection;
use App\Services\NativeFtpClient;
use App\Services\RemoteFileBrowserService;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class RemoteFileBrowserServiceTest extends TestCase
{
    private function server(): Server
    {
        return new Server([
            'name' => 'Production',
            'host' => 'ftp.example.test',
            'port' => 21,
            'username' => 'user',
            'password' => 'secret',
            'use_ftps' => true,
            'passive' => true,
            'remote_path' => '/public_html',
        ]);
    }

    public function test_browses_and_sorts_directories_files_and_links(): void
    {
        $server = $this->server();
        $client = Mockery::mock(NativeFtpClient::class);
        $connection = Mockery::mock(FtpConnection::class);
        $connection->shouldReceive('open')->once()->with($server, $client);
        $client->shouldReceive('changeDirectory')->once()->with('/')->andReturn(true);
        $client->shouldReceive('currentDirectory')->once()->andReturn('/home/ftp-user');
        $client->shouldReceive('listMlsd')->once()->with('.')->andReturn([
            ['name' => 'z.txt', 'type' => 'file', 'size' => '12', 'modify' => '20261004120000'],
            ['name' => 'assets', 'type' => 'dir'],
            ['name' => 'current', 'type' => 'OS.unix=slink:/outside'],
            ['name' => '.', 'type' => 'cdir'],
        ]);
        $client->shouldReceive('close')->once()->andReturn(true);

        $result = (new RemoteFileBrowserService($client, $connection))->browse($server);

        $this->assertSame('Production', $result['server_name']);
        $this->assertSame('/home/ftp-user', $result['root_path']);
        $this->assertSame('/home/ftp-user', $result['path']);
        $this->assertSame(['directory', 'file', 'link'], array_column($result['entries'], 'type'));
        $this->assertSame(['assets', 'z.txt', 'current'], array_column($result['entries'], 'name'));
        $this->assertSame(12, $result['entries'][1]['size']);
        $this->assertNull($result['parent_relative_path']);
    }

    public function test_browses_nested_directories_using_relative_paths(): void
    {
        $server = $this->server();
        $client = Mockery::mock(NativeFtpClient::class);
        $connection = Mockery::mock(FtpConnection::class);
        $connection->shouldReceive('open')->once();
        $client->shouldReceive('changeDirectory')->once()->with('/')->andReturn(true);
        $client->shouldReceive('currentDirectory')->once()->andReturn('/home/ftp-user');
        $client->shouldReceive('listMlsd')->once()->with('.')
            ->andReturn([['name' => 'assets', 'type' => 'dir']]);
        $client->shouldReceive('listMlsd')->once()->with('assets')
            ->andReturn([['name' => 'app.js', 'type' => 'file', 'size' => '20']]);
        $client->shouldReceive('close')->once()->andReturn(true);

        $result = (new RemoteFileBrowserService($client, $connection))->browse($server, 'assets');

        $this->assertSame('/home/ftp-user/assets', $result['path']);
        $this->assertSame('', $result['parent_relative_path']);
        $this->assertSame('assets/app.js', $result['entries'][0]['relative_path']);
    }

    public function test_rejects_traversal_before_opening_the_connection(): void
    {
        $server = $this->server();
        $client = Mockery::mock(NativeFtpClient::class);
        $connection = Mockery::mock(FtpConnection::class);
        $connection->shouldNotReceive('open');

        try {
            (new RemoteFileBrowserService($client, $connection))->browse($server, '../outside');
            $this->fail('Expected traversal validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('path', $exception->errors());
        }
    }

    public function test_verifies_a_regular_file_without_modifying_it(): void
    {
        $server = $this->server();
        $client = Mockery::mock(NativeFtpClient::class);
        $connection = Mockery::mock(FtpConnection::class);
        $connection->shouldReceive('open')->once();
        $client->shouldReceive('changeDirectory')->once()->with('/')->andReturn(true);
        $client->shouldReceive('listMlsd')->once()->with('.')->andReturn([['name' => 'assets', 'type' => 'dir']]);
        $client->shouldReceive('listMlsd')->once()->with('assets')->andReturn([['name' => 'old.js', 'type' => 'file']]);
        $client->shouldNotReceive('delete');
        $client->shouldNotReceive('download');
        $client->shouldReceive('close')->once()->andReturn(true);

        (new RemoteFileBrowserService($client, $connection))->verifyFile($server, 'assets/old.js');
        $this->assertTrue(true);
    }

    public function test_verification_rejects_symlink_ancestors_and_closes_the_connection(): void
    {
        $server = $this->server();
        $client = Mockery::mock(NativeFtpClient::class);
        $connection = Mockery::mock(FtpConnection::class);
        $connection->shouldReceive('open')->once();
        $client->shouldReceive('changeDirectory')->once()->with('/')->andReturn(true);
        $client->shouldReceive('listMlsd')->once()->with('.')->andReturn([['name' => 'assets', 'type' => 'OS.unix=slink:/outside']]);
        $client->shouldNotReceive('delete');
        $client->shouldNotReceive('download');
        $client->shouldReceive('close')->once()->andReturn(true);

        $this->expectException(ValidationException::class);
        (new RemoteFileBrowserService($client, $connection))->verifyFile($server, 'assets/old.js');
    }

    public function test_uses_raw_listing_when_mlsd_is_not_supported(): void
    {
        $server = $this->server();
        $client = Mockery::mock(NativeFtpClient::class);
        $connection = Mockery::mock(FtpConnection::class);
        $connection->shouldReceive('open')->once();
        $client->shouldReceive('changeDirectory')->once()->andReturn(true);
        $client->shouldReceive('currentDirectory')->once()->andReturn('/home/ftp-user');
        $client->shouldReceive('listMlsd')->once()->with('.')->andReturn(false);
        $client->shouldReceive('listRaw')->once()->with('.')->andReturn([
            'drwxr-xr-x 2 owner group 4096 Oct 03 2026 assets',
            '-rw-r--r-- 1 owner group 10 Oct 03 2026 index.html',
            'lrwxrwxrwx 1 owner group 7 Oct 03 2026 current -> /outside',
            'total 12',
        ]);
        $client->shouldReceive('close')->once()->andReturn(true);

        $result = (new RemoteFileBrowserService($client, $connection))->browse($server);

        $this->assertSame(['directory', 'file', 'link'], array_column($result['entries'], 'type'));
        $this->assertSame(['assets', 'index.html', 'current'], array_column($result['entries'], 'name'));
    }

    public function test_downloads_only_a_listed_file_to_a_temporary_response(): void
    {
        $server = $this->server();
        $client = Mockery::mock(NativeFtpClient::class);
        $connection = Mockery::mock(FtpConnection::class);
        $connection->shouldReceive('open')->once();
        $client->shouldReceive('changeDirectory')->once()->andReturn(true);
        $client->shouldReceive('listMlsd')->once()->with('.')
            ->andReturn([['name' => 'index.html', 'type' => 'file']]);
        $temporaryPath = null;
        $client->shouldReceive('download')->once()->with('index.html', Mockery::type('string'))
            ->andReturnUsing(function (string $remote, string $local) use (&$temporaryPath): bool {
                $temporaryPath = $local;
                file_put_contents($local, 'remote content');

                return true;
            });
        $client->shouldReceive('close')->once()->andReturn(true);

        $response = (new RemoteFileBrowserService($client, $connection))->downloadFile($server, 'index.html');

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame('remote content', file_get_contents($temporaryPath));
        $this->assertStringContainsString('index.html', $response->headers->get('content-disposition'));
        @unlink($temporaryPath);
    }

    public function test_deletes_a_file_but_never_a_directory_or_a_link(): void
    {
        $server = $this->server();
        $client = Mockery::mock(NativeFtpClient::class);
        $connection = Mockery::mock(FtpConnection::class);
        $connection->shouldReceive('open')->once();
        $client->shouldReceive('changeDirectory')->once()->andReturn(true);
        $client->shouldReceive('currentDirectory')->once()->andReturn('/home/ftp-user');
        $client->shouldReceive('listMlsd')->once()->with('.')->andReturn([
            ['name' => 'assets', 'type' => 'dir'],
            ['name' => 'current', 'type' => 'OS.unix=slink:/outside'],
        ]);
        $client->shouldReceive('listMlsd')->once()->with('assets')
            ->andReturn([['name' => 'old.js', 'type' => 'file']]);
        $client->shouldReceive('delete')->once()->with('assets/old.js')->andReturn(true);
        $client->shouldReceive('close')->once()->andReturn(true);

        $result = (new RemoteFileBrowserService($client, $connection))->deleteFile($server, 'assets/old.js');

        $this->assertSame(['path' => '/home/ftp-user/assets/old.js'], $result);
    }

    public function test_refuses_to_delete_a_directory_or_link(): void
    {
        $server = $this->server();
        $client = Mockery::mock(NativeFtpClient::class);
        $connection = Mockery::mock(FtpConnection::class);
        $connection->shouldReceive('open')->twice();
        $client->shouldReceive('changeDirectory')->twice()->andReturn(true);
        $client->shouldReceive('currentDirectory')->twice()->andReturn('/home/ftp-user');
        $client->shouldReceive('listMlsd')->times(2)->with('.')->andReturn([
            ['name' => 'assets', 'type' => 'dir'],
            ['name' => 'current', 'type' => 'OS.unix=slink:/outside'],
        ]);
        $client->shouldNotReceive('delete');
        $client->shouldReceive('close')->twice()->andReturn(true);
        $service = new RemoteFileBrowserService($client, $connection);

        foreach (['assets', 'current'] as $path) {
            try {
                $service->deleteFile($server, $path);
                $this->fail("Expected $path to be rejected.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('path', $exception->errors());
            }
        }
    }

    public function test_reports_failed_listings_and_closes_the_connection(): void
    {
        $server = $this->server();
        $client = Mockery::mock(NativeFtpClient::class);
        $connection = Mockery::mock(FtpConnection::class);
        $connection->shouldReceive('open')->once();
        $client->shouldReceive('changeDirectory')->once()->andReturn(true);
        $client->shouldReceive('currentDirectory')->once()->andReturn('/home/ftp-user');
        $client->shouldReceive('listMlsd')->once()->with('.')->andReturn(false);
        $client->shouldReceive('listRaw')->once()->with('.')->andReturn(false);
        $client->shouldReceive('close')->once()->andReturn(true);

        $this->expectException(FtpConnectionException::class);
        (new RemoteFileBrowserService($client, $connection))->browse($server);
    }

    public function test_reports_an_unavailable_current_path_and_closes_the_connection(): void
    {
        $server = $this->server();
        $client = Mockery::mock(NativeFtpClient::class);
        $connection = Mockery::mock(FtpConnection::class);
        $connection->shouldReceive('open')->once();
        $client->shouldReceive('changeDirectory')->once()->andReturn(true);
        $client->shouldReceive('currentDirectory')->once()->andReturn(false);
        $client->shouldReceive('close')->once()->andReturn(true);

        $this->expectException(FtpConnectionException::class);
        (new RemoteFileBrowserService($client, $connection))->browse($server);
    }
}
