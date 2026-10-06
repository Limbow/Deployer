<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

class ProjectRemoteFilesService
{
    public function __construct(
        private PublishPath $paths,
        private PublishIgnore $ignores,
        private RemoteFileBrowserService $browser,
    ) {}

    public function browse(Project $project, Server $server, string $destination, string $directory): array
    {
        $this->validateRelative($directory, true);
        $destinations = $this->paths->destinations($project, $server);
        $root = $destinations[$destination] ?? null;
        if ($root === null) {
            throw ValidationException::withMessages(['destination' => 'El proyecto no tiene ese destino configurado.']);
        }
        $prefix = $destination === 'public' ? 'public/' : '';
        if ($directory !== '' && $this->protectedReason($prefix.$directory, $project) !== null) {
            throw ValidationException::withMessages(['path' => 'No se permite navegar por una carpeta protegida.']);
        }
        $absolute = rtrim($root, '/').($directory === '' ? '' : '/'.$directory);
        $listing = $this->browser->browse($server, ltrim($absolute, '/'));
        foreach ($listing['entries'] as &$entry) {
            $path = $prefix.($directory === '' ? '' : $directory.'/').$entry['name'];
            $entry['publish_path'] = $path;
            $entry['deletion_reason'] = $entry['type'] !== 'file'
                ? 'Solo se pueden borrar archivos, no carpetas ni enlaces.'
                : $this->deletionReason($path, $project);
        }
        unset($entry);

        return [
            'destination' => $destination, 'path' => $directory, 'remote_path' => $absolute ?: '/',
            'parent_path' => $directory === '' ? null : (dirname($directory) === '.' ? '' : dirname($directory)),
            'entries' => $listing['entries'],
        ];
    }

    public function selected(Project $project, Server $server, array $selected): array
    {
        $destinations = $this->paths->destinations($project, $server);
        $directories = [];
        $files = [];
        foreach ($selected as $path) {
            $this->validateRelative($path);
            $reason = $this->deletionReason($path, $project);
            if ($reason !== null) {
                throw ValidationException::withMessages(['delete_files' => $path.': '.$reason]);
            }
            $public = $destinations['public'] !== null && str_starts_with($path, 'public/');
            $relative = $public ? substr($path, 7) : $path;
            $directory = dirname($relative) === '.' ? '' : dirname($relative);
            $destination = $public ? 'public' : 'backend';
            $key = $destination."\0".$directory;
            $directories[$key] ??= $this->browse($project, $server, $destination, $directory);
            $entry = collect($directories[$key]['entries'])->firstWhere('publish_path', $path);
            if ($entry === null || $entry['type'] !== 'file') {
                throw ValidationException::withMessages(['delete_files' => 'El archivo remoto ya no existe o no es un archivo regular: '.$path.'.']);
            }
            $files[] = ['path' => $path, 'remote_path' => $this->paths->file($path, $destinations),
                'size' => $entry['size'] ?? 0, 'hash' => '', 'manual_delete' => true];
        }

        return $files;
    }

    private function deletionReason(string $path, Project $project): ?string
    {
        $reason = $this->protectedReason($path, $project);
        if ($reason !== null) {
            return $reason;
        }
        $local = LocalPath::directory($project->build_output_path).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (file_exists($local) || is_link($local)) {
            return 'Existe en el origen actual; se reemplaza al subirlo, no se borra.';
        }

        return null;
    }

    private function protectedReason(string $path, Project $project): ?string
    {
        $prefix = '';
        foreach (explode('/', $path) as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix.'/'.$segment;
            if (strtolower($segment) === '_deploys') {
                return 'Protegido: historial remoto de deploys.';
            }
            $reason = $this->ignores->reason($prefix, $project->type, $project->ignore_patterns);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    private function validateRelative(string $path, bool $allowEmpty = false): void
    {
        if ($allowEmpty && $path === '') {
            return;
        }
        if ($path === '' || strlen($path) > 1024 || preg_match('~[\x00-\x1F\x7F\\\\]~', $path)) {
            throw ValidationException::withMessages(['delete_files' => 'Ruta remota no valida.']);
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255) {
                throw ValidationException::withMessages(['delete_files' => 'La ruta contiene un segmento no permitido.']);
            }
        }
    }
}
