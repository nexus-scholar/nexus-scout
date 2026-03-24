<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'run_id',
        'status',
        'config_snapshot',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'config_snapshot' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function searchResults(): HasMany
    {
        return $this->hasMany(SearchResult::class);
    }

    public function clusters(): HasMany
    {
        return $this->hasMany(Cluster::class);
    }
}
