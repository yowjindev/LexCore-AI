<?php

namespace App\Modules\AI\Services;

use App\Exceptions\AI\AIProviderException;
use App\Modules\AI\Contracts\AIClientContract;
use App\Modules\AI\DTOs\AIResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ClaudeClient implements AIClientContract
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private int    $maxTokens = 4096,
    ) {}

    public function complete(string $prompt, array $options = []): AIResponse
    {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if ($options['web_search'] ?? false) {
            $payload['tools'] = [[
                'type'     => 'web_search_20250305',
                'name'     => 'web_search',
                'max_uses' => 3,
            ]];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', $payload);
        } catch (ConnectionException $e) {
            throw new AIProviderException('Claude API connection failed: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw new AIProviderException('Claude API request failed (' . $response->status() . '): ' . $response->body());
        }

        $data = $response->json();

        // Web-search responses interleave server_tool_use / web_search_tool_result
        // blocks with text blocks — only the text blocks form the answer.
        $textBlocks = array_filter($data['content'], fn ($block) => ($block['type'] ?? '') === 'text');
        $content    = implode("\n", array_column($textBlocks, 'text'));

        return new AIResponse(
            content:      $content,
            inputTokens:  $data['usage']['input_tokens'],
            outputTokens: $data['usage']['output_tokens'],
            model:        $data['model'],
        );
    }

    public function getProvider(): string { return 'claude'; }
    public function getModel(): string    { return $this->model; }
}
