<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Screening extends Model
{
    use HasUuids;

    protected $fillable = [
        'document_id',
        'decision',
        'reason',
        'confidence',
        'layers',
    ];

    protected function casts(): array
    {
        return [
            'layers' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
