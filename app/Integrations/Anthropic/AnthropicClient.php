<?php

namespace App\Integrations\Anthropic;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Minimal Messages API client that asks for JSON back. */
class AnthropicClient
{
    public function __construct(private array $config) {}

    /**
     * @param  array<int, array<string, mixed>>  $content  Messages API content blocks
     */
    public function json(array $content, string $system, int $maxTokens = 1024): array
    {
        if (empty($this->config['key'])) {
            throw new RuntimeException('Set ANTHROPIC_API_KEY to use the Anthropic drivers.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->config['key'],
            'anthropic-version' => '2023-06-01',
        ])->acceptJson()->timeout(60)->retry(2, 1000, throw: false)->post($this->config['url'], [
            'model' => $this->config['model'],
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $content]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic request failed: '.($response->json('error.message') ?? $response->status()));
        }

        $text = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");

        return self::extractJson($text);
    }

    public static function extractJson(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false) {
            throw new RuntimeException('Anthropic did not return JSON.');
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        if (! is_array($data)) {
            throw new RuntimeException('Anthropic returned invalid JSON.');
        }

        return $data;
    }
}
