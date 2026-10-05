<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BumpProjectVersionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:patch,minor,major'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Indica el tipo de incremento de versión.',
            'type.string' => 'El tipo de incremento de versión debe ser texto.',
            'type.in' => 'El incremento debe ser patch, minor o major.',
        ];
    }
}
