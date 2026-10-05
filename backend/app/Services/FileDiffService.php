<?php

namespace App\Services;

use App\Exceptions\FileManifestException;
use App\Models\Deploy;
use App\Models\DeployFile;
use App\Models\Project;
use App\Models\Server;
use FilesystemIterator;
use Throwable;

class FileDiffService
{
    public function __construct(private PublishPath $paths, private PublishIgnore $ignores) {}

    public function manifest(Project $project, Server $server): array
    {
        $root = LocalPath::directory($project->build_output_path);
        if ($project->type === 'laravel' && $root !== LocalPath::directory($project->local_path)) {
            throw new FileManifestException('Laravel debe usar la raiz del proyecto como origen.');
        }
        $destinations = $this->paths->destinations($project, $server);
        $history = Deploy::where('project_id', $project->id)->where('server_id', $server->id)
            ->where('status', 'success')->where('remote_path', $destinations['backend'])
            ->where('public_remote_path', $destinations['public']);
        $last = (clone $history)->orderByDesc('finished_at')->orderByDesc('id')->first();
        $baseline = [];
        $owned = [];
        $seenRemotePaths = [];
        // Partial deploys retain the last successful uploaded hash of untouched files.
        foreach (DeployFile::query()->join('deploys', 'deploys.id', '=', 'deploy_files.deploy_id')
            ->whereIn('deploy_files.deploy_id', (clone $history)->select('id'))
            ->whereIn('deploy_files.status', ['uploaded', 'deleted', 'absent'])
            ->orderByDesc('deploys.finished_at')->orderByDesc('deploys.id')->orderByDesc('deploy_files.id')
            ->select('deploy_files.*')->cursor() as $file) {
            if (isset($seenRemotePaths[$file->remote_path])) {
                continue;
            }
            $seenRemotePaths[$file->remote_path] = true;
            if ($file->status === 'uploaded') {
                $key = $file->relative_path."\0".$file->remote_path;
                $baseline[$key] = $file->hash;
                $localFile = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file->relative_path);
                if (! file_exists($localFile) && ! is_link($localFile)) {
                    $owned[] = ['path' => $file->relative_path, 'remote_path' => $file->remote_path,
                        'hash' => $file->hash, 'size' => $file->size];
                }
            }
        }
        $files = [];
        $entries = 0;
        $start = microtime(true);
        $limit = (int) config('deploy.files.max_entries', 50000);
        $seconds = (int) config('deploy.files.timeout_seconds', 30);
        $check = function () use (&$entries, $start, $limit, $seconds): void {
            if ($entries > $limit || microtime(true) - $start > $seconds) {
                throw new FileManifestException("El arbol supera el limite de {$limit} entradas o {$seconds} segundos. Reduce el origen o agrega exclusiones y vuelve a intentar; no se devolvio un resultado parcial.");
            }
        };
        $walk = function (string $directory, string $prefix = '', int $depth = 0) use (&$walk, &$files, &$entries, $check, $project, $destinations, $baseline, $root): array {
            if ($depth > 64) {
                throw new FileManifestException('El arbol supera 64 niveles de carpetas.');
            }
            $nodes = [];
            try {
                $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
                foreach ($iterator as $entry) {
                    $entries++;
                    $check();
                    $relative = $prefix.$entry->getFilename();
                    if (! mb_check_encoding($relative, 'UTF-8') || preg_match('/[\x00-\x1F\x7F\\\\]/', $relative)) {
                        throw new FileManifestException('El origen contiene un nombre de archivo no compatible. Renombralo antes de publicar.');
                    }
                    $reason = $this->ignores->reason($relative, $project->type, $project->ignore_patterns);
                    $isLink = $entry->isLink();
                    $resolved = $entry->getRealPath();
                    if ($isLink || ($resolved !== false && ! str_starts_with(strtolower($resolved).DIRECTORY_SEPARATOR, strtolower($root).DIRECTORY_SEPARATOR))) {
                        $reason = 'Protegido: enlace o ruta fuera del origen.';
                    }
                    $kind = $isLink ? 'link' : ($entry->isDir() ? 'directory' : 'file');
                    $node = ['path' => $relative, 'name' => $entry->getFilename(), 'kind' => $kind,
                        'ignored' => $reason !== null, 'reason' => $reason];
                    if ($reason !== null) {
                        $node['children'] = [];
                        $nodes[] = $node;

                        continue;
                    }
                    if ($resolved === false || ! $entry->isReadable()) {
                        throw new FileManifestException('No se puede leer: '.$relative);
                    }
                    if ($kind === 'directory') {
                        $node['children'] = $walk($entry->getPathname(), $relative.'/', $depth + 1);
                    } else {
                        if (! $entry->isFile()) {
                            throw new FileManifestException('Entrada no compatible: '.$relative);
                        }
                        clearstatcache(true, $entry->getPathname());
                        $before = stat($entry->getPathname());
                        $hash = hash_file('sha1', $entry->getPathname());
                        clearstatcache(true, $entry->getPathname());
                        $after = stat($entry->getPathname());
                        if ($hash === false || $before === false || $after === false || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime']) {
                            throw new FileManifestException('El archivo cambio o no se pudo leer durante el analisis: '.$relative.'. Vuelve a cargar el arbol.');
                        }
                        $check();
                        $remote = $this->paths->file($relative, $destinations);
                        $changed = ($baseline[$relative."\0".$remote] ?? null) !== $hash;
                        $file = ['path' => $relative, 'size' => $after['size'], 'hash' => $hash,
                            'changed' => $changed, 'ignored' => false, 'remote_path' => $remote];
                        $files[] = $file;
                        $node = [...$node, ...$file];
                    }
                    $nodes[] = $node;
                }
            } catch (FileManifestException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new FileManifestException('No se pudo recorrer la carpeta: '.($prefix ?: '(origen)').'. Verifica permisos y vuelve a intentar.', previous: $exception);
            }
            usort($nodes, fn (array $a, array $b) => (($a['kind'] !== 'directory') <=> ($b['kind'] !== 'directory')) ?: strnatcasecmp($a['name'], $b['name']));

            return $nodes;
        };
        $tree = $walk($root);
        usort($files, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return [
            'project_id' => $project->id, 'server_id' => $server->id, 'source_path' => $root,
            'destinations' => $destinations, 'last_deploy_id' => $last?->id,
            'generated_at' => now()->toIso8601String(), 'hash_algorithm' => 'sha1',
            'files' => $files, 'tree' => $tree, 'obsolete_files' => $owned,
            'summary' => ['files' => count($files), 'changed' => count(array_filter($files, fn (array $file) => $file['changed'])),
                'bytes' => array_sum(array_column($files, 'size')), 'obsolete' => count($owned)],
        ];
    }
}
