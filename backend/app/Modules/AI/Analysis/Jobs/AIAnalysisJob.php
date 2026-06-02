<?php

namespace App\Modules\AI\Analysis\Jobs;

use App\Exceptions\AI\AIAnalysisException;
use App\Exceptions\AI\AIBudgetExceededException;
use App\Modules\AI\Analysis\DTOs\AnalysisResult;
use App\Modules\AI\Analysis\Repositories\Contracts\IDocumentAnalysisRepository;
use App\Modules\AI\Prompts\Contracts\PromptLoaderContract;
use App\Modules\AI\Contracts\AIClientContract;
use App\Modules\AI\OCR\Models\DocumentExtractionChunk;
use App\Modules\AI\Services\ObservableAIClient;
use App\Modules\AI\Services\TokenBudgetService;
use App\Modules\AI\Utilities\TextTruncator;
use App\Modules\Documents\Events\DocumentAnalysisCompleted;
use App\Modules\Documents\Events\DocumentAnalysisFailed;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentStatusManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AIAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 5;
    public $timeout = 120;

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function __construct(public readonly Document $document)
    {
        $this->onConnection('redis')->onQueue('analysis');
    }

    public function handle(): void
    {
        app(TokenBudgetService::class)->check($this->document->organization_id);

        $claude        = new ObservableAIClient(
            app(AIClientContract::class),
            $this->document->organization_id,
            $this->document->id,
            'ai_analysis',
        );
        $repo          = app(IDocumentAnalysisRepository::class);
        $statusManager = app(DocumentStatusManager::class);
        $promptLoader  = app(PromptLoaderContract::class);
        $truncator     = app(TextTruncator::class);

        try {
            $this->document->refresh();
            $chunks = $this->document->chunks()->orderBy('chunk_index')->get();

            if ($this->document->status !== Document::STATUS_AI_PROCESSING) {
                if ($statusManager->canTransition($this->document, Document::STATUS_AI_PROCESSING)) {
                    $statusManager->transition($this->document, Document::STATUS_AI_PROCESSING);
                }
            }

            if ($chunks->isNotEmpty()) {
                $allChunkAnalysesCompleted = $chunks->every(
                    fn (DocumentExtractionChunk $chunk) => $chunk->analysis_status === DocumentExtractionChunk::ANALYSIS_STATUS_COMPLETED
                );

                if (! $allChunkAnalysesCompleted) {
                    throw new AIAnalysisException('Chunk analyses are not complete yet.');
                }

                $completedChunks = $chunks->where('analysis_status', DocumentExtractionChunk::ANALYSIS_STATUS_COMPLETED);
                $prompt = $promptLoader->load('document.synthesize_analysis', [
                    'filename' => $this->document->original_filename,
                    'content'  => json_encode($completedChunks->map(fn (DocumentExtractionChunk $chunk) => [
                        'chunk_index'   => $chunk->chunk_index,
                        'page_start'    => $chunk->page_start,
                        'page_end'      => $chunk->page_end,
                        'summary'       => $chunk->analysis_summary,
                        'key_points'    => $chunk->analysis_key_points ?? [],
                        'parties'       => $chunk->analysis_parties ?? [],
                        'governing_law' => $chunk->analysis_governing_law,
                        'effective_date'=> $chunk->analysis_effective_date,
                        'risk_score'    => $chunk->analysis_risk_score,
                        'confidence'    => $chunk->analysis_confidence,
                    ])->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            } else {
                $extraction = $this->document->extraction;

                if (! $extraction?->extracted_text) {
                    throw new AIAnalysisException('No extracted text available for analysis.');
                }

                $text       = $truncator->truncate($extraction->extracted_text);
                $sanitizer  = app(\App\Modules\AI\Security\PromptSanitizer::class);
                $sanitizer->flagSuspicious($text);
                $text       = $sanitizer->wrap($sanitizer->neutralize($text));
                $prompt = $promptLoader->load('document.analyze', [
                    'content'  => $text,
                    'filename' => $this->document->original_filename,
                ]);
            }

            $response = $claude->complete($prompt);
            $parsed   = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

            if (! isset($parsed['summary'])) {
                throw new AIAnalysisException('Invalid analysis response: missing required summary field.');
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

            $analysis = $repo->upsert($this->document->id, $result);

            $statusManager->transition($this->document, Document::STATUS_ANALYZED);

            DocumentAnalysisCompleted::dispatch($this->document, $analysis);

        } catch (AIBudgetExceededException $e) {
            // Non-retryable — fail immediately
            $statusManager->transition($this->document, Document::STATUS_FAILED);
            DocumentAnalysisFailed::dispatch($this->document, "Budget exceeded: {$e->getMessage()}");
            $this->fail($e);
            return;
        } catch (Throwable $e) {
            // Transient error — keep document at ai_processing so Horizon shows clean retries
            // without status thrashing between ai_processing and ocr_completed.
            // failed() handles the final failure after all retries are exhausted.
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        // All retries exhausted
        Document::where('id', $this->document->id)->update(['status' => Document::STATUS_FAILED]);
        DocumentAnalysisFailed::dispatch($this->document, $e->getMessage());
    }
}
