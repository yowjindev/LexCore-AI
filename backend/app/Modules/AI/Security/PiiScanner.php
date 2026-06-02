<?php

namespace App\Modules\AI\Security;

class PiiScanner
{
    private const PATTERNS = [
        'ssn'         => '/\b\d{3}-\d{2}-\d{4}\b/',
        'credit_card' => '/\b(?:\d{4}[- ]?){3}\d{4}\b/',
        'passport'    => '/\b[A-Z]{1,2}\d{6,9}\b/',
    ];

    /**
     * @return array<int, array{type: string, count: int, samples: string[]}>
     */
    public function scan(string $text): array
    {
        $results = [];

        foreach (self::PATTERNS as $type => $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                $found   = $matches[0];
                $samples = array_slice(
                    array_map(fn (string $m) => $this->redact($type, $m), $found),
                    0,
                    3
                );
                $results[] = [
                    'type'    => $type,
                    'count'   => count($found),
                    'samples' => $samples,
                ];
            }
        }

        return $results;
    }

    public function hasPii(string $text): bool
    {
        return count($this->scan($text)) > 0;
    }

    private function redact(string $type, string $match): string
    {
        return match ($type) {
            'ssn'         => preg_replace('/\d{3}-\d{2}-(\d{4})/', '***-**-$1', $match),
            'credit_card' => str_repeat('*', strlen($match) - 4) . substr($match, -4),
            'passport'    => substr($match, 0, 2) . str_repeat('*', strlen($match) - 2),
            default       => str_repeat('*', strlen($match)),
        };
    }
}
