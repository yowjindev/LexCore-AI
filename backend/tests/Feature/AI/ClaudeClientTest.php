<?php

namespace Tests\Feature\AI;

use App\Exceptions\AI\AIProviderException;
use App\Modules\AI\Services\ClaudeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaudeClientTest extends TestCase
{
    private ClaudeClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new ClaudeClient(
            apiKey:    'test-key',
            model:     'claude-test-model',
            maxTokens: 1024,
        );
    }

    public function test_complete_returns_response_with_content_and_tokens(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"summary": "Test summary"}']],
                'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
                'model'   => 'claude-test-model-20241201',
            ], 200),
        ]);

        $response = $this->client->complete('Analyze this document');

        $this->assertSame('{"summary": "Test summary"}', $response->content);
        $this->assertSame(100, $response->inputTokens);
        $this->assertSame(20, $response->outputTokens);
        $this->assertSame('claude-test-model-20241201', $response->model);
    }

    public function test_complete_sends_correct_headers_and_body(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage'   => ['input_tokens' => 10, 'output_tokens' => 5],
                'model'   => 'claude-test-model',
            ], 200),
        ]);

        $this->client->complete('Test prompt');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->header('x-api-key')[0] === 'test-key'
                && $request->header('anthropic-version')[0] === '2023-06-01'
                && $request['model'] === 'claude-test-model'
                && $request['max_tokens'] === 1024
                && $request['messages'][0]['role'] === 'user'
                && $request['messages'][0]['content'] === 'Test prompt';
        });
    }

    public function test_complete_omits_tools_by_default(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage'   => ['input_tokens' => 10, 'output_tokens' => 5],
                'model'   => 'claude-test-model',
            ], 200),
        ]);

        $this->client->complete('Test prompt');

        Http::assertSent(fn (Request $request) => ! isset($request['tools']));
    }

    public function test_complete_sends_web_search_tool_when_option_enabled(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage'   => ['input_tokens' => 10, 'output_tokens' => 5],
                'model'   => 'claude-test-model',
            ], 200),
        ]);

        $this->client->complete('Test prompt', ['web_search' => true]);

        Http::assertSent(fn (Request $request) =>
            isset($request['tools'])
            && $request['tools'][0]['type'] === 'web_search_20250305'
            && $request['tools'][0]['name'] === 'web_search'
        );
    }

    public function test_complete_concatenates_multiple_text_blocks(): void
    {
        // Web-search responses interleave server_tool_use / web_search_tool_result
        // blocks with text blocks — only the text blocks form the answer.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'server_tool_use', 'id' => 'tu_1', 'name' => 'web_search', 'input' => ['query' => 'q']],
                    ['type' => 'web_search_tool_result', 'tool_use_id' => 'tu_1', 'content' => []],
                    ['type' => 'text', 'text' => 'First part.'],
                    ['type' => 'text', 'text' => 'Second part.'],
                ],
                'usage'   => ['input_tokens' => 10, 'output_tokens' => 5],
                'model'   => 'claude-test-model',
            ], 200),
        ]);

        $response = $this->client->complete('Test prompt', ['web_search' => true]);

        $this->assertSame("First part.\nSecond part.", $response->content);
    }

    public function test_complete_throws_ai_provider_exception_on_api_failure(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->expectException(AIProviderException::class);

        $this->client->complete('Test prompt');
    }

    public function test_complete_throws_ai_provider_exception_on_connection_failure(): void
    {
        Http::fake([
            'api.anthropic.com/*' => fn() => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
        ]);

        $this->expectException(AIProviderException::class);

        $this->client->complete('Test prompt');
    }
}
