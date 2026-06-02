<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRequest extends Model
{
    use HasUuids;

    const UPDATED_AT = null;    // append-only — no updated_at column

    protected $fillable = [
        'organization_id',
        'user_id',
        'document_id',
        'job_type',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'latency_ms',
        'cost_usd',
        'status',
        'error_message',
        'raw_response',
    ];

    protected function casts(): array
    {
        return [
            'prompt_tokens'     => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens'      => 'integer',
            'latency_ms'        => 'integer',
            'cost_usd'          => 'decimal:6',
            'created_at'        => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static fn (self $model) => $model->created_at ??= now());
    }
}
