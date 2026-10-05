<?php

namespace App\Services;

use App\Exceptions\ProjectScanException;

class LocalPath
{
    public static function absolute(string $path, string $base): string
    {
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', $base);

        if (preg_match('~^[a-z]:(?!/)~i', $path) || str_contains($path, "\0")) {
            throw new ProjectScanException('La ruta local es invalida. Usa una ruta absoluta o relativa al proyecto.');
        }

        if (! self::isAbsolute($path)) {
            $path = rtrim($base, '/').'/'.$path;
        } elseif (DIRECTORY_SEPARATOR === '\\' && str_starts_with($path, '/') && ! str_starts_with($path, '//') && preg_match('/^[a-z]:/i', $base, $drive)) {
            $path = $drive[0].$path;
        }

        if (preg_match('~^([a-z]:)/~i', $path, $match)) {
            $prefix = strtoupper($match[1]).'/';
            $path = substr($path, 3);
        } elseif (str_starts_with($path, '//')) {
            $parts = explode('/', substr($path, 2));
            if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                throw new ProjectScanException('La ruta de red debe incluir servidor y recurso compartido.');
            }
            $prefix = '//'.array_shift($parts).'/'.array_shift($parts).'/';
            $path = implode('/', $parts);
        } elseif (str_starts_with($path, '/')) {
            $prefix = '/';
            $path = ltrim($path, '/');
        } else {
            throw new ProjectScanException('La carpeta base debe ser una ruta absoluta.');
        }

        $segments = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($segments);
            } else {
                $segments[] = $part;
            }
        }

        return self::native($prefix.implode('/', $segments));
    }

    public static function directory(string $path): string
    {
        if (! self::isAbsolute(str_replace('\\', '/', $path)) || str_contains($path, "\0")) {
            throw new ProjectScanException('La carpeta debe ser una ruta absoluta.');
        }

        $resolved = realpath($path);

        if ($resolved === false || ! is_dir($resolved) || ! is_readable($resolved)) {
            throw new ProjectScanException('La carpeta no existe o no se puede leer: '.$path);
        }

        return self::native($resolved);
    }

    public static function native(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[a-z]:/~i', $path) === 1;
    }
}
