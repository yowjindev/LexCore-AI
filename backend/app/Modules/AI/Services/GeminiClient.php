<?php

namespace App\Modules\AI\Services;

use App\Exceptions\AI\AIProviderException;
use App\Modules\AI\Contracts\AIClientContract;
use App\Modules\AI\DTOs\AIResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GeminiClient implements AIClientContract
{
    public function __construct(
        private string $apiKey,
        private string $model    = 'gemini-2.0-flash',
        private int    $maxTokens = 4096,
    ) {}

    public function complete(string $prompt): AIResponse
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";

        try {
            $response = Http::withQueryParameters(['key' => $this->apiKey])
                ->post($url, [
                    'contents'          => [['parts' => [['text' => $prompt]]]],
                    'generationConfig'  => [
                        'temperature'     => 0.1,
                        'maxOutputTokens' => $this->maxTokens,
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new AIProviderException('Gemini API connection failed: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw new AIProviderException('Gemini API request failed (' . $response->status() . '): ' . $response->body());
        }

        $data = $response->json();

        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        // Strip markdown code fences Gemini sometimes adds
        $content = preg_replace('/^```(?:json)?\s*\n?|\n?```\s*$/m', '', trim($content));

        return new AIResponse(
            content:      $content,
            inputTokens:  $data['usageMetadata']['promptTokenCount']     ?? 0,
            outputTokens: $data['usageMetadata']['candidatesTokenCount'] ?? 0,
            model:        $data['modelVersion'] ?? $this->model,
        );
    }

    public function getProvider(): string { return 'gemini'; }
    public function getModel(): string    { return $this->model; }
}
