<?php

namespace App\Services;

use App\Exceptions\FtpTransferException;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;
use Throwable;

class FtpTransferService
{
    private bool $connected = false;

    public function __construct(private NativeFtpClient $client) {}

    public function ensureDirectory(Server $server, string $path): void
    {
        $this->run($server, 'crear carpeta', function (NativeFtpClient $client) use ($path): void {
            $this->ensureDirectoryWithClient($client, $path);
        });
    }

    public function fileExists(Server $server, string $path): bool
    {
        return $this->run($server, 'comprobar archivo remoto', function (NativeFtpClient $client) use ($path): bool {
            if ($client->size($path) >= 0) {
                return true;
            }
            $parent = dirname($path);
            $listing = $client->listPath($parent === '.' ? '/' : $parent);
            if ($listing === false) {
                throw new FtpTransferException('No se pudo comprobar un archivo en el destino remoto.');
            }
            $name = basename($path);
            foreach ($listing as $entry) {
                if (basename(str_replace('\\', '/', $entry)) === $name) {
                    return true;
                }
            }

            return false;
        });
    }

    public function upload(Server $server, string $localPath, string $remotePath): void
    {
        $this->run($server, 'subir archivo', function (NativeFtpClient $client) use ($localPath, $remotePath): void {
            $this->ensureDirectoryWithClient($client, dirname($remotePath));
            if (! $client->upload($remotePath, $localPath)) {
                throw new FtpTransferException('El servidor no acepto el archivo.');
            }
        });
    }

    public function download(Server $server, string $remotePath, string $localPath): void
    {
        $this->run($server, 'respaldar archivo', function (NativeFtpClient $client) use ($remotePath, $localPath): void {
            if (! $client->download($remotePath, $localPath)) {
                throw new FtpTransferException('No se pudo descargar el respaldo desde el servidor.');
            }
        });
    }

    public function delete(Server $server, string $remotePath): bool
    {
        return $this->run($server, 'eliminar archivo obsoleto', function (NativeFtpClient $client) use ($remotePath): bool {
            if ($client->delete($remotePath)) {
                return true;
            }
            $parent = dirname($remotePath);
            $listing = $client->listPath($parent === '.' ? '/' : $parent);
            if ($listing === false) {
                throw new FtpTransferException('No se pudo verificar el archivo obsoleto en el servidor.');
            }
            foreach ($listing as $entry) {
                if (basename(str_replace('\\', '/', $entry)) === basename($remotePath)) {
                    throw new FtpTransferException('El servidor rechazo la eliminacion del archivo obsoleto.');
                }
            }

            return false;
        });
    }

    public function disconnect(): void
    {
        if (! $this->connected) {
            return;
        }
        try {
            if (! $this->client->close()) {
                Log::warning('No se pudo cerrar limpiamente la conexion FTP de un deploy.');
            }
        } catch (Throwable $exception) {
            Log::warning('Error al cerrar la conexion FTP de un deploy.', ['exception_type' => $exception::class]);
        } finally {
            $this->connected = false;
        }
    }

    private function run(Server $server, string $operation, callable $callback): mixed
    {
        $settings = Setting::values();
        $attempts = max(1, min((int) ($settings['ftp_retries'] ?? 3), 10));
        $delay = max(0, min((int) ($settings['ftp_retry_delay_ms'] ?? 1000), 30000));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                if (! $this->connected) {
                    $this->connect($server);
                }

                return $callback($this->client);
            } catch (FtpTransferException $exception) {
                $this->disconnect();
                if ($attempt === $attempts) {
                    throw $exception;
                }
                Log::warning('Reintentando operacion FTP.', ['operation' => $operation, 'attempt' => $attempt, 'exception_type' => $exception::class]);
                usleep($delay * $attempt * 1000);
            }
        }
        throw new FtpTransferException('La operacion FTP no pudo completarse.');
    }

    private function connect(Server $server): void
    {
        if (! extension_loaded('ftp')) {
            throw new FtpTransferException('La extension FTP de PHP no esta habilitada.');
        }
        try {
            $password = $server->password;
        } catch (DecryptException) {
            throw new FtpTransferException('No se pudo descifrar la contrasena del servidor. Verifica APP_KEY.');
        }
        if ($server->use_ftps && ! function_exists('ftp_ssl_connect')) {
            throw new FtpTransferException('Esta instalacion de PHP no soporta FTPS.');
        }
        if (! $this->client->connect($server->host, $server->port, $server->use_ftps)) {
            throw new FtpTransferException('No se pudo conectar al servidor FTP/FTPS.');
        }
        $this->connected = true;
        if (! $this->client->login($server->username, $password)) {
            throw new FtpTransferException('El servidor rechazo el inicio de sesion FTP.');
        }
        if ($server->passive && ! $this->client->ignorePassiveAddress()) {
            throw new FtpTransferException('No se pudo configurar la direccion pasiva FTP.');
        }
        if (! $this->client->passive($server->passive)) {
            throw new FtpTransferException('No se pudo configurar el modo de transferencia FTP.');
        }
    }

    private function ensureDirectoryWithClient(NativeFtpClient $client, string $path): void
    {
        if (! str_starts_with($path, '/') || preg_match('~[\x00-\x1F\x7F\\\\]|(?:^|/)\.\.?(?:/|$)~', $path)) {
            throw new FtpTransferException('La carpeta remota no es valida.');
        }
        $current = '';
        foreach (array_filter(explode('/', $path), fn (string $segment) => $segment !== '') as $segment) {
            $current .= '/'.$segment;
            if ($client->changeDirectory($current)) {
                continue;
            }
            $client->makeDirectory($current);
            if (! $client->changeDirectory($current)) {
                throw new FtpTransferException('No se pudo crear una carpeta en el servidor.');
            }
        }
    }
}
