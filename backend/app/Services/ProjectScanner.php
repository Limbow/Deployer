<?php

namespace App\Services;

use App\Exceptions\ProjectScanException;
use Illuminate\Support\Facades\File;

class ProjectScanner
{
    public function __construct(private WorkspaceJson $json) {}

    public function inspect(string $directory): array
    {
        $directory = LocalPath::directory($directory);
        if (! is_file($directory.DIRECTORY_SEPARATOR.'angular.json')) {
            return $this->inspectLaravel($directory);
        }
        $workspace = $this->json->read($directory.DIRECTORY_SEPARATOR.'angular.json');
        $package = $this->json->read($directory.DIRECTORY_SEPARATOR.'package.json');
        $version = $package['version'] ?? null;

        if (! is_string($version) || trim($version) === '' || strlen($version) > 255) {
            throw new ProjectScanException('package.json debe incluir una version de texto no vacia.');
        }

        $projects = $workspace['projects'] ?? null;

        if (! is_array($projects) || $projects === []) {
            throw new ProjectScanException('angular.json no contiene proyectos.');
        }

        $applications = [];
        $issues = [];

        foreach ($projects as $name => $project) {
            if (! is_array($project) || ($project['projectType'] ?? null) === 'library') {
                continue;
            }

            $build = $project['architect']['build'] ?? $project['targets']['build'] ?? null;
            if (! is_array($build)) {
                continue;
            }

            try {
                $applications[] = [
                    'type' => 'angular',
                    'target_platform' => $this->platform($directory, $package),
                    'angular_project' => (string) $name,
                    'name' => (string) $name,
                    'builder' => $build['builder'] ?? '',
                    'build_output_path' => $this->outputPath($directory, (string) $name, $build),
                    'build_command' => 'npx ng build '.escapeshellarg((string) $name).' --configuration production',
                ];
            } catch (ProjectScanException $exception) {
                $issues[] = ['angular_project' => (string) $name, 'message' => $exception->getMessage()];
            }
        }

        if ($applications === [] && $issues === []) {
            throw new ProjectScanException('El workspace no contiene aplicaciones Angular con un target build.');
        }

        return [
            'local_path' => $directory,
            'current_version' => $version,
            'applications' => $applications,
            'issues' => $issues,
        ];
    }

    public function scan(string $root): array
    {
        $root = LocalPath::directory($root);
        $pending = [$root];
        $found = [];
        $issues = [];
        $visited = 0;
        $excluded = ['node_modules', 'vendor', 'dist', 'build', 'out', 'coverage', '.git', '.angular', 'storage'];
        $self = realpath(base_path('..'));

        while ($pending !== []) {
            $directory = array_pop($pending);
            if (++$visited > 5000) {
                throw new ProjectScanException('La carpeta contiene demasiados directorios. Selecciona una raiz mas especifica.');
            }
            if ($self !== false && (strcasecmp($directory, $self) === 0 || str_starts_with(strtolower($directory), strtolower($self).DIRECTORY_SEPARATOR))) {
                continue;
            }
            if (! is_readable($directory)) {
                $issues[] = ['local_path' => $directory, 'message' => 'No se puede leer esta carpeta.'];

                continue;
            }
            if (is_file($directory.DIRECTORY_SEPARATOR.'angular.json') || (is_file($directory.DIRECTORY_SEPARATOR.'artisan') && is_file($directory.DIRECTORY_SEPARATOR.'composer.json'))) {
                try {
                    $workspace = $this->inspect($directory);
                    foreach ($workspace['applications'] as $application) {
                        $found[] = [
                            ...$application,
                            'local_path' => $workspace['local_path'],
                            'current_version' => $workspace['current_version'],
                        ];
                    }
                    foreach ($workspace['issues'] as $issue) {
                        $issues[] = ['local_path' => $directory, ...$issue];
                    }
                } catch (ProjectScanException $exception) {
                    $issues[] = ['local_path' => $directory, 'message' => $exception->getMessage()];
                }

                continue;
            }
            foreach (File::directories($directory) as $child) {
                if (! is_link($child) && ! in_array(strtolower(basename($child)), $excluded, true)) {
                    $pending[] = $child;
                }
            }
        }

        usort($found, fn (array $a, array $b) => strcasecmp($a['local_path'].$a['angular_project'], $b['local_path'].$b['angular_project']));

        return ['projects' => $found, 'issues' => $issues];
    }

    private function outputPath(string $directory, string $name, array $build): string
    {
        $builder = $build['builder'] ?? '';
        $application = in_array($builder, ['@angular/build:application', '@angular-devkit/build-angular:application'], true);
        $browser = in_array($builder, ['@angular-devkit/build-angular:browser', '@angular-devkit/build-angular:browser-esbuild'], true);

        if (! $application && ! $browser) {
            throw new ProjectScanException('Builder no soportado: '.(is_string($builder) ? $builder : 'invalido'));
        }

        $options = $build['options'] ?? [];
        $production = $build['configurations']['production'] ?? [];
        if (! is_array($options) || ! is_array($production)) {
            throw new ProjectScanException('Las opciones de build o production son invalidas.');
        }
        $options = array_replace($options, $production);
        $output = array_key_exists('outputPath', $options) ? $options['outputPath'] : ($application ? 'dist/'.$name : null);

        if (is_string($output) && trim($output) !== '') {
            $base = $output;
            $suffix = $application ? 'browser' : '';
        } elseif ($application && is_array($output) && isset($output['base']) && is_string($output['base']) && trim($output['base']) !== '') {
            $base = $output['base'];
            $suffix = array_key_exists('browser', $output) ? $output['browser'] : 'browser';
            if (! is_string($suffix) || ($suffix !== '' && (str_starts_with($suffix, '/') || str_starts_with($suffix, '\\') || preg_match('/^[a-z]:/i', $suffix) || in_array('..', preg_split('~[/\\\\]+~', $suffix), true)))) {
                throw new ProjectScanException('El sufijo browser de outputPath debe ser una subcarpeta relativa.');
            }
        } else {
            throw new ProjectScanException('outputPath debe ser una ruta de texto o un objeto {base, browser} para el builder application.');
        }

        return LocalPath::absolute($base.($suffix === '' ? '' : '/'.$suffix), $directory);
    }

    private function inspectLaravel(string $directory): array
    {
        if (! is_file($directory.DIRECTORY_SEPARATOR.'artisan')) {
            throw new ProjectScanException('La carpeta no contiene un workspace Angular ni un proyecto Laravel (artisan y composer.json).');
        }
        $composer = $this->json->read($directory.DIRECTORY_SEPARATOR.'composer.json');
        if (! isset($composer['require']['laravel/framework'])) {
            throw new ProjectScanException('composer.json no declara laravel/framework.');
        }
        $version = $composer['version'] ?? '0.0.0';
        if (! is_string($version) || trim($version) === '' || strlen($version) > 255) {
            throw new ProjectScanException('La version de composer.json debe ser texto no vacio.');
        }

        return [
            'local_path' => $directory,
            'current_version' => $version,
            'applications' => [[
                'type' => 'laravel', 'target_platform' => 'web',
                'angular_project' => null, 'name' => basename($directory),
                'builder' => 'Laravel', 'build_output_path' => $directory, 'build_command' => null,
            ]],
            'issues' => [],
        ];
    }

    private function platform(string $directory, array $package): string
    {
        if (! is_array($package['dependencies'] ?? []) || ! is_array($package['devDependencies'] ?? [])) {
            throw new ProjectScanException('Las dependencias de package.json deben ser objetos JSON.');
        }
        $dependencies = array_keys(array_replace($package['dependencies'] ?? [], $package['devDependencies'] ?? []));
        if (array_intersect($dependencies, ['electron', '@tauri-apps/api', '@tauri-apps/cli']) || is_dir($directory.DIRECTORY_SEPARATOR.'src-tauri')) {
            return 'desktop';
        }
        if (array_intersect($dependencies, ['@ionic/angular', '@capacitor/core', 'cordova']) || is_file($directory.DIRECTORY_SEPARATOR.'ionic.config.json') || is_file($directory.DIRECTORY_SEPARATOR.'capacitor.config.ts') || is_file($directory.DIRECTORY_SEPARATOR.'capacitor.config.json')) {
            return 'mobile';
        }

        return 'web';
    }
}
