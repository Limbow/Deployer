<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class ServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'required', 'string', 'max:255'],
            'host' => [$presence, 'required', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }

                if (! filter_var($value, FILTER_VALIDATE_IP) && ! preg_match('/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\z/i', $value)) {
                    $fail('El host debe ser un dominio o una IP, sin protocolo ni ruta.');
                }
            }],
            'port' => [$presence, 'required', 'integer', 'between:1,65535'],
            'username' => [$presence, 'required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
            'password' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'nullable', 'string', 'max:4096', 'not_regex:/[\x00\r\n]/'],
            'use_ftps' => [$presence, 'required', 'boolean'],
            'passive' => [$presence, 'required', 'boolean'],
            'remote_path' => [$presence, 'required', 'string', 'max:1024', 'regex:~\A/[^\x00-\x1F\x7F\\\\]*\z~'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'string' => 'Este campo debe ser texto.',
            'max' => 'Este campo supera la longitud permitida (:max).',
            'port.integer' => 'El puerto debe ser un numero entero.',
            'port.between' => 'El puerto debe estar entre 1 y 65535.',
            'boolean' => 'Este campo debe ser verdadero o falso.',
            'remote_path.regex' => 'La ruta remota debe comenzar con / y no contener barras invertidas ni caracteres de control.',
            'not_regex' => 'Este campo contiene caracteres de control no permitidos.',
        ];
    }
}
