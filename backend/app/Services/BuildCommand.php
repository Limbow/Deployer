<?php

namespace App\Services;

use App\Exceptions\ProjectScanException;

class BuildCommand
{
    public function resolve(string $command, string $npx): string
    {
        if (preg_match('/^\s*npx(?:\.cmd)?(?=\s|$)/i', $command)) {
            if (preg_match('/[\x00\r\n"%!&|<>^]/', $npx) || trim($npx) === '') {
                throw new ProjectScanException('La ruta configurada de npx contiene caracteres no permitidos.');
            }
            $command = preg_replace_callback('/^\s*npx(?:\.cmd)?/i', fn () => '"'.$npx.'"', $command, 1);
        }

        // Laravel/Symfony wraps shell strings with cmd.exe on Windows; a nested /C breaks quoted .cmd paths.
        return $command;
    }
}
