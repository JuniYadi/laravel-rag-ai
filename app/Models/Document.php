<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'file_path',
        'file_type',
        'content',
        'excerpt',
        'embedding',
        'status',
        'error_message',
        'processing_started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getEmbeddingAttribute($value): ?array
    {
        if (is_string($value)) {
            return json_decode($value, true);
        }

        return $value;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }
}
