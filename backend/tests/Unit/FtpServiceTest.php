<?php

namespace Tests\Unit;

use App\Exceptions\FtpConnectionException;
use App\Models\Server;
use App\Services\FtpService;
use App\Services\NativeFtpClient;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FtpServiceTest extends TestCase
{
    private function server(bool $secure = true, bool $passive = true): Server
    {
        return new Server([
            'host' => 'ftp.example.test', 'port' => 21, 'username' => 'user',
            'password' => 'secret', 'use_ftps' => $secure, 'passive' => $passive,
            'remote_path' => '/public_html',
        ]);
    }

    #[DataProvider('modes')]
    public function test_connection_sequence_and_sorted_listing(bool $secure, bool $passive): void
    {
        $client = Mockery::mock(NativeFtpClient::class);
        $client->shouldReceive('connect')->once()->ordered()->with('ftp.example.test', 21, $secure)->andReturn(true);
        $client->shouldReceive('login')->once()->ordered()->with('user', 'secret')->andReturn(true);
        if ($passive) {
            $client->shouldReceive('ignorePassiveAddress')->once()->ordered()->andReturn(true);
        }

        $client->shouldReceive('passive')->once()->ordered()->with($passive)->andReturn(true);
        $client->shouldReceive('changeDirectory')->once()->ordered()->with('/public_html')->andReturn(true);
        $client->shouldReceive('listFiles')->once()->ordered()->andReturn(['z.txt', 'index.html']);
        $client->shouldReceive('close')->once()->ordered()->andReturn(true);

        $result = (new FtpService($client))->test($this->server($secure, $passive));

        $this->assertSame(['index.html', 'z.txt'], $result['entries']);
        $this->assertSame($secure ? 'FTPS' : 'FTP', $result['protocol']);
    }

    public static function modes(): array
    {
        return [[true, true], [false, true], [true, false], [false, false]];
    }

    #[DataProvider('failureSteps')]
    public function test_each_native_failure_is_reported_and_connections_are_closed(string $failure): void
    {
        $client = Mockery::mock(NativeFtpClient::class);
        $steps = ['connect', 'login', 'ignorePassiveAddress', 'passive', 'changeDirectory', 'listFiles'];

        foreach ($steps as $step) {
            $client->shouldReceive($step)->once()->ordered()->andReturn($step === $failure ? false : true);

            if ($step === $failure) {
                break;
            }
        }

        if ($failure !== 'connect') {
            $client->shouldReceive('close')->once()->ordered()->andReturn(true);
        }

        $this->expectException(FtpConnectionException::class);
        (new FtpService($client))->test($this->server());
    }

    public static function failureSteps(): array
    {
        return [['connect'], ['login'], ['passive'], ['ignorePassiveAddress'], ['changeDirectory'], ['listFiles']];
    }

    public function test_invalid_encryption_key_is_reported_without_opening_a_connection(): void
    {
        $server = $this->server();
        $server->setRawAttributes([...$server->getAttributes(), 'password' => 'invalid-ciphertext']);
        $client = Mockery::mock(NativeFtpClient::class);
        $client->shouldNotReceive('connect');

        $this->expectException(FtpConnectionException::class);
        $this->expectExceptionMessage('APP_KEY');
        (new FtpService($client))->test($server);
    }

    public function test_an_empty_directory_is_a_valid_listing(): void
    {
        $client = Mockery::mock(NativeFtpClient::class);
        foreach (['connect', 'login', 'passive', 'ignorePassiveAddress', 'changeDirectory', 'close'] as $step) {
            $client->shouldReceive($step)->once()->andReturn(true);
        }
        $client->shouldReceive('listFiles')->once()->andReturn([]);

        $this->assertSame([], (new FtpService($client))->test($this->server())['entries']);
    }
}
