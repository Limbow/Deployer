<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FtpConnectionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServerRequest;
use App\Models\Deploy;
use App\Models\Server;
use App\Services\FtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ServerController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Server::query()->orderBy('name')->orderBy('id')->get()]);
    }

    public function store(ServerRequest $request): JsonResponse
    {
        $server = Server::create($request->validated());

        return response()->json(['data' => $server], 201);
    }

    public function show(Server $server): JsonResponse
    {
        return response()->json(['data' => $server]);
    }

    public function update(ServerRequest $request, Server $server): JsonResponse
    {
        $attributes = $request->validated();

        if (array_key_exists('password', $attributes) && ($attributes['password'] === null || $attributes['password'] === '')) {
            unset($attributes['password']);
        }

        $server->update($attributes);

        return response()->json(['data' => $server]);
    }

    public function destroy(Server $server): Response
    {
        if (Deploy::where('server_id', $server->id)->whereIn('status', ['queued', 'running'])->exists()) {
            abort(409, 'Espera a que termine el deploy antes de eliminar este servidor.');
        }
        $server->delete();

        return response()->noContent();
    }

    public function test(Server $server, FtpService $ftp): JsonResponse
    {
        try {
            return response()->json(['data' => $ftp->test($server)]);
        } catch (FtpConnectionException $exception) {
            Log::warning('Prueba FTP fallida.', ['server_id' => $server->id, 'reason' => $exception->getMessage()]);

            return response()->json(['message' => $exception->getMessage()], 502);
        }
    }
}
