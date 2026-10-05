<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeployRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'server_id' => ['required', 'integer', Rule::exists('servers', 'id')],
            'build_id' => ['nullable', 'integer', Rule::exists('builds', 'id')],
            'version' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,254}\z/'],
            'changes' => ['nullable', 'string', 'max:10000'],
            'delete_obsolete' => ['sometimes', 'boolean'],
            'files' => ['present', 'array', 'max:50000'],
            'files.*' => ['required', 'string', 'max:2048', 'distinct', 'not_regex:/[\x00-\x1F\x7F\\\\]/'],
        ];
    }

    public function messages(): array
    {
        return [
            'version.regex' => 'La version solo puede contener letras, numeros, punto, guion y guion bajo.',
            'files.min' => 'Selecciona al menos un archivo para desplegar.',
            'files.*.distinct' => 'No repitas rutas de archivo.',
        ];
    }
}
