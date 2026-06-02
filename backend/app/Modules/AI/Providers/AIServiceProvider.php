<?php

namespace App\Modules\AI\Providers;

use App\Modules\AI\Analysis\Repositories\Contracts\IDocumentAnalysisRepository;
use App\Modules\AI\Analysis\Repositories\DocumentAnalysisRepository;
use App\Modules\AI\Contracts\AIClientContract;
use App\Modules\AI\Contracts\EmbeddingClientContract;
use App\Modules\AI\OCR\Events\OCRCompleted;
use App\Modules\AI\OCR\Events\OCRFailed;
use App\Modules\AI\OCR\Listeners\LogOCRActivity;
use App\Modules\AI\OCR\Parsers\ImageTextExtractor;
use App\Modules\AI\OCR\Parsers\PdfTextExtractor;
use App\Modules\AI\OCR\Repositories\Contracts\IDocumentExtractionRepository;
use App\Modules\AI\OCR\Repositories\DocumentExtractionRepository;
use App\Modules\AI\OCR\Services\OcrService;
use App\Modules\AI\Pipelines\DocumentAnalysisPipeline;
use App\Modules\AI\Prompts\Contracts\PromptLoaderContract;
use App\Modules\AI\Prompts\PromptLoader;
use App\Modules\AI\Analysis\Listeners\DispatchAIAnalysis;
use App\Modules\AI\Risk\Listeners\DispatchRiskDetection;
use App\Modules\AI\Services\ClaudeClient;
use App\Modules\AI\Services\GeminiClient;
use App\Modules\AI\Services\GeminiEmbeddingClient;
use App\Modules\AI\Services\TokenBudgetService;
use App\Modules\AI\Utilities\TextTruncator;
use App\Modules\Documents\Events\DocumentAnalysisCompleted;
use App\Modules\Documents\Listeners\LogDocumentAnalysisActivity;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PromptLoaderContract::class, PromptLoader::class);
        $this->app->singleton(DocumentAnalysisPipeline::class);

        $this->app->singleton(IDocumentExtractionRepository::class, DocumentExtractionRepository::class);
        $this->app->singleton(IDocumentAnalysisRepository::class, DocumentAnalysisRepository::class);

        $this->app->singleton(OcrService::class, function () {
            return new OcrService([
                new PdfTextExtractor(),
                new ImageTextExtractor(),
            ]);
        });

        $this->app->singleton(AIClientContract::class, function () {
            $driver = config('ai.driver', 'claude');

            if ($driver === 'gemini') {
                $cfg = config('ai.gemini');
                return new GeminiClient(
                    apiKey:    $cfg['api_key']    ?? '',
                    model:     $cfg['model']      ?? 'gemini-2.0-flash',
                    maxTokens: $cfg['max_tokens'] ?? 4096,
                );
            }

            $cfg = config('ai.claude');
            return new ClaudeClient(
                apiKey:    $cfg['api_key']    ?? '',
                model:     $cfg['model']      ?? 'claude-sonnet-4-6',
                maxTokens: $cfg['max_tokens'] ?? 4096,
            );
        });

        $this->app->singleton(TextTruncator::class);
        $this->app->singleton(TokenBudgetService::class);

        $this->app->singleton(EmbeddingClientContract::class, function () {
            $cfg = config('ai.embedding.gemini');
            return new GeminiEmbeddingClient(
                apiKey: $cfg['api_key'] ?? '',
                model:  $cfg['model']  ?? 'text-embedding-004',
            );
        });

        $this->app->singleton(
            \App\Modules\AI\Embeddings\Repositories\Contracts\IDocumentChunkRepository::class,
            \App\Modules\AI\Embeddings\Repositories\DocumentChunkRepository::class,
        );
        $this->app->singleton(\App\Modules\AI\Embeddings\Services\ChunkingService::class);

        $this->app->singleton(
            \App\Modules\AI\Embeddings\Repositories\Contracts\ISearchRepository::class,
            \App\Modules\AI\Embeddings\Repositories\SearchRepository::class,
        );
        $this->app->singleton(\App\Modules\AI\Embeddings\Services\SemanticSearchService::class);
        $this->app->singleton(\App\Modules\Documents\Services\ChatService::class);

        $this->app->singleton(\App\Modules\AI\Security\PiiScanner::class);
        $this->app->singleton(\App\Modules\AI\Security\PromptSanitizer::class);
    }

    public function boot(): void
    {
        Event::listen(OCRCompleted::class, [LogOCRActivity::class, 'handleCompleted']);
        Event::listen(OCRFailed::class, [LogOCRActivity::class, 'handleFailed']);

        // Analysis dispatch
        Event::listen(OCRCompleted::class, [DispatchAIAnalysis::class, 'handle']);

        // Analysis completion logging
        Event::listen(DocumentAnalysisCompleted::class, [LogDocumentAnalysisActivity::class, 'handle']);
        Event::listen(DocumentAnalysisCompleted::class, [DispatchRiskDetection::class, 'handle']);
        Event::listen(DocumentAnalysisCompleted::class, [\App\Modules\AI\Embeddings\Listeners\DispatchEmbedding::class, 'handle']);

        // PII scanning on OCR completion
        Event::listen(OCRCompleted::class, [\App\Modules\Documents\Listeners\ScanForPii::class, 'handle']);
    }
}
