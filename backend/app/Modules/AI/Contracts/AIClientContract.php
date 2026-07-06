<?php

namespace App\Modules\AI\Contracts;

use App\Modules\AI\DTOs\AIResponse;

interface AIClientContract
{
    /**
     * @param array{web_search?: bool} $options
     *        web_search: let the provider ground the answer with live web
     *        results (Gemini google_search grounding / Claude web_search tool).
     */
    public function complete(string $prompt, array $options = []): AIResponse;

    /** Identifier for the provider, e.g. 'gemini' or 'claude'. */
    public function getProvider(): string;

    /** Active model name, e.g. 'gemini-3.1-flash-lite' or 'claude-sonnet-4-6'. */
    public function getModel(): string;
}
