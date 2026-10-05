<?php

namespace Tests;

use Illuminate\Support\Facades\File;

trait CreatesAngularWorkspaces
{
    private string $workspaceRoot;

    private function createWorkspace(string $folder, array $projects, string $version = '1.2.3'): string
    {
        $path = $this->workspaceRoot.DIRECTORY_SEPARATOR.$folder;
        File::ensureDirectoryExists($path);
        File::put($path.DIRECTORY_SEPARATOR.'angular.json', json_encode(['version' => 1, 'projects' => $projects], JSON_THROW_ON_ERROR));
        File::put($path.DIRECTORY_SEPARATOR.'package.json', json_encode(['name' => 'fixture', 'version' => $version], JSON_THROW_ON_ERROR));

        return $path;
    }

    private function application(mixed $output = 'dist/site', string $builder = '@angular/build:application', array $production = []): array
    {
        return [
            'projectType' => 'application',
            'architect' => ['build' => [
                'builder' => $builder,
                'options' => $output === null ? [] : ['outputPath' => $output],
                'configurations' => ['production' => $production],
            ]],
        ];
    }
}
