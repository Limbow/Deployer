<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deploy_id', 'relative_path', 'remote_path', 'local_path', 'hash', 'size', 'status', 'backup_path', 'error', 'manual_delete'])]
class DeployFile extends Model
{
    protected function casts(): array
    {
        return ['size' => 'integer', 'manual_delete' => 'boolean'];
    }

    public function deploy(): BelongsTo
    {
        return $this->belongsTo(Deploy::class);
    }
}
