<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'status', 'project_type', 'local_path', 'build_output_path', 'command', 'log', 'duration_ms', 'exit_code', 'started_at', 'finished_at'])]
class Build extends Model
{
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'duration_ms' => 'integer', 'exit_code' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
