<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Cluster extends Model
{
    use HasUuids;

    protected $fillable = [
        'search_run_id',
        'representative_id',
        'strategy',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
        ];
    }

    public function searchRun(): BelongsTo
    {
        return $this->belongsTo(SearchRun::class);
    }

    public function representative(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'representative_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'cluster_document');
    }
}
