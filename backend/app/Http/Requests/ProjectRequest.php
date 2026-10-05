<?php

namespace App\Http\Requests;

use App\Exceptions\ProjectScanException;
use App\Models\Project;
use App\Services\LocalPath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'local_path' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'required', 'string', 'max:768', 'not_regex:/[\x00-\x1F\x7F]/'],
            'type' => ['sometimes', 'required', Rule::in(['angular', 'laravel'])],
            'angular_project' => ['sometimes', 'nullable', 'string', 'max:255'],
            'build_output_path' => ['sometimes', 'required', 'string', 'max:2048', 'not_regex:/[\x00-\x1F\x7F]/'],
            'build_command' => ['sometimes', 'nullable', 'string', 'max:4096', 'not_regex:/[\x00\r\n]/'],
            'ignore_patterns' => ['sometimes', 'array', 'max:100'],
            'ignore_patterns.*' => ['required', 'string', 'max:255'],
            'current_version' => ['sometimes', 'required', 'string', 'max:255'],
            'servers' => ['sometimes', 'array', 'max:100'],
            'servers.*.server_id' => ['required', 'integer', 'distinct', Rule::exists('servers', 'id')],
            'servers.*.label' => ['required', 'string', 'max:255'],
            'servers.*.remote_path_override' => ['nullable', 'string', 'max:1024', 'regex:~\A/[^\x00-\x1F\x7F\\\\]*\z~'],
            'servers.*.public_remote_path' => ['nullable', 'string', 'max:1024', 'regex:~\A/[^\x00-\x1F\x7F\\\\]*\z~'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->has('local_path') || ! $this->has('local_path')) {
                return;
            }
            try {
                $path = LocalPath::directory($this->string('local_path')->toString());
                if (strlen($path) > 768) {
                    $validator->errors()->add('local_path', 'La ruta local resuelta es demasiado larga.');

                    return;
                }
                $project = $this->route('project');
                if (Project::query()->where('local_path', $path)->when($project instanceof Project, fn ($query) => $query->where('id', '!=', $project->id))->exists()) {
                    $validator->errors()->add('local_path', 'Esta carpeta ya esta registrada.');
                }
            } catch (ProjectScanException $exception) {
                $validator->errors()->add('local_path', $exception->getMessage());
            }
        });
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'max' => 'Este campo supera el limite permitido (:max).',
            'servers.*.server_id.exists' => 'El servidor seleccionado ya no existe.',
            'servers.*.server_id.distinct' => 'No se puede asociar el mismo servidor dos veces.',
            'servers.*.remote_path_override.regex' => 'La ruta remota debe comenzar con / y usar barras normales.',
        ];
    }
}
