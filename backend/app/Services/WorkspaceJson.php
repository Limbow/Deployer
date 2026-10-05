<?php

namespace App\Services;

use App\Exceptions\ProjectScanException;
use Illuminate\Support\Facades\File;
use JsonException;

class WorkspaceJson
{
    public function read(string $file): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            throw new ProjectScanException('No se puede leer '.$file);
        }

        $text = File::get($file);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        // Preserve quoted strings while removing JSONC comments and trailing commas.
        $text = preg_replace_callback('~"(?:\\\\.|[^"\\\\])*"|//[^\r\n]*|/\*[\s\S]*?\*/~', fn (array $match) => str_starts_with($match[0], '"') ? $match[0] : ' ', $text);
        $text = preg_replace_callback('~"(?:\\\\.|[^"\\\\])*"|,\s*(?=[}\]])~', fn (array $match) => str_starts_with($match[0], '"') ? $match[0] : '', $text);

        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProjectScanException('JSON invalido en '.$file);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new ProjectScanException('Se esperaba un objeto JSON en '.$file);
        }

        return $data;
    }
}
