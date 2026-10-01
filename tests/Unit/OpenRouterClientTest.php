<?php

namespace Tests\Unit;

use App\Integrations\OpenRouter\OpenRouterClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OpenRouterClientTest extends TestCase
{
    private function client(?string $key = 'sk-or-test'): OpenRouterClient
    {
        return new OpenRouterClient(['key' => $key, 'url' => 'https://openrouter.ai/api/v1']);
    }

    #[Test]
    public function it_sends_images_and_fallback_models_and_parses_messy_json(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => "<think>hmm</think>Sure!\n```json\n{\"surname\": \"BELLO\"}\n```"]]]])]);

        $out = $this->client()->json([
            ['type' => 'image', 'mime' => 'image/png', 'data' => 'QUJD'],
            ['type' => 'text', 'text' => 'Read this.'],
        ], 'system prompt', ['a/free:free', 'b/free:free'], 100);

        $this->assertSame(['surname' => 'BELLO'], $out);
        Http::assertSent(function (Request $r) {
            return $r->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $r->hasHeader('Authorization', 'Bearer sk-or-test')
                && $r['model'] === 'a/free:free'
                && $r['models'] === ['a/free:free', 'b/free:free']
                && $r['messages'][0] === ['role' => 'system', 'content' => 'system prompt']
                && $r['messages'][1]['content'][0] === ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,QUJD']]
                && $r['messages'][1]['content'][1] === ['type' => 'text', 'text' => 'Read this.'];
        });
    }

    #[Test]
    public function it_reports_api_errors_and_missing_keys(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded');
        $this->client()->json([['type' => 'text', 'text' => 'x']], 's', ['m:free']);
    }

    #[Test]
    public function it_needs_a_key(): void
    {
        $this->expectExceptionMessage('OPENROUTER_API_KEY');
        $this->client(null)->json([['type' => 'text', 'text' => 'x']], 's', ['m:free']);
    }
}
