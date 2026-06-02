<?php

namespace App\Modules\Documents\Listeners;

use App\Modules\AI\OCR\Events\OCRCompleted;
use App\Modules\AI\Security\PiiScanner;
use App\Modules\Documents\Events\PIIDetected;
use Illuminate\Support\Facades\Log;

class ScanForPii
{
    public function __construct(private readonly PiiScanner $scanner) {}

    public function handle(OCRCompleted $event): void
    {
        $text = $event->result->text ?? '';

        if (empty($text)) {
            return;
        }

        $matches = $this->scanner->scan($text);
        $hasPii  = count($matches) > 0;

        $event->document->update(['contains_pii' => $hasPii]);

        if ($hasPii) {
            Log::info('PII detected in document', [
                'document_id' => $event->document->id,
                'types'       => array_column($matches, 'type'),
            ]);
            PIIDetected::dispatch($event->document, $matches);
        }
    }
}
