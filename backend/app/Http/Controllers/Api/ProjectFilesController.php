<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FileManifestException;
use App\Exceptions\FtpConnectionException;
use App\Exceptions\ProjectScanException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\FileDiffService;
use App\Services\ProjectRemoteFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProjectFilesController extends Controller
{
    public function remote(Request $request, Project $project, ProjectRemoteFilesService $files): JsonResponse
    {
        $values = $request->validate([
            'server_id' => ['required', 'integer', 'exists:servers,id'],
            'destination' => ['sometimes', 'in:backend,public'],
            'path' => ['sometimes', 'nullable', 'string', 'max:1024'],
        ]);
        $server = $project->servers()->where('servers.id', $values['server_id'])->first();
        if ($server === null) {
            throw ValidationException::withMessages(['server_id' => 'Asocia este servidor al proyecto antes de seleccionar archivos.']);
        }
        try {
            return response()->json(['data' => $files->browse($project, $server, $values['destination'] ?? 'backend', $values['path'] ?? '')]);
        } catch (FtpConnectionException $exception) {
            Log::warning('No se pudo listar el destino del deploy.', ['project_id' => $project->id, 'server_id' => $server->id, 'message' => $exception->getMessage()]);

            return response()->json(['message' => $exception->getMessage()], 502);
        } catch (FileManifestException|ProjectScanException $exception) {
            throw ValidationException::withMessages(['path' => $exception->getMessage()]);
        }
    }

    public function index(Request $request, Project $project, FileDiffService $files): JsonResponse
    {
        $values = $request->validate(['server_id' => ['required', 'integer', 'exists:servers,id']]);
        $server = $project->servers()->where('servers.id', $values['server_id'])->first();
        if ($server === null) {
            throw ValidationException::withMessages(['server_id' => 'Asocia este servidor al proyecto antes de seleccionar archivos.']);
        }
        if ($project->builds()->whereIn('status', ['queued', 'running'])->exists()) {
            abort(409, 'Espera a que termine el build antes de leer sus archivos.');
        }
        try {
            return response()->json(['data' => $files->manifest($project, $server)]);
        } catch (FileManifestException|ProjectScanException $exception) {
            Log::warning('No se pudo generar el arbol de archivos.', ['project_id' => $project->id, 'server_id' => $server->id, 'message' => $exception->getMessage()]);
            throw ValidationException::withMessages(['files' => $exception->getMessage()]);
        }
    }
}
