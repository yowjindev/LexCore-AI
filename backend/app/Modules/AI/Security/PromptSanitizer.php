<?php

namespace App\Modules\AI\Security;

use Illuminate\Support\Facades\Log;

class PromptSanitizer
{
    private const DELIMITER_TOKENS = [
        '<document',   '</document',
        '<system>',    '<|im_start|>',
        '<|im_end|>',  '[INST]',
        '[/INST]',
    ];

    private const REPLACEMENTS = [
        '&lt;document',  '&lt;/document',
        '&lt;system&gt;','&lt;|im_start|&gt;',
        '&lt;|im_end|&gt;','&#91;INST&#93;',
        '&#91;/INST&#93;',
    ];

    private const INJECTION_PATTERNS = [
        'ignore_instructions' => '/ignore\s+(all\s+)?(previous|prior)\s+instructions?/i',
        'disregard_above'     => '/disregard\s+(the\s+)?(above|previous|prior)/i',
        'you_are_now'         => '/you\s+are\s+now\s+(?!a\s+party|the\s+tenant|an?\s+employee)/i',
        'jailbreak_dan'       => '/\bDAN\b|do\s+anything\s+now/i',
    ];

    public function wrap(string $content, string $tag = 'document'): string
    {
        $nonce = substr(bin2hex(random_bytes(4)), 0, 8);
        $clean = $this->neutralize($content);
        return "<{$tag} id=\"{$nonce}\">{$clean}</{$tag}>";
    }

    public function neutralize(string $content): string
    {
        return str_replace(self::DELIMITER_TOKENS, self::REPLACEMENTS, $content);
    }

    /**
     * @return array<string, string>
     */
    public function flagSuspicious(string $content): array
    {
        $matches = [];
        foreach (self::INJECTION_PATTERNS as $key => $pattern) {
            if (preg_match($pattern, $content, $m)) {
                $matches[$key] = $m[0];
            }
        }

        if ($matches) {
            Log::warning('Possible prompt injection detected', [
                'patterns'       => array_keys($matches),
                'content_length' => strlen($content),
            ]);
        }

        return $matches;
    }
}
