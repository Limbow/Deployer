<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FileManifestException;
use App\Exceptions\ProjectScanException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeployRequest;
use App\Jobs\DeployJob;
use App\Models\Build;
use App\Models\Deploy;
use App\Models\Project;
use App\Services\FileDiffService;
use App\Services\LocalPath;
use App\Services\PublishPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DeployController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $values = $request->validate([
            'project_id' => ['sometimes', 'required', 'integer', 'exists:projects,id'],
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'required', 'integer', 'between:1,100'],
        ]);
        $perPage = (int) ($values['per_page'] ?? 20);
        $deploys = Deploy::query()
            ->select([
                'id', 'project_id', 'server_id', 'build_id', 'project_name', 'project_type', 'server_name',
                'version', 'changes', 'status', 'progress', 'duration_ms', 'remote_path', 'public_remote_path',
                'delete_obsolete', 'version_file_name', 'git_commit', 'started_at', 'finished_at', 'created_at',
            ])
            ->withCount([
                'files',
                'files as uploaded_files_count' => fn (Builder $query) => $query->where('status', 'uploaded'),
                'files as deleted_files_count' => fn (Builder $query) => $query->where('status', 'deleted'),
                'files as absent_files_count' => fn (Builder $query) => $query->where('status', 'absent'),
            ])
            ->when(isset($values['project_id']), fn (Builder $query) => $query->where('project_id', $values['project_id']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $deploys->items(),
            'meta' => [
                'current_page' => $deploys->currentPage(),
                'last_page' => $deploys->lastPage(),
                'per_page' => $deploys->perPage(),
                'total' => $deploys->total(),
            ],
        ]);
    }

    public function store(DeployRequest $request, FileDiffService $diff, PublishPath $paths): JsonResponse
    {
        if (config('queue.default') !== 'database' || (int) config('queue.connections.database.retry_after') <= 3660) {
            throw ValidationException::withMessages(['queue' => 'Configura QUEUE_CONNECTION=database y DB_QUEUE_RETRY_AFTER=3700 para desplegar sin bloquear o duplicar jobs.']);
        }
        $values = $request->validated();
        $project = Project::findOrFail($values['project_id']);
        $server = $project->servers()->where('servers.id', $values['server_id'])->first();
        if ($server === null) {
            throw ValidationException::withMessages(['server_id' => 'Asocia este servidor al proyecto antes de desplegar.']);
        }
        if ($project->builds()->whereIn('status', ['queued', 'running'])->exists()) {
            abort(409, 'Espera a que termine el build antes de desplegar.');
        }
        $build = isset($values['build_id']) ? Build::findOrFail($values['build_id']) : null;
        if ($build && ($build->project_id !== $project->id || $build->status !== 'success')) {
            throw ValidationException::withMessages(['build_id' => 'El build debe pertenecer al proyecto y haber finalizado correctamente.']);
        }
        try {
            $manifest = $diff->manifest($project, $server);
        } catch (FileManifestException|ProjectScanException $exception) {
            Log::warning('No se pudo validar el manifiesto para un deploy.', ['project_id' => $project->id, 'server_id' => $server->id, 'message' => $exception->getMessage()]);
            throw ValidationException::withMessages(['files' => $exception->getMessage()]);
        }
        if ($build) {
            try {
                $buildPath = LocalPath::directory($build->build_output_path);
            } catch (ProjectScanException) {
                throw ValidationException::withMessages(['build_id' => 'La salida local del build ya no existe. Vuelve a compilar o elige omitir el build.']);
            }
            if ($buildPath !== $manifest['source_path']) {
                throw ValidationException::withMessages(['build_id' => 'La salida local cambio desde el build seleccionado. Vuelve a compilar o elige omitir el build.']);
            }
        }
        $available = collect($manifest['files'])->keyBy('path');
        $selected = collect($values['files'])->map(function (string $path) use ($available) {
            $file = $available->get($path);
            if ($file === null) {
                throw ValidationException::withMessages(['files' => 'Un archivo seleccionado ya no existe o esta excluido: '.$path.'. Vuelve a cargar el arbol.']);
            }

            return $file;
        });
        $deleteObsolete = (bool) ($values['delete_obsolete'] ?? false);
        if ($selected->isEmpty() && (! $deleteObsolete || $manifest['obsolete_files'] === [])) {
            throw ValidationException::withMessages(['files' => 'Selecciona al menos un archivo para desplegar o activa la limpieza de archivos obsoletos.']);
        }
        if ($deleteObsolete) {
            $missingChanged = collect($manifest['files'])->filter(fn (array $file) => $file['changed'])
                ->pluck('path')->diff($selected->pluck('path'));
            if ($missingChanged->isNotEmpty()) {
                throw ValidationException::withMessages(['delete_obsolete' => 'Para limpiar archivos obsoletos, incluye todos los archivos nuevos o cambiados del build actual. Falta: '.$missingChanged->first().'.']);
            }
        }

        $deploy = DB::transaction(function () use ($project, $server, $values, $manifest, $selected, $build, $deleteObsolete, $paths): Deploy {
            $lockedProject = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $lockedServer = $lockedProject->servers()->where('servers.id', $server->id)->lockForUpdate()->first();
            if ($lockedServer === null) {
                throw ValidationException::withMessages(['server_id' => 'El servidor dejo de estar asociado al proyecto.']);
            }
            if (LocalPath::directory($lockedProject->build_output_path) !== $manifest['source_path']
                || $lockedProject->type !== $project->type
                || $paths->destinations($lockedProject, $lockedServer) !== $manifest['destinations']) {
                throw ValidationException::withMessages(['files' => 'El proyecto o el destino cambio mientras se preparaba el deploy. Vuelve a cargar el arbol.']);
            }
            if ($lockedProject->builds()->whereIn('status', ['queued', 'running'])->exists()) {
                abort(409, 'Espera a que termine el build antes de desplegar.');
            }
            if (Deploy::where('project_id', $lockedProject->id)->where('server_id', $lockedServer->id)->whereIn('status', ['queued', 'running'])->exists()) {
                abort(409, 'Ya hay un deploy en curso para este proyecto y servidor.');
            }

            $deploy = Deploy::create([
                'project_id' => $lockedProject->id, 'server_id' => $lockedServer->id, 'build_id' => $build?->id,
                'project_name' => $lockedProject->name, 'project_type' => $lockedProject->type,
                'server_name' => $lockedServer->name, 'source_path' => $manifest['source_path'],
                'version' => $values['version'], 'changes' => $values['changes'] ?? null,
                'status' => 'queued', 'progress' => 0, 'log' => '',
                'remote_path' => $manifest['destinations']['backend'], 'public_remote_path' => $manifest['destinations']['public'],
                'delete_obsolete' => $deleteObsolete,
            ]);
            foreach ($selected as $file) {
                $deploy->files()->create([
                    'relative_path' => $file['path'], 'remote_path' => $file['remote_path'],
                    'local_path' => $manifest['source_path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['path']),
                    'hash' => $file['hash'], 'size' => $file['size'], 'status' => 'queued',
                ]);
            }
            if ($deleteObsolete) {
                foreach ($manifest['obsolete_files'] as $file) {
                    $deploy->files()->create([
                        'relative_path' => $file['path'], 'remote_path' => $file['remote_path'],
                        'hash' => $file['hash'], 'size' => $file['size'], 'status' => 'pending_delete',
                    ]);
                }
            }
            DeployJob::dispatch($deploy->id);

            return $deploy->load('files');
        });

        return response()->json(['data' => $deploy], 202);
    }

    public function show(Deploy $deploy): JsonResponse
    {
        return response()->json(['data' => $deploy->load('files')]);
    }

    public function active(Request $request, Project $project): JsonResponse
    {
        $values = $request->validate(['server_id' => ['required', 'integer', 'exists:servers,id']]);
        $serverId = (int) $values['server_id'];
        if (! $project->servers()->where('servers.id', $serverId)->exists()) {
            throw ValidationException::withMessages(['server_id' => 'Asocia este servidor al proyecto antes de consultar sus deploys.']);
        }
        $deploy = Deploy::query()->where('project_id', $project->id)->where('server_id', $serverId)
            ->whereIn('status', ['queued', 'running'])->orderByDesc('id')->first();

        return response()->json(['data' => $deploy?->load('files')]);
    }
}
