<?php

namespace App\Services;

use App\Exceptions\FtpConnectionException;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class RemoteFileBrowserService
{
    private const FTP_ROOT = '/';

    public function __construct(
        private NativeFtpClient $client,
        private FtpConnection $connection,
    ) {}

    public function browse(Server $server, string $relativePath = ''): array
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        $this->connection->open($server, $this->client);

        try {
            $this->changeToRoot();
            $rootPath = $this->currentRootPath();
            $this->assertDirectory($relativePath);
            $entries = $this->listDirectory($relativePath);
            $parent = $relativePath === '' ? null : dirname($relativePath);
            $parent = $parent === '.' ? '' : $parent;

            return [
                'server_name' => $server->name,
                'root_path' => $rootPath,
                'relative_path' => $relativePath,
                'path' => $this->displayPath($rootPath, $relativePath),
                'parent_relative_path' => $parent,
                'entries' => $entries,
            ];
        } finally {
            $this->close();
        }
    }

    public function deleteFile(Server $server, string $relativePath): array
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === '') {
            throw ValidationException::withMessages(['path' => 'Selecciona un archivo; no se pueden borrar carpetas.']);
        }
        $this->connection->open($server, $this->client);

        try {
            $this->changeToRoot();
            $rootPath = $this->currentRootPath();
            $this->assertFile($relativePath);
            if (! $this->client->delete($relativePath)) {
                throw new FtpConnectionException('El servidor no pudo eliminar el archivo seleccionado.');
            }
        } finally {
            $this->close();
        }

        $path = $this->displayPath($rootPath, $relativePath);
        Log::info('Se elimino un archivo remoto desde el explorador.', ['server_id' => $server->id, 'path' => $path]);

        return ['path' => $path];
    }

    public function verifyFile(Server $server, string $relativePath): void
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        $this->connection->open($server, $this->client);
        try {
            $this->changeToRoot();
            $this->assertFile($relativePath);
        } finally {
            $this->close();
        }
    }

    public function downloadFile(Server $server, string $relativePath): BinaryFileResponse
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === '') {
            throw ValidationException::withMessages(['path' => 'Selecciona un archivo para descargar.']);
        }

        $temporaryPath = null;
        try {
            $this->connection->open($server, $this->client);
            try {
                $this->changeToRoot();
                $this->assertFile($relativePath);
                $temporaryPath = tempnam(sys_get_temp_dir(), 'deploy-tool-remote-');
                if ($temporaryPath === false) {
                    $temporaryPath = null;
                    throw new FtpConnectionException('No se pudo preparar el archivo temporal para la descarga.');
                }
                if (! $this->client->download($relativePath, $temporaryPath)) {
                    throw new FtpConnectionException('No se pudo descargar el archivo desde el servidor.');
                }
            } finally {
                $this->close();
            }

            return response()->download($temporaryPath, $this->downloadName($relativePath), [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            if ($temporaryPath !== null && is_file($temporaryPath) && ! @unlink($temporaryPath)) {
                Log::warning('No se pudo limpiar un archivo temporal de descarga FTP.', ['server_id' => $server->id]);
            }
            throw $exception;
        }
    }

    private function changeToRoot(): void
    {
        if (! $this->client->changeDirectory(self::FTP_ROOT)) {
            throw new FtpConnectionException('No se pudo acceder a la raiz visible de la cuenta FTP. Verifica los permisos del usuario.');
        }
    }

    private function currentRootPath(): string
    {
        $path = $this->client->currentDirectory();
        if ($path === false || ! str_starts_with($path, '/') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new FtpConnectionException('El servidor FTP no informo una ruta absoluta valida.');
        }

        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private function close(): void
    {
        if (! $this->client->close()) {
            throw new FtpConnectionException('No se pudo cerrar correctamente la conexion FTP.');
        }
    }

    private function normalizeRelativePath(string $path): string
    {
        if (strlen($path) > 1024 || str_starts_with($path, '/') || preg_match('~[\x00-\x1F\x7F\\\\]~', $path)) {
            throw ValidationException::withMessages(['path' => 'La ruta debe estar dentro de la raiz visible de la cuenta FTP.']);
        }
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255) {
                throw ValidationException::withMessages(['path' => 'La ruta contiene un segmento no permitido.']);
            }
        }

        return implode('/', $segments);
    }

    private function assertDirectory(string $relativePath): void
    {
        $current = '';
        foreach ($relativePath === '' ? [] : explode('/', $relativePath) as $segment) {
            $entry = $this->findEntry($this->listDirectory($current), $segment);
            if ($entry === null) {
                abort(404, 'No se encontro la carpeta solicitada dentro de la raiz FTP.');
            }
            if ($entry['type'] !== 'directory') {
                throw ValidationException::withMessages(['path' => 'Solo se puede navegar por carpetas reales; no se siguen enlaces ni archivos.']);
            }
            $current = $current === '' ? $segment : $current.'/'.$segment;
        }
    }

    private function assertFile(string $relativePath): void
    {
        $parent = dirname($relativePath);
        $parent = $parent === '.' ? '' : $parent;
        $this->assertDirectory($parent);
        $entry = $this->findEntry($this->listDirectory($parent), basename($relativePath));
        if ($entry === null) {
            abort(404, 'No se encontro el archivo solicitado dentro de la raiz FTP.');
        }
        if ($entry['type'] !== 'file') {
            throw ValidationException::withMessages(['path' => 'Solo se permite operar sobre archivos; las carpetas y enlaces no se pueden borrar ni descargar.']);
        }
    }

    private function listDirectory(string $relativePath): array
    {
        $remotePath = $relativePath === '' ? '.' : $relativePath;
        $mlsd = $this->client->listMlsd($remotePath);
        if ($mlsd !== false) {
            return $this->sortEntries($this->mlsdEntries($mlsd, $relativePath));
        }

        $raw = $this->client->listRaw($remotePath);
        if ($raw === false) {
            throw new FtpConnectionException('No se pudo listar la carpeta remota. Verifica los permisos del usuario FTP.');
        }

        return $this->sortEntries($this->rawEntries($raw, $relativePath));
    }

    private function mlsdEntries(array $listing, string $parent): array
    {
        $entries = [];
        foreach ($listing as $facts) {
            if (! is_array($facts) || ! is_string($facts['name'] ?? null)) {
                throw new FtpConnectionException('El servidor devolvio un listado FTP no compatible.');
            }
            $name = $facts['name'];
            if ($name === '.' || $name === '..' || in_array(strtolower((string) ($facts['type'] ?? '')), ['cdir', 'pdir'], true)) {
                continue;
            }
            $this->validateEntryName($name);
            $type = strtolower((string) ($facts['type'] ?? ''));
            $size = isset($facts['size']) && ctype_digit((string) $facts['size']) ? (int) $facts['size'] : null;
            $modified = is_string($facts['modify'] ?? null) ? $facts['modify'] : null;
            $entries[] = $this->entry($name, $this->entryType($type), $size, $modified, $parent);
        }

        return $entries;
    }

    private function rawEntries(array $listing, string $parent): array
    {
        $entries = [];
        foreach ($listing as $line) {
            if (! is_string($line)) {
                throw new FtpConnectionException('El servidor devolvio un listado FTP no compatible.');
            }
            if (trim($line) === '' || preg_match('/\Atotal\s+\d+\z/i', trim($line))) {
                continue;
            }
            $entry = $this->parseRawEntry($line);
            if ($entry['name'] === '.' || $entry['name'] === '..') {
                continue;
            }
            $this->validateEntryName($entry['name']);
            $entries[] = $this->entry($entry['name'], $entry['type'], $entry['size'], null, $parent);
        }

        return $entries;
    }

    private function parseRawEntry(string $line): array
    {
        if (preg_match('/\A([bcdlps-])[rwxstST-]{9}\s+\d+\s+\S+\s+\S+\s+(\d+)\s+\S+\s+\d{1,2}\s+(?:\d{4}|\d{1,2}:\d{2})\s+(.+)\z/', trim($line), $matches)) {
            $name = $matches[3];
            $type = match ($matches[1]) {
                'd' => 'directory',
                'l' => 'link',
                '-' => 'file',
                default => 'unknown',
            };
            if ($type === 'link' && str_contains($name, ' -> ')) {
                $name = explode(' -> ', $name, 2)[0];
            }

            return ['name' => $name, 'type' => $type, 'size' => (int) $matches[2]];
        }
        if (preg_match('/\A\d{2}-\d{2}-\d{2,4}\s+\d{1,2}:\d{2}\s*(?:AM|PM)\s+(<DIR>|\d+)\s+(.+)\z/i', trim($line), $matches)) {
            return [
                'name' => $matches[2],
                'type' => strtoupper($matches[1]) === '<DIR>' ? 'directory' : 'file',
                'size' => strtoupper($matches[1]) === '<DIR>' ? null : (int) $matches[1],
            ];
        }

        return ['name' => trim($line), 'type' => 'unknown', 'size' => null];
    }

    private function entryType(string $type): string
    {
        if (str_contains($type, 'slink') || str_contains($type, 'symlink')) {
            return 'link';
        }
        if ($type === 'dir' || str_ends_with($type, '=dir')) {
            return 'directory';
        }
        if ($type === 'file') {
            return 'file';
        }

        return 'unknown';
    }

    private function entry(string $name, string $type, ?int $size, ?string $modified, string $parent): array
    {
        return [
            'name' => $name,
            'relative_path' => $parent === '' ? $name : $parent.'/'.$name,
            'type' => $type,
            'size' => $size,
            'modified' => $modified,
        ];
    }

    private function findEntry(array $entries, string $name): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['name'] === $name) {
                return $entry;
            }
        }

        return null;
    }

    private function validateEntryName(string $name): void
    {
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new FtpConnectionException('El servidor devolvio un nombre de archivo no compatible.');
        }
    }

    private function sortEntries(array $entries): array
    {
        $order = ['directory' => 0, 'file' => 1, 'link' => 2, 'unknown' => 3];
        usort($entries, fn (array $left, array $right) => ($order[$left['type']] <=> $order[$right['type']])
            ?: strcasecmp($left['name'], $right['name']));

        return $entries;
    }

    private function displayPath(string $rootPath, string $relativePath): string
    {
        return $relativePath === '' ? $rootPath : rtrim($rootPath, '/').'/'.$relativePath;
    }

    private function downloadName(string $relativePath): string
    {
        return basename($relativePath);
    }
}
