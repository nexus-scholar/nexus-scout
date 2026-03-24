<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Document extends Model
{
    use HasUuids;

    protected $fillable = [
        'title',
        'year',
        'abstract',
        'venue',
        'url',
        'language',
        'cited_by_count',
        'doi',
        'arxiv_id',
        'pubmed_id',
        'openalex_id',
        's2_id',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
        ];
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'document_author')
            ->withPivot('author_order');
    }

    public function searchProvenance(): HasMany
    {
        return $this->hasMany(SearchResult::class);
    }

    public function screening(): HasOne
    {
        return $this->hasOne(Screening::class);
    }

    public function clusters(): BelongsToMany
    {
        return $this->belongsToMany(Cluster::class, 'cluster_document');
    }
}
