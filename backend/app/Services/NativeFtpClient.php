<?php

namespace App\Services;

use FTP\Connection;
use LogicException;

class NativeFtpClient
{
    private ?Connection $connection = null;

    // Native warnings may contain connection details; callers check every result and emit safe errors.
    public function connect(string $host, int $port, bool $secure): bool
    {
        $connection = $secure ? @ftp_ssl_connect($host, $port, 10) : @ftp_connect($host, $port, 10);
        $this->connection = $connection === false ? null : $connection;

        return $connection !== false;
    }

    public function login(string $username, string $password): bool
    {
        return @ftp_login($this->connection(), $username, $password);
    }

    public function passive(bool $enabled): bool
    {
        return @ftp_pasv($this->connection(), $enabled);
    }

    public function ignorePassiveAddress(): bool
    {
        return @ftp_set_option($this->connection(), FTP_USEPASVADDRESS, false);
    }

    public function changeDirectory(string $path): bool
    {
        return @ftp_chdir($this->connection(), $path);
    }

    public function currentDirectory(): string|false
    {
        return @ftp_pwd($this->connection());
    }

    public function listFiles(): array|false
    {
        return @ftp_nlist($this->connection(), '.');
    }

    public function listPath(string $path): array|false
    {
        return @ftp_nlist($this->connection(), $path);
    }

    public function listMlsd(string $path): array|false
    {
        return function_exists('ftp_mlsd') ? @ftp_mlsd($this->connection(), $path) : false;
    }

    public function listRaw(string $path): array|false
    {
        return @ftp_rawlist($this->connection(), $path);
    }

    public function size(string $path): int|false
    {
        return @ftp_size($this->connection(), $path);
    }

    public function makeDirectory(string $path): string|false
    {
        return @ftp_mkdir($this->connection(), $path);
    }

    public function upload(string $remote, string $local): bool
    {
        return @ftp_put($this->connection(), $remote, $local, FTP_BINARY);
    }

    public function download(string $remote, string $local): bool
    {
        return @ftp_get($this->connection(), $local, $remote, FTP_BINARY);
    }

    public function delete(string $path): bool
    {
        return @ftp_delete($this->connection(), $path);
    }

    public function close(): bool
    {
        $closed = @ftp_close($this->connection());
        $this->connection = null;

        return $closed;
    }

    private function connection(): Connection
    {
        return $this->connection ?? throw new LogicException('La conexion FTP no esta abierta.');
    }
}
