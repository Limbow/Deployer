<?php

namespace App\Services;

class PublishIgnore
{
    public function reason(string $path, string $type, array $patterns): ?string
    {
        $lower = strtolower($path);
        foreach (explode('/', $lower) as $segment) {
            if ($segment === '.env' || str_starts_with($segment, '.env.')) {
                return 'Protegido: variables de entorno.';
            }
            if (in_array($segment, ['.git', '.svn', '.hg', 'node_modules'], true)) {
                return 'Protegido: repositorio o dependencias de desarrollo.';
            }
        }
        if ($type === 'laravel') {
            foreach (['storage', 'public/storage', 'bootstrap/cache'] as $directory) {
                if ($lower === $directory || str_starts_with($lower, $directory.'/')) {
                    return 'Protegido: datos persistentes o cache local de Laravel.';
                }
            }
            if (preg_match('~^database/.*\.(?:sqlite|sqlite3|db)(?:-(?:wal|shm|journal))?$~', $lower)) {
                return 'Protegido: base de datos local.';
            }
        }
        foreach ($patterns as $pattern) {
            $pattern = trim(str_replace('\\', '/', $pattern), '/');
            if ($pattern !== '' && (fnmatch($pattern, $path) || fnmatch($pattern, basename($path)) || fnmatch($pattern, $path.'/'))) {
                return 'Excluido por patron: '.$pattern;
            }
        }

        return null;
    }
}
