<?php

namespace Tests\Unit\AI\Security;

use App\Modules\AI\Security\PiiScanner;
use Tests\TestCase;

class PiiScannerTest extends TestCase
{
    private PiiScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new PiiScanner();
    }

    public function test_detects_us_ssn(): void
    {
        $matches = $this->scanner->scan('The employee SSN is 123-45-6789 as per records.');
        $types   = array_column($matches, 'type');
        $this->assertContains('ssn', $types);
    }

    public function test_detects_credit_card_number(): void
    {
        $matches = $this->scanner->scan('Card: 4111 1111 1111 1111 was charged.');
        $types   = array_column($matches, 'type');
        $this->assertContains('credit_card', $types);
    }

    public function test_detects_passport_number(): void
    {
        $matches = $this->scanner->scan('Passport No. A12345678 presented.');
        $types   = array_column($matches, 'type');
        $this->assertContains('passport', $types);
    }

    public function test_clean_legal_text_produces_no_matches(): void
    {
        $text = 'This Agreement is entered into between Acme Corp and Widget Ltd '
              . 'effective January 1, 2026, for the provision of legal services.';
        $this->assertCount(0, $this->scanner->scan($text));
    }

    public function test_samples_are_redacted(): void
    {
        $matches = $this->scanner->scan('SSN: 987-65-4321');
        $ssn     = collect($matches)->firstWhere('type', 'ssn');
        $this->assertNotNull($ssn);
        foreach ($ssn['samples'] as $sample) {
            $this->assertStringNotContainsString('987-65-4321', $sample);
        }
    }

    public function test_has_pii_returns_true_when_pii_found(): void
    {
        $this->assertTrue($this->scanner->hasPii('SSN: 123-45-6789'));
        $this->assertFalse($this->scanner->hasPii('This is a clean contract.'));
    }
}
