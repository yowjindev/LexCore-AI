<?php

namespace App\Modules\AI\Analysis\Jobs;

use App\Exceptions\AI\AIAnalysisException;
use App\Exceptions\AI\AIBudgetExceededException;
use App\Modules\AI\Analysis\DTOs\AnalysisResult;
use App\Modules\AI\Analysis\Repositories\Contracts\IDocumentAnalysisRepository;
use App\Modules\AI\Contracts\AIClientContract;
use App\Modules\AI\OCR\Models\DocumentExtractionChunk;
use App\Modules\AI\Services\ObservableAIClient;
use App\Modules\AI\Services\TokenBudgetService;
use App\Modules\AI\Prompts\Contracts\PromptLoaderContract;
use App\Modules\Documents\Events\DocumentAnalysisFailed;
use App\Modules\Documents\Models\Document;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AIChunkAnalysisJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 5;
    public $timeout = 120;

    /** Seconds to wait between retries: 10s, 30s, 60s, 120s */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function __construct(
        public readonly Document $document,
        public readonly DocumentExtractionChunk $chunk,
    ) {
        $this->onConnection('redis')->onQueue('analysis');
    }

    public function handle(): void
    {
        $document = Document::query()->find($this->document->id);
        if ($document === null || $document->status === Document::STATUS_FAILED) {
            return;
        }

        $chunk = DocumentExtractionChunk::query()->find($this->chunk->id);
        if ($chunk === null || $chunk->status !== DocumentExtractionChunk::STATUS_COMPLETED) {
            return;
        }

        if (! filled($chunk->extracted_text)) {
            throw new AIAnalysisException('No extracted text available for chunk analysis.');
        }

        app(TokenBudgetService::class)->check($document->organization_id);

        $claude       = new ObservableAIClient(
            app(AIClientContract::class),
            $document->organization_id,
            $document->id,
            'chunk_analysis',
        );
        $promptLoader = app(PromptLoaderContract::class);

        try {
            $chunk->update([
                'analysis_status' => DocumentExtractionChunk::ANALYSIS_STATUS_PROCESSING,
                'analysis_error_message' => null,
            ]);

            $sanitizer   = app(\App\Modules\AI\Security\PromptSanitizer::class);
            $sanitizer->flagSuspicious($chunk->extracted_text);
            $safeContent = $sanitizer->wrap($sanitizer->neutralize($chunk->extracted_text));
            $prompt = $promptLoader->load('document.analyze_chunk', [
                'filename'    => $document->original_filename,
                'chunk_range' => "pages {$chunk->page_start}-{$chunk->page_end}",
                'content'     => $safeContent,
            ]);

            $response = $claude->complete($prompt);
            $parsed = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

            if (! isset($parsed['summary'])) {
                throw new AIAnalysisException('Invalid chunk analysis response: missing required summary field.');
            }

            $result = new AnalysisResult(
                summary:       (string) $parsed['summary'],
                keyPoints:     (array) ($parsed['key_points'] ?? []),
                parties:       (array) ($parsed['parties'] ?? []),
                governingLaw:  isset($parsed['governing_law']) ? (string) $parsed['governing_law'] : null,
                effectiveDate: isset($parsed['effective_date']) ? (string) $parsed['effective_date'] : null,
                riskScore:     (float) ($parsed['risk_score'] ?? 0.0),
                confidence:    (float) ($parsed['confidence'] ?? 0.5),
                model:         $response->model,
                rawResponse:   $response->content,
            );

            $chunk->update([
                'analysis_status'         => DocumentExtractionChunk::ANALYSIS_STATUS_COMPLETED,
                'analysis_summary'        => $result->summary,
                'analysis_key_points'     => $result->keyPoints,
                'analysis_parties'        => $result->parties,
                'analysis_governing_law'  => $result->governingLaw,
                'analysis_effective_date' => $result->effectiveDate,
                'analysis_risk_score'     => $result->riskScore,
                'analysis_confidence'     => $result->confidence,
                'analysis_model'          => $result->model,
                'analysis_raw_response'   => $result->rawResponse,
                'analyzed_at'             => now(),
            ]);
        } catch (AIBudgetExceededException $e) {
            // Non-retryable — budget exhausted, fail immediately without consuming retries
            $chunk->update([
                'analysis_status'        => DocumentExtractionChunk::ANALYSIS_STATUS_FAILED,
                'analysis_error_message' => $e->getMessage(),
            ]);
            Document::where('id', $document->id)->update(['status' => Document::STATUS_FAILED]);
            DocumentAnalysisFailed::dispatch($document, "Budget exceeded: {$e->getMessage()}");
            $this->fail($e);
            return;
        } catch (Throwable $e) {
            // Transient error — reset chunk to pending so retries can proceed.
            // Do NOT mark the document as failed yet; failed() handles that after all retries exhaust.
            $chunk->update([
                'analysis_status'        => DocumentExtractionChunk::ANALYSIS_STATUS_PENDING,
                'analysis_error_message' => "Attempt {$this->attempts()}: {$e->getMessage()}",
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        // All retries exhausted — now mark everything as failed
        $chunk = DocumentExtractionChunk::query()->find($this->chunk->id);
        if ($chunk) {
            $chunk->update([
                'analysis_status'        => DocumentExtractionChunk::ANALYSIS_STATUS_FAILED,
                'analysis_error_message' => $e->getMessage(),
            ]);
        }

        $document = Document::query()->find($this->document->id);
        if ($document !== null && $document->status !== Document::STATUS_FAILED) {
            Document::where('id', $document->id)->update(['status' => Document::STATUS_FAILED]);
            DocumentAnalysisFailed::dispatch($this->document, "Chunk {$this->chunk->chunk_index} failed after all retries: {$e->getMessage()}");
        }
    }
}
