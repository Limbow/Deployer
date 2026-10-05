<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProjectScanException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BumpProjectVersionRequest;
use App\Http\Requests\ProjectRequest;
use App\Models\Build;
use App\Models\Deploy;
use App\Models\Project;
use App\Models\Setting;
use App\Services\LocalPath;
use App\Services\ProjectScanner;
use App\Services\ProjectVersionService;
use App\Services\ProjectVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function index(Request $request, ProjectVisibility $visibility): JsonResponse
    {
        $settings = Setting::values();
        $projects = Project::with('servers')->orderBy('name')->orderBy('id')->get()->map(function (Project $project) use ($visibility, $settings): Project {
            $project->setAttribute('hidden_reason', $visibility->reason($project->local_path, $project->target_platform, $settings));

            return $project;
        });

        return response()->json(['data' => $projects->filter(fn (Project $project) => $request->boolean('include_hidden') || $project->hidden_reason === null)->values()]);
    }

    public function scan(Request $request, ProjectScanner $scanner, ProjectVisibility $visibility): JsonResponse
    {
        try {
            $result = $scanner->scan(Setting::values()['projects_root']);
        } catch (ProjectScanException $exception) {
            throw ValidationException::withMessages(['projects_root' => $exception->getMessage()]);
        }

        $saved = Project::query()->get(['id', 'local_path', 'angular_project'])->keyBy('local_path');
        $settings = Setting::values();
        $result['projects'] = array_map(function (array $candidate) use ($saved, $visibility, $settings): array {
            $project = $saved->get($candidate['local_path']);

            return [...$candidate, 'registered_id' => $project?->id, 'registered_angular_project' => $project?->angular_project,
                'hidden_reason' => $visibility->reason($candidate['local_path'], $candidate['target_platform'], $settings)];
        }, $result['projects']);
        $result['hidden_count'] = count(array_filter($result['projects'], fn (array $candidate) => $candidate['hidden_reason'] !== null));
        if (! $request->boolean('include_hidden')) {
            $result['projects'] = array_values(array_filter($result['projects'], fn (array $candidate) => $candidate['hidden_reason'] === null));
        }
        foreach ($result['issues'] as $issue) {
            Log::warning('Problema al escanear un proyecto.', $issue);
        }

        return response()->json(['data' => $result]);
    }

    public function store(ProjectRequest $request, ProjectScanner $scanner): JsonResponse
    {
        $attributes = $this->attributes($request, $scanner);
        $project = DB::transaction(function () use ($attributes, $request): Project {
            $project = Project::create($attributes);
            $this->syncServers($project, $request);

            return $project;
        });

        return response()->json(['data' => $project->load('servers')], 201);
    }

    public function show(Project $project): JsonResponse
    {
        return response()->json(['data' => $project->load('servers')]);
    }

    public function update(ProjectRequest $request, Project $project, ProjectScanner $scanner): JsonResponse
    {
        $attributes = $this->attributes($request, $scanner, $project);
        DB::transaction(function () use ($project, $attributes, $request): void {
            $project->update($attributes);
            $this->syncServers($project, $request);
        });

        return $this->show($project);
    }

    public function destroy(Project $project): Response
    {
        DB::transaction(function () use ($project): void {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (Build::where('project_id', $project->id)->whereIn('status', ['queued', 'running'])->exists()
                || Deploy::where('project_id', $project->id)->whereIn('status', ['queued', 'running'])->exists()) {
                abort(409, 'Espera a que terminen el build y el deploy antes de eliminar este proyecto.');
            }
            $project->delete();
        });

        return response()->noContent();
    }

    public function bumpVersion(BumpProjectVersionRequest $request, Project $project, ProjectVersionService $versions): JsonResponse
    {
        $type = $request->validated('type');
        $result = DB::transaction(function () use ($project, $type, $versions): array {
            $lockedProject = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($lockedProject->builds()->whereIn('status', ['queued', 'running'])->exists()
                || Deploy::where('project_id', $lockedProject->id)->whereIn('status', ['queued', 'running'])->exists()) {
                abort(409, 'Espera a que terminen el build y el deploy antes de incrementar la versión.');
            }

            $version = $versions->bump($lockedProject, $type);
            $lockedProject->update(['current_version' => $version['version']]);

            return [
                'project' => $lockedProject->load('servers'),
                'updated_files' => $version['updated_files'],
            ];
        });

        return response()->json(['data' => $result]);
    }

    public function visibility(Request $request, ProjectVisibility $visibility): JsonResponse
    {
        $values = $request->validate([
            'local_path' => ['required', 'string', 'max:768'],
            'hidden' => ['required', 'boolean'],
        ]);
        try {
            $path = LocalPath::absolute($values['local_path'], Setting::values()['projects_root']);
        } catch (ProjectScanException $exception) {
            throw ValidationException::withMessages(['local_path' => $exception->getMessage()]);
        }
        $key = $visibility->key($path);
        DB::transaction(function () use ($values, $path, $key, $visibility): void {
            $settings = Setting::values();
            foreach (['hidden_project_paths', 'visible_project_paths'] as $setting) {
                $paths = array_values(array_filter($settings[$setting], fn (string $existing) => $visibility->key($existing) !== $key));
                if (($setting === 'hidden_project_paths') === (bool) $values['hidden']) {
                    $paths[] = $path;
                }
                Setting::updateOrCreate(['key' => $setting], ['value' => $paths]);
            }
        });

        return response()->json(['data' => ['local_path' => $path, 'hidden' => (bool) $values['hidden']]]);
    }

    private function attributes(ProjectRequest $request, ProjectScanner $scanner, ?Project $project = null): array
    {
        $values = $request->safe()->except('servers');
        $path = $values['local_path'] ?? $project?->local_path;
        $requiresInspection = $project === null || $request->has('local_path') || $request->has('angular_project');

        if ($requiresInspection) {
            try {
                $workspace = $scanner->inspect($path);
            } catch (ProjectScanException $exception) {
                throw ValidationException::withMessages(['local_path' => $exception->getMessage()]);
            }

            $selected = $values['angular_project'] ?? $project?->angular_project;
            $applications = $workspace['applications'];
            $type = $applications[0]['type'] ?? null;
            if ($type === 'laravel') {
                $selected = null;
            } elseif ($selected === null && count($applications) === 1) {
                $selected = $applications[0]['angular_project'];
            }
            $application = collect($applications)->first(fn (array $candidate) => $candidate['angular_project'] === $selected);
            if ($application === null) {
                throw ValidationException::withMessages(['angular_project' => 'Selecciona una aplicacion valida del workspace. Si hay varias, indica angular_project.']);
            }

            $defaults = [
                'type' => $application['type'],
                'target_platform' => $application['target_platform'],
                'name' => $application['name'],
                'angular_project' => $selected,
                'build_output_path' => $application['build_output_path'],
                'build_command' => $application['build_command'],
                'ignore_patterns' => $type === 'laravel' ? [
                    '.env', '.env.*', '.git', '.git/*', 'node_modules', 'node_modules/*',
                    'storage', 'storage/*', 'bootstrap/cache/*.php', 'public/storage', 'public/storage/*',
                    'tests', 'tests/*', '*.log',
                ] : ['*.map'],
                'current_version' => $workspace['current_version'],
            ];
            if ($project !== null) {
                $preserved = $project->only(['name', 'ignore_patterns']);
                if ($workspace['local_path'] === $project->local_path && $selected === $project->angular_project) {
                    $preserved = $project->only(array_keys($defaults));
                }
                $defaults = array_replace($defaults, $preserved);
            }
            if (isset($values['type']) && $values['type'] !== $type) {
                throw ValidationException::withMessages(['type' => 'El tipo no coincide con el proyecto detectado en esta carpeta.']);
            }
            $values = array_replace($defaults, $values, ['local_path' => $workspace['local_path'], 'angular_project' => $selected, 'type' => $type, 'target_platform' => $application['target_platform']]);
        }

        $type = $values['type'] ?? $project?->type;
        if ($project !== null && isset($values['type']) && ! $requiresInspection && $values['type'] !== $project->type) {
            throw ValidationException::withMessages(['type' => 'Para cambiar de tipo, selecciona la carpeta del nuevo proyecto.']);
        }
        if ($type === 'angular' && array_key_exists('build_command', $values) && $values['build_command'] === null) {
            throw ValidationException::withMessages(['build_command' => 'Angular requiere un comando de build.']);
        }
        if ($type === 'angular' && $request->exists('angular_project') && $request->input('angular_project') === null) {
            throw ValidationException::withMessages(['angular_project' => 'Angular requiere seleccionar una aplicacion.']);
        }
        if ($type === 'angular' && $request->has('servers')) {
            foreach ($request->validated('servers', []) as $server) {
                if (! empty($server['public_remote_path'])) {
                    throw ValidationException::withMessages(['servers' => 'La ruta public separada solo corresponde a Laravel.']);
                }
            }
        }

        if (isset($values['build_output_path'])) {
            try {
                $values['build_output_path'] = LocalPath::absolute($values['build_output_path'], $path);
            } catch (ProjectScanException $exception) {
                throw ValidationException::withMessages(['build_output_path' => $exception->getMessage()]);
            }
            if (strlen($values['build_output_path']) > 2048) {
                throw ValidationException::withMessages(['build_output_path' => 'La ruta de salida resuelta es demasiado larga.']);
            }
        }

        if ($type === 'laravel') {
            $source = $values['build_output_path'] ?? $project?->build_output_path;
            if ($source !== LocalPath::absolute($path, $path)) {
                throw ValidationException::withMessages(['build_output_path' => 'Laravel usa la raiz del proyecto como origen; public se enruta por separado.']);
            }
            foreach ($request->validated('servers', []) as $server) {
                $backendPath = $server['remote_path_override'] ?? null;
                if ($backendPath === null || $backendPath === '/' || preg_match('~(?:^|/)(?:public_html|\.\.?)(?:/|$)~i', $backendPath)) {
                    throw ValidationException::withMessages(['servers' => 'Laravel requiere una ruta remota del backend fuera de public_html.']);
                }
                $publicPath = $server['public_remote_path'] ?? null;
                if ($publicPath !== null && (preg_match('~(?:^|/)\.\.?(?:/|$)~', $publicPath) || $publicPath === '/')) {
                    throw ValidationException::withMessages(['servers' => 'La ruta public debe ser una carpeta especifica sin segmentos . o ...']);
                }
                if ($publicPath !== null && (rtrim($backendPath, '/') === rtrim($publicPath, '/') || str_starts_with(rtrim($backendPath, '/').'/', rtrim($publicPath, '/').'/') || str_starts_with(rtrim($publicPath, '/').'/', rtrim($backendPath, '/').'/'))) {
                    throw ValidationException::withMessages(['servers' => 'El backend y public deben usar destinos diferentes.']);
                }
            }
        }

        return $values;
    }

    private function syncServers(Project $project, ProjectRequest $request): void
    {
        if (! $request->has('servers')) {
            return;
        }
        $relations = [];
        foreach ($request->validated('servers', []) as $server) {
            $relations[$server['server_id']] = [
                'label' => $server['label'],
                'remote_path_override' => $server['remote_path_override'] ?? null,
                'public_remote_path' => $server['public_remote_path'] ?? null,
            ];
        }
        $project->servers()->sync($relations);
    }
}
