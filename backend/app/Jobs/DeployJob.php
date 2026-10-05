<?php

namespace App\Jobs;

use App\Exceptions\DeployException;
use App\Exceptions\FtpTransferException;
use App\Models\Deploy;
use App\Models\DeployFile;
use App\Models\Project;
use App\Models\Server;
use App\Services\FtpTransferService;
use App\Services\LocalPath;
use App\Services\PublishPath;
use App\Services\VersionFileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeployJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $deployId) {}

    public function handle(FtpTransferService $ftp, VersionFileService $versions, PublishPath $paths): void
    {
        if (! Deploy::whereKey($this->deployId)->where('status', 'queued')->update([
            'status' => 'running', 'started_at' => now(), 'progress' => 1,
        ])) {
            return;
        }
        $deploy = Deploy::with('files')->findOrFail($this->deployId);
        $started = microtime(true);
        $log = '';
        $truncated = false;
        $append = function (string $line) use (&$log, &$truncated, $deploy): void {
            if ($truncated) {
                return;
            }
            $line = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $line);
            if (strlen($log) + strlen($line) > 2_000_000) {
                $marker = "\n[Log truncado: limite de 2 MB.]\n";
                $log = mb_strcut($log.$line, 0, 2_000_000 - strlen($marker), 'UTF-8').$marker;
                $truncated = true;
            } else {
                $log .= $line;
            }
            $deploy->update(['log' => $log]);
        };
        try {
            $server = Server::find($deploy->server_id);
            if ($server === null) {
                throw new DeployException('El servidor asociado ya no existe.');
            }
            $source = LocalPath::directory($deploy->source_path);
            $destinations = ['backend' => $deploy->remote_path, 'public' => $deploy->public_remote_path];
            if ($paths->validatePair($deploy->project_type, $deploy->remote_path, $deploy->public_remote_path) !== $destinations) {
                throw new DeployException('Los destinos guardados para el deploy no son validos.');
            }
            $uploads = $deploy->files->where('status', 'queued')->values()->all();
            usort($uploads, fn (DeployFile $a, DeployFile $b) => $this->entryPoint($a) <=> $this->entryPoint($b) ?: strcmp($a->relative_path, $b->relative_path));
            $deletions = $deploy->files->where('status', 'pending_delete')->values();
            $total = count($uploads) + $deletions->count() + 2;
            $completed = 0;
            $append("Deploy #{$deploy->id} iniciado para {$deploy->server_name}. No se ejecutan comandos remotos.\n");
            foreach ($uploads as $file) {
                $this->uploadFile($deploy, $server, $file, $source, $destinations, $paths, $ftp, $append);
                $completed++;
                $this->progress($deploy, $completed, $total);
            }
            if ($deploy->delete_obsolete) {
                foreach ($deletions as $file) {
                    $this->deleteObsolete($deploy, $server, $file, $source, $destinations, $paths, $ftp, $append);
                    $completed++;
                    $this->progress($deploy, $completed, $total);
                }
            }
            $append("Preparando archivo de version y proteccion de /_deploys/.\n");
            $name = $versions->upload($deploy->fresh(), $server);
            $deploy->update(['version_file_name' => $name, 'progress' => 99]);
            $append("Archivo de version subido: /_deploys/{$name}.\n");
            $deploy->update([
                'status' => 'success', 'progress' => 100, 'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'log' => $log,
            ]);
            if ($deploy->project_id !== null) {
                Project::whereKey($deploy->project_id)->update(['current_version' => $deploy->version]);
            }
            $append("Deploy completado correctamente.\n");
        } catch (Throwable $exception) {
            $message = $exception instanceof DeployException || $exception instanceof FtpTransferException
                ? $exception->getMessage()
                : 'El deploy fallo por un error local inesperado. Revisa permisos, almacenamiento y conexion FTP.';
            $deploy->files()->whereIn('status', ['uploading', 'deleting'])->update([
                'status' => 'failed', 'error' => $message,
            ]);
            $append("\nDeploy fallido: {$message}\n");
            $deploy->update([
                'status' => 'failed', 'finished_at' => now(), 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'log' => $log,
            ]);
            Log::error('Deploy fallido.', ['deploy_id' => $deploy->id, 'exception_type' => $exception::class]);
        } finally {
            $ftp->disconnect();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $deploy = Deploy::find($this->deployId);
        if ($deploy && in_array($deploy->status, ['queued', 'running'], true)) {
            $deploy->files()->whereIn('status', ['uploading', 'deleting'])->update([
                'status' => 'failed', 'error' => 'El worker no pudo completar la transferencia.',
            ]);
            $deploy->update([
                'status' => 'failed', 'finished_at' => now(),
                'log' => mb_strcut((string) $deploy->log, 0, 1_999_000, 'UTF-8')."\nEl worker no pudo completar el deploy. Revisa el worker y el estado del servidor.\n",
                'duration_ms' => $deploy->started_at ? (int) abs(now()->diffInMilliseconds($deploy->started_at)) : null,
            ]);
            Log::error('Job de deploy fallido.', ['deploy_id' => $this->deployId, 'exception_type' => $exception ? $exception::class : null]);
        }
    }

    private function uploadFile(Deploy $deploy, Server $server, DeployFile $file, string $source, array $destinations, PublishPath $paths, FtpTransferService $ftp, callable $append): void
    {
        try {
            if ($paths->file($file->relative_path, $destinations) !== $file->remote_path) {
                throw new DeployException('El destino guardado de '.$file->relative_path.' no coincide con la configuracion del deploy.');
            }
            $local = $source.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file->relative_path);
            if ($local !== $file->local_path || is_link($local) || realpath($local) === false || ! is_file($local) || ! is_readable($local)) {
                throw new DeployException('El archivo local ya no existe o cambio de tipo: '.$file->relative_path.'.');
            }
            $resolved = realpath($local);
            if ($resolved === false || ! str_starts_with(strtolower($resolved), strtolower(rtrim($source, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))) {
                throw new DeployException('Se rechazo una ruta de archivo fuera del origen: '.$file->relative_path.'.');
            }
            clearstatcache(true, $local);
            $size = filesize($local);
            $hash = hash_file('sha1', $local);
            if ($size === false || $hash === false || $size !== $file->size || ! hash_equals($file->hash, $hash)) {
                throw new DeployException('El archivo cambio despues de seleccionarlo: '.$file->relative_path.'. Vuelve a cargar los archivos.');
            }
            $file->update(['status' => 'uploading']);
            $append('Subiendo '.$file->relative_path.' ('.$size." bytes).\n");
            $ftp->ensureDirectory($server, dirname($file->remote_path));
            if ($ftp->fileExists($server, $file->remote_path)) {
                $backup = $this->backupPath($deploy, $file->relative_path, false);
                $ftp->download($server, $file->remote_path, $backup['absolute']);
                if (! is_file($backup['absolute'])) {
                    throw new DeployException('No se creo el respaldo previo a reemplazar '.$file->relative_path.'.');
                }
                $file->update(['backup_path' => $backup['relative']]);
            }
            $ftp->upload($server, $local, $file->remote_path);
            $file->update(['status' => 'uploaded', 'error' => null]);
        } catch (Throwable $exception) {
            $file->update(['status' => 'failed', 'error' => $this->failureReason($exception)]);
            throw $exception;
        }
    }

    private function deleteObsolete(Deploy $deploy, Server $server, DeployFile $file, string $source, array $destinations, PublishPath $paths, FtpTransferService $ftp, callable $append): void
    {
        try {
            if ($paths->file($file->relative_path, $destinations) !== $file->remote_path) {
                throw new DeployException('Se rechazo una ruta remota de archivo obsoleto.');
            }
            $local = $source.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file->relative_path);
            if (file_exists($local) || is_link($local)) {
                $file->update(['status' => 'skipped', 'error' => 'El archivo reaparecio en el origen; se conservo en el servidor.']);
                $append('Se conserva '.$file->remote_path.' porque la ruta reaparecio en el origen local.'."\n");

                return;
            }
            $file->update(['status' => 'deleting']);
            $append('Verificando archivo obsoleto '.$file->remote_path.".\n");
            if (! $ftp->fileExists($server, $file->remote_path)) {
                $file->update(['status' => 'absent', 'error' => 'El archivo ya no estaba en el servidor.']);
                $append('Ya estaba ausente: '.$file->remote_path.".\n");

                return;
            }
            $backup = $this->backupPath($deploy, $file->relative_path, true);
            $ftp->download($server, $file->remote_path, $backup['absolute']);
            $remoteHash = hash_file('sha1', $backup['absolute']);
            if ($remoteHash === false) {
                throw new DeployException('No se pudo verificar el respaldo de '.$file->remote_path.'.');
            }
            $file->update(['backup_path' => $backup['relative']]);
            if (! hash_equals($file->hash, $remoteHash)) {
                $file->update(['status' => 'skipped', 'error' => 'El archivo remoto fue modificado externamente; se respaldo y se conservo.']);
                $append('Se conserva '.$file->remote_path.' porque el contenido remoto difiere del ultimo upload registrado.'."\n");

                return;
            }
            if ($ftp->delete($server, $file->remote_path)) {
                $file->update(['status' => 'deleted', 'error' => null]);
                $append('Eliminado y respaldado: '.$file->remote_path.".\n");
            } else {
                $file->update(['status' => 'absent', 'error' => 'El archivo desaparecio antes de borrarlo.']);
                $append('Ya estaba ausente: '.$file->remote_path.".\n");
            }
        } catch (Throwable $exception) {
            $file->update(['status' => 'failed', 'error' => $this->failureReason($exception)]);
            throw $exception;
        }
    }

    private function failureReason(Throwable $exception): string
    {
        return $exception instanceof DeployException || $exception instanceof FtpTransferException
            ? $exception->getMessage()
            : 'La transferencia fallo por un error local inesperado.';
    }

    private function backupPath(Deploy $deploy, string $relativePath, bool $obsolete): array
    {
        $folder = 'backups'.DIRECTORY_SEPARATOR.$deploy->id.DIRECTORY_SEPARATOR.($obsolete ? 'obsolete' : 'files');
        $absolute = storage_path('app'.DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        File::ensureDirectoryExists(dirname($absolute));

        return ['absolute' => $absolute, 'relative' => str_replace(DIRECTORY_SEPARATOR, '/', $folder).'/'.$relativePath];
    }

    private function progress(Deploy $deploy, int $completed, int $total): void
    {
        $deploy->update(['progress' => min(98, 2 + (int) floor(($completed / max(1, $total)) * 95))]);
    }

    private function entryPoint(DeployFile $file): int
    {
        return in_array(strtolower(basename($file->remote_path)), ['index.html', 'index.php'], true) ? 1 : 0;
    }
}
