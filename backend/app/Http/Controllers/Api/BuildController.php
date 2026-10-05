<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BuildProjectJob;
use App\Models\Build;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BuildController extends Controller
{
    public function store(Project $project): JsonResponse
    {
        if (config('queue.default') !== 'database') {
            throw ValidationException::withMessages(['queue' => 'Configura QUEUE_CONNECTION=database para ejecutar builds sin bloquear la API.']);
        }
        if ((int) config('queue.connections.database.retry_after') <= 3660) {
            throw ValidationException::withMessages(['queue' => 'DB_QUEUE_RETRY_AFTER debe ser mayor que 3660 para evitar ejecuciones simultaneas del mismo job. Usa 3700.']);
        }
        $build = DB::transaction(function () use ($project): Build {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (trim($project->build_command ?? '') === '') {
                throw ValidationException::withMessages(['build_command' => 'Este proyecto no tiene comando de build. Laravel puede continuar sin compilar; configura un comando si necesitas generar assets.']);
            }
            $active = Build::where('project_id', $project->id)->whereIn('status', ['queued', 'running'])->first();
            if ($active) {
                abort(409, 'Ya hay un build pendiente o en ejecucion para este proyecto ('.$active->id.').');
            }
            $build = Build::create([
                'project_id' => $project->id, 'project_type' => $project->type, 'status' => 'queued',
                'local_path' => $project->local_path, 'build_output_path' => $project->build_output_path,
                'command' => $project->build_command, 'log' => '',
            ]);
            // The database queue insert shares this transaction, so a failed dispatch leaves no orphan build.
            BuildProjectJob::dispatch($build->id, Setting::values()['npx_path']);

            return $build;
        });

        return response()->json(['data' => $build->refresh()], 202);
    }

    public function show(Build $build): JsonResponse
    {
        return response()->json(['data' => $build]);
    }

    public function latest(Project $project): JsonResponse
    {
        return response()->json(['data' => Build::where('project_id', $project->id)->latest('id')->first()]);
    }
}
