<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'target_platform', 'local_path', 'angular_project', 'build_output_path', 'build_command', 'ignore_patterns', 'current_version'])]
class Project extends Model
{
    protected function casts(): array
    {
        return ['ignore_patterns' => 'array'];
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class)->withPivot('label', 'remote_path_override', 'public_remote_path');
    }

    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }

    public function deploys(): HasMany
    {
        return $this->hasMany(Deploy::class);
    }
}
