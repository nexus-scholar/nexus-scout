<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'search_run_id',
        'query_id',
        'document_id',
        'provider',
        'provider_doc_id',
        'rank',
        'retrieved_at',
    ];

    protected function casts(): array
    {
        return [
            'retrieved_at' => 'datetime',
        ];
    }

    public function searchRun(): BelongsTo
    {
        return $this->belongsTo(SearchRun::class);
    }

    public function queryRecord(): BelongsTo
    {
        return $this->belongsTo(QueryRecord::class, 'query_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
