<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\AIClientContract;
use App\Modules\AI\DTOs\AIResponse;
use App\Modules\AI\Models\AiRequest;
use App\Modules\AI\Models\AiTokenBudget;
use Throwable;

class ObservableAIClient implements AIClientContract
{
    public function getProvider(): string { return $this->inner->getProvider(); }
    public function getModel(): string    { return $this->inner->getModel(); }

    public function __construct(
        private readonly AIClientContract $inner,
        private readonly string           $organizationId,
        private readonly string           $documentId,
        private readonly string           $jobType,
        private readonly ?string          $userId = null,
    ) {}

    public function complete(string $prompt, array $options = []): AIResponse
    {
        $start = microtime(true);

        try {
            $response  = $this->inner->complete($prompt, $options);
            $latencyMs = (int) ((microtime(true) - $start) * 1000);
            $total     = $response->inputTokens + $response->outputTokens;

            AiRequest::create([
                'organization_id'   => $this->organizationId,
                'user_id'           => $this->userId,
                'document_id'       => $this->documentId,
                'job_type'          => $this->jobType,
                'model'             => $response->model,
                'prompt_tokens'     => $response->inputTokens,
                'completion_tokens' => $response->outputTokens,
                'total_tokens'      => $total,
                'latency_ms'        => $latencyMs,
                'raw_response'      => $response->content,
                'status'            => 'success',
            ]);

            AiTokenBudget::where('organization_id', $this->organizationId)
                ->increment('current_month_tokens', $total);

            return $response;

        } catch (Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            AiRequest::create([
                'organization_id'   => $this->organizationId,
                'user_id'           => $this->userId,
                'document_id'       => $this->documentId,
                'job_type'          => $this->jobType,
                'model'             => 'unknown',
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'total_tokens'      => 0,
                'latency_ms'        => $latencyMs,
                'status'            => 'failure',
                'error_message'     => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
