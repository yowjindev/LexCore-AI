<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Services\GeminiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    private GeminiClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new GeminiClient(
            apiKey:    'test-key',
            model:     'gemini-test-model',
            maxTokens: 1024,
        );
    }

    private function fakeGeminiResponse(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates'    => [['content' => ['parts' => [['text' => 'ok']]]]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
                'modelVersion'  => 'gemini-test-model',
            ], 200),
        ]);
    }

    public function test_complete_omits_tools_by_default(): void
    {
        $this->fakeGeminiResponse();

        $this->client->complete('Test prompt');

        Http::assertSent(fn (Request $request) => ! isset($request['tools']));
    }

    public function test_complete_sends_google_search_grounding_when_option_enabled(): void
    {
        $this->fakeGeminiResponse();

        $this->client->complete('Test prompt', ['web_search' => true]);

        Http::assertSent(fn (Request $request) =>
            isset($request['tools'])
            && array_key_exists('google_search', $request['tools'][0])
        );
    }
}
