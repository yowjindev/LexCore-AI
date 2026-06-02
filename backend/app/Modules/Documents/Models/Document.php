<?php
namespace App\Modules\Documents\Models;

use App\Models\User;
use App\Modules\AI\Embeddings\Models\DocumentChunk;
use App\Modules\AI\OCR\Models\DocumentExtractionChunk;
use App\Modules\AI\OCR\Models\DocumentExtraction;
use App\Modules\Organizations\Models\Organization;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Documents\Models\DocumentConversation;

class Document extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    const STATUS_PENDING        = 'pending';
    const STATUS_PROCESSING     = 'processing';   // legacy — kept for backward compat; not in VALID_TRANSITIONS
    const STATUS_OCR_PROCESSING = 'ocr_processing';
    const STATUS_OCR_COMPLETED  = 'ocr_completed';
    const STATUS_AI_PROCESSING  = 'ai_processing';
    const STATUS_ANALYZED       = 'analyzed';
    const STATUS_FAILED         = 'failed';

    protected $fillable = [
        'organization_id', 'uploaded_by', 'title', 'original_filename',
        'mime_type', 'file_size', 's3_path', 'status', 'contains_pii', 'category', 'tags',
    ];

    protected function casts(): array
    {
        return [
            'tags'         => 'array',
            'file_size'    => 'integer',
            'contains_pii' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(DocumentAnalysis::class);
    }

    public function extraction(): HasOne
    {
        return $this->hasOne(DocumentExtraction::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentExtractionChunk::class);
    }

    public function documentChunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(DocumentConversation::class);
    }

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }
}
