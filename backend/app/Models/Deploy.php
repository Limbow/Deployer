<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'server_id', 'build_id', 'project_name', 'project_type', 'server_name', 'source_path', 'version', 'changes', 'status', 'progress', 'duration_ms', 'log', 'remote_path', 'public_remote_path', 'delete_obsolete', 'version_file_name', 'git_commit', 'started_at', 'finished_at'])]
class Deploy extends Model
{
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'progress' => 'integer',
            'duration_ms' => 'integer', 'delete_obsolete' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(DeployFile::class);
    }
}
