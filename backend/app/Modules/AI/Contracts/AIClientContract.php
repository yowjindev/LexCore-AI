<?php

namespace App\Modules\AI\Contracts;

use App\Modules\AI\DTOs\AIResponse;

interface AIClientContract
{
    public function complete(string $prompt): AIResponse;

    /** Identifier for the provider, e.g. 'gemini' or 'claude'. */
    public function getProvider(): string;

    /** Active model name, e.g. 'gemini-3.1-flash-lite' or 'claude-sonnet-4-6'. */
    public function getModel(): string;
}
