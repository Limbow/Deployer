<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProjectScanException;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\LocalPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Setting::values()]);
    }

    public function update(Request $request): JsonResponse
    {
        $values = $request->validate([
            'projects_root' => ['sometimes', 'required', 'string', 'max:768', 'not_regex:/[\x00-\x1F\x7F]/'],
            'npx_path' => ['sometimes', 'required', 'string', 'max:1024', 'not_regex:/[\x00\r\n]/'],
            'ftp_retries' => ['sometimes', 'required', 'integer', 'between:1,10'],
            'ftp_retry_delay_ms' => ['sometimes', 'required', 'integer', 'between:0,60000'],
            'excluded_project_folders' => ['sometimes', 'array', 'max:100'],
            'excluded_project_folders.*' => ['required', 'string', 'max:255', 'regex:~\A[^/\\\\\x00-\x1F\x7F]+\z~'],
            'hide_non_web_projects' => ['sometimes', 'required', 'boolean'],
        ], [
            'required' => 'Este campo es obligatorio.',
            'ftp_retries.between' => 'Los reintentos deben estar entre 1 y 10.',
            'ftp_retry_delay_ms.between' => 'La espera debe estar entre 0 y 60000 milisegundos.',
        ]);

        if (isset($values['projects_root'])) {
            try {
                $values['projects_root'] = LocalPath::directory($values['projects_root']);
            } catch (ProjectScanException $exception) {
                throw ValidationException::withMessages(['projects_root' => $exception->getMessage()]);
            }
        }

        foreach (['ftp_retries', 'ftp_retry_delay_ms'] as $key) {
            if (isset($values[$key])) {
                $values[$key] = (int) $values[$key];
            }
            if (isset($values['hide_non_web_projects'])) {
                $values['hide_non_web_projects'] = (bool) $values['hide_non_web_projects'];
            }
        }

        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });

        return $this->index();
    }
}
