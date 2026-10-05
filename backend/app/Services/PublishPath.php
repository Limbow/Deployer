<?php

namespace App\Services;

use App\Exceptions\FileManifestException;
use App\Models\Project;
use App\Models\Server;

class PublishPath
{
    public function destinations(Project $project, Server $server): array
    {
        $backend = $this->remote($server->pivot->remote_path_override ?: $server->remote_path);
        $public = $server->pivot->public_remote_path ? $this->remote($server->pivot->public_remote_path) : null;

        return $this->validatePair($project->type, $backend, $public);
    }

    public function validatePair(string $type, string $backend, ?string $public): array
    {
        $backend = $this->remote($backend);
        $public = $public === null ? null : $this->remote($public);
        if ($type === 'laravel') {
            if ($backend === '/' || preg_match('~(?:^|/)public_html(?:/|$)~i', $backend)) {
                throw new FileManifestException('Laravel requiere un destino backend especifico fuera de public_html.');
            }
            if ($public !== null && ($public === '/' || $backend === $public || str_starts_with($backend.'/', $public.'/') || str_starts_with($public.'/', $backend.'/'))) {
                throw new FileManifestException('Backend y public deben tener destinos especificos, separados y no anidados.');
            }
        } elseif ($public !== null) {
            throw new FileManifestException('El destino public separado solo corresponde a Laravel.');
        }

        return ['backend' => $backend, 'public' => $public];
    }

    public function file(string $relative, array $destinations): string
    {
        if ($relative === '' || str_contains($relative, '\\') || preg_match('~(?:^|/)\.\.?(?:/|$)|[\x00-\x1F\x7F]~', $relative) || str_starts_with($relative, '/')) {
            throw new FileManifestException('Ruta relativa de archivo no publicable.');
        }
        if ($destinations['public'] !== null && str_starts_with($relative, 'public/')) {
            return rtrim($destinations['public'], '/').'/'.substr($relative, 7);
        }

        return rtrim($destinations['backend'], '/').'/'.$relative;
    }

    private function remote(string $path): string
    {
        if (! str_starts_with($path, '/') || preg_match('~[\x00-\x1F\x7F\\\\]|(?:^|/)\.\.?(?:/|$)~', $path)) {
            throw new FileManifestException('El destino remoto debe ser absoluto, sin segmentos . o .. ni barras invertidas.');
        }

        return '/'.implode('/', array_filter(explode('/', $path), fn (string $part) => $part !== ''));
    }
}
