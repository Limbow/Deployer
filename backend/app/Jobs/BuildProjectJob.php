<?php

namespace App\Jobs;

use App\Exceptions\ProjectScanException;
use App\Models\Build;
use App\Services\BuildCommand;
use App\Services\LocalPath;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

class BuildProjectJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3660;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $buildId, public string $npxPath) {}

    public function handle(BuildCommand $commands): void
    {
        if (! Build::whereKey($this->buildId)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()])) {
            return;
        }
        $build = Build::findOrFail($this->buildId);
        $start = microtime(true);
        $log = '';
        $lastFlush = $start;
        $persistedLog = '';
        $process = null;
        $exitCode = null;
        $truncated = false;
        $append = function (string $text) use (&$log, &$truncated): void {
            if ($truncated) {
                return;
            }
            $text = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $text);
            if (strlen($log) + strlen($text) > 2_000_000) {
                $marker = "\n[Log truncado: limite de 2 MB.]\n";
                $log = mb_strcut($log.$text, 0, 2_000_000 - strlen($marker), 'UTF-8').$marker;
                $truncated = true;
            } else {
                $log .= $text;
            }
        };

        try {
            $path = LocalPath::directory($build->local_path);
            $command = $commands->resolve($build->command, $this->npxPath);
            $environment = ['CI' => 'true', 'NG_CLI_ANALYTICS' => 'false', 'NO_COLOR' => '1'];
            $systemPath = getenv('PATH');
            if ($systemPath !== false) {
                $environment['PATH'] = $systemPath;
            }
            if (is_file($this->npxPath)) {
                $environment['PATH'] = dirname($this->npxPath).PATH_SEPARATOR.($systemPath ?: '');
            }
            $append("Build iniciado. No se subiran archivos.\n");
            $build->update(['log' => $log]);
            $persistedLog = $log;
            $process = Process::path($path)->timeout(3600)->env($environment)->start($command,
                function (string $type, string $output) use ($append): void {
                    $append(mb_convert_encoding($output, 'UTF-8', 'UTF-8'));
                });
            while ($process->running()) {
                $process->ensureNotTimedOut();
                if (microtime(true) - $lastFlush >= 1 && $log !== $persistedLog) {
                    $build->update(['log' => $log]);
                    $persistedLog = $log;
                    $lastFlush = microtime(true);
                }
                usleep(100_000);
            }
            $result = $process->wait();
            $exitCode = $result->exitCode();
            if (! $result->successful()) {
                $append("\nEl comando termino con error (codigo ".$exitCode.").\n");
                $status = 'failed';
                Log::warning('Comando de build fallido.', ['build_id' => $build->id, 'exit_code' => $exitCode]);
            } else {
                if ($build->project_type === 'angular' && ! is_file($build->build_output_path.DIRECTORY_SEPARATOR.'index.html')) {
                    throw new ProjectScanException('El comando termino, pero falta index.html en la carpeta de salida configurada. Revisa outputPath.');
                }
                $append("\nBuild completado correctamente.\n");
                $status = 'success';
            }
            $build->update([
                'status' => $status, 'log' => $log, 'exit_code' => $exitCode,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000), 'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            if ($process?->running()) {
                $process->stop(1);
            }
            $message = $exception instanceof ProjectScanException ? $exception->getMessage() : 'No se pudo ejecutar o verificar el build. Revisa el comando, la carpeta de salida, Node y la ruta de npx.';
            // Process exception messages can include the command/environment; keep those out of the API and logs.
            if ($exception instanceof ProcessTimedOutException) {
                $message = 'El build excedio el limite de 3600 segundos y fue detenido.';
            }
            $append("\n".$message."\n");
            $build->update([
                'status' => 'failed', 'log' => $log, 'finished_at' => now(), 'exit_code' => $exitCode,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);
            Log::error('Build fallido.', ['build_id' => $build->id, 'exception_type' => $exception::class]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $build = Build::find($this->buildId);
        if ($build && in_array($build->status, ['queued', 'running'], true)) {
            $build->update([
                'status' => 'failed', 'finished_at' => now(),
                'log' => mb_strcut($build->log, 0, 1_999_000, 'UTF-8')."\nEl worker no pudo completar el job. Revisa el worker y vuelve a intentar.\n",
                'duration_ms' => $build->started_at ? (int) abs(now()->diffInMilliseconds($build->started_at)) : null,
            ]);
            Log::error('Job de build fallido.', ['build_id' => $this->buildId, 'exception_type' => $exception ? $exception::class : null]);
        }
    }
}
