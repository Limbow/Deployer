<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'host', 'port', 'username', 'password', 'use_ftps', 'passive', 'remote_path'])]
#[Hidden(['password'])]
class Server extends Model
{
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'port' => 'integer',
            'use_ftps' => 'boolean',
            'passive' => 'boolean',
        ];
    }
}
