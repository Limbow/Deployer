<?php

namespace App\Services;

use App\Exceptions\ProjectScanException;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;

class ProjectVersionService
{
    private const PACKAGE_FILES = ['package.json', 'package-lock.json', 'npm-shrinkwrap.json'];

    public function __construct(private readonly WorkspaceJson $json) {}

    public function bump(Project $project, string $type): array
    {
        if ($project->type === 'laravel') {
            return ['version' => $this->bumpLaravelVersion($project->current_version, $type), 'updated_files' => []];
        }

        if ($project->type !== 'angular') {
            throw ValidationException::withMessages(['type' => 'Solo se pueden incrementar versiones de proyectos Laravel o Angular.']);
        }

        try {
            $path = LocalPath::directory($project->local_path);
        } catch (ProjectScanException $exception) {
            throw ValidationException::withMessages(['type' => $exception->getMessage()]);
        }

        $packagePath = $path.DIRECTORY_SEPARATOR.'package.json';
        if (is_link($packagePath)) {
            throw ValidationException::withMessages(['type' => 'package.json no puede ser un enlace simbólico.']);
        }
        try {
            $package = $this->json->read($packagePath);
        } catch (ProjectScanException $exception) {
            throw ValidationException::withMessages(['type' => $exception->getMessage()]);
        }
        if (! is_string($package['version'] ?? null) || trim($package['version']) === '') {
            throw ValidationException::withMessages(['type' => 'package.json debe incluir una versión válida antes de incrementarla.']);
        }

        $before = $this->packageFileHashes($path);

        try {
            $result = Process::path($path)
                ->timeout(60)
                ->env(['PATH' => getenv('PATH') ?: ''])
                ->run([
                    $this->npmExecutable(),
                    'version',
                    $type,
                    '--no-git-tag-version',
                    '--ignore-scripts',
                ]);
        } catch (ProcessTimedOutException) {
            Log::warning('Se agotó el tiempo al incrementar la versión del proyecto.', ['project_id' => $project->id]);
            throw ValidationException::withMessages(['type' => 'npm tardó demasiado al incrementar la versión. Verifica el proyecto y vuelve a intentarlo.']);
        } catch (ProcessStartFailedException) {
            Log::warning('No se pudo iniciar npm para incrementar la versión del proyecto.', ['project_id' => $project->id]);
            throw ValidationException::withMessages(['type' => 'No se pudo iniciar npm. Verifica que Node.js y npm estén instalados y accesibles desde la ruta configurada de npx.']);
        }

        if (! $result->successful()) {
            Log::warning('npm no pudo incrementar la versión del proyecto.', [
                'project_id' => $project->id,
                'exit_code' => $result->exitCode(),
            ]);
            throw ValidationException::withMessages(['type' => 'npm no pudo incrementar la versión. Revisa package.json y el log local de npm.']);
        }

        try {
            $updatedPackage = $this->json->read($packagePath);
        } catch (ProjectScanException $exception) {
            Log::warning('npm dejó un package.json ilegible al incrementar la versión.', ['project_id' => $project->id]);
            throw ValidationException::withMessages(['type' => 'npm terminó, pero no se pudo volver a leer package.json. Revisa el archivo antes de continuar.']);
        }

        $version = $updatedPackage['version'] ?? null;
        if (! is_string($version) || trim($version) === '') {
            throw ValidationException::withMessages(['type' => 'npm terminó, pero package.json no contiene una versión válida.']);
        }

        $after = $this->packageFileHashes($path);
        $updatedFiles = array_values(array_filter(
            self::PACKAGE_FILES,
            fn (string $file) => ($before[$file] ?? null) !== ($after[$file] ?? null),
        ));

        return ['version' => $version, 'updated_files' => $updatedFiles];
    }

    private function bumpLaravelVersion(string $version, string $type): string
    {
        if (! preg_match('/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/', $version, $matches)) {
            throw ValidationException::withMessages(['type' => 'La versión de Laravel debe tener formato MAJOR.MINOR.PATCH para poder incrementarla.']);
        }

        [$major, $minor, $patch] = [$matches[1], $matches[2], $matches[3]];

        return match ($type) {
            'major' => $this->incrementDecimal($major).'.0.0',
            'minor' => $major.'.'.$this->incrementDecimal($minor).'.0',
            'patch' => $major.'.'.$minor.'.'.$this->incrementDecimal($patch),
        };
    }

    private function incrementDecimal(string $value): string
    {
        $digits = str_split($value);
        for ($index = count($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = chr(ord($digits[$index]) + 1);

                return implode('', $digits);
            }
            $digits[$index] = '0';
        }

        return '1'.implode('', $digits);
    }

    private function packageFileHashes(string $path): array
    {
        $hashes = [];
        foreach (self::PACKAGE_FILES as $file) {
            $fullPath = $path.DIRECTORY_SEPARATOR.$file;
            if (is_link($fullPath)) {
                throw ValidationException::withMessages(['type' => $file.' no puede ser un enlace simbólico.']);
            }
            $hash = is_file($fullPath) ? hash_file('sha256', $fullPath) : null;
            if (is_file($fullPath) && $hash === false) {
                throw ValidationException::withMessages(['type' => 'No se pudo leer '.$file.' para verificar el cambio de versión.']);
            }
            $hashes[$file] = $hash;
        }

        return $hashes;
    }

    private function npmExecutable(): string
    {
        $binary = PHP_OS_FAMILY === 'Windows' ? 'npm.cmd' : 'npm';
        $npxPath = Setting::values()['npx_path'] ?? '';

        if (! str_contains($npxPath, '/') && ! str_contains($npxPath, '\\')) {
            return $binary;
        }

        return dirname($npxPath).DIRECTORY_SEPARATOR.$binary;
    }
}
