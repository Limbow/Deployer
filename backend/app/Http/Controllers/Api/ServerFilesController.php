<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FtpConnectionException;
use App\Http\Controllers\Controller;
use App\Models\Deploy;
use App\Models\Server;
use App\Services\RemoteFileBrowserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ServerFilesController extends Controller
{
    public function index(Request $request, Server $server, RemoteFileBrowserService $files): JsonResponse
    {
        $values = $request->validate([
            'path' => ['sometimes', 'string', 'max:1024', 'not_regex:/[\x00-\x1F\x7F\\\\]/'],
        ]);
        try {
            return response()->json(['data' => $files->browse($server, $values['path'] ?? '')]);
        } catch (FtpConnectionException $exception) {
            return $this->connectionError($server, $exception);
        }
    }

    public function download(Request $request, Server $server, RemoteFileBrowserService $files): BinaryFileResponse|JsonResponse
    {
        $values = $request->validate([
            'path' => ['required', 'string', 'max:1024', 'not_regex:/[\x00-\x1F\x7F\\\\]/'],
        ]);
        try {
            return $files->downloadFile($server, $values['path']);
        } catch (FtpConnectionException $exception) {
            return $this->connectionError($server, $exception);
        }
    }

    public function destroy(Request $request, Server $server, RemoteFileBrowserService $files): JsonResponse
    {
        $values = $request->validate([
            'path' => ['required', 'string', 'max:1024', 'not_regex:/[\x00-\x1F\x7F\\\\]/'],
        ]);
        if (Deploy::query()->where('server_id', $server->id)->whereIn('status', ['queued', 'running'])->exists()) {
            abort(409, 'Espera a que termine el deploy activo de este servidor antes de borrar archivos.');
        }
        try {
            return response()->json(['data' => $files->deleteFile($server, $values['path'])]);
        } catch (FtpConnectionException $exception) {
            return $this->connectionError($server, $exception);
        }
    }

    private function connectionError(Server $server, FtpConnectionException $exception): JsonResponse
    {
        Log::warning('Operacion del explorador FTP fallida.', [
            'server_id' => $server->id,
            'reason' => $exception->getMessage(),
        ]);

        return response()->json(['message' => $exception->getMessage()], 502);
    }
}
