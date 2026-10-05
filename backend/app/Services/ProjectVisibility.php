<?php

namespace App\Services;

class ProjectVisibility
{
    public function reason(string $path, string $platform, array $settings): ?string
    {
        $key = $this->key($path);
        if (in_array($key, array_map($this->key(...), $settings['hidden_project_paths']), true)) {
            return 'Ocultado manualmente';
        }
        if (in_array($key, array_map($this->key(...), $settings['visible_project_paths']), true)) {
            return null;
        }
        $root = $this->key($settings['projects_root']);
        $relative = str_starts_with($key, $root.'/') ? substr($key, strlen($root) + 1) : basename($key);
        $segments = explode('/', $relative);
        foreach ($settings['excluded_project_folders'] as $folder) {
            if (in_array(strtolower($folder), $segments, true)) {
                return 'Carpeta excluida: '.$folder;
            }
        }
        if ($settings['hide_non_web_projects'] && $platform !== 'web') {
            return 'Aplicacion '.$platform.' (Ionic/Capacitor/Cordova/Electron/Tauri)';
        }

        return null;
    }

    public function key(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }
}
