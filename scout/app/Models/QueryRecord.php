<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QueryRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'query_id',
        'text',
        'category',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function searchResults(): HasMany
    {
        return $this->hasMany(SearchResult::class, 'query_id');
    }
}
