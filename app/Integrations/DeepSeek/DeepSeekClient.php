<?php

namespace App\Integrations\DeepSeek;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal client for DeepSeek's OpenAI-compatible chat completions API that
 * asks for JSON back. deepseek-flash accepts images, so one model reads
 * passports and understands trip messages.
 *
 * Content blocks are provider-neutral:
 *   ['type' => 'text', 'text' => '...']
 *   ['type' => 'image', 'mime' => 'image/jpeg', 'data' => '<base64>']
 *
 * Any OpenAI-compatible endpoint works by changing DEEPSEEK_URL and the model.
 */
class DeepSeekClient
{
    public function __construct(private array $config) {}

    /** @param array<int, array<string, mixed>> $content */
    public function json(array $content, string $system, int $maxTokens = 1024): array
    {
        if (empty($this->config['key'])) {
            throw new RuntimeException('Set DEEPSEEK_API_KEY to use the DeepSeek drivers.');
        }

        $response = Http::withToken($this->config['key'])
            ->acceptJson()->timeout(90)->retry(2, 1500, throw: false)
            ->post(rtrim($this->config['url'], '/').'/chat/completions', [
                'model' => $this->config['model'],
                'max_tokens' => $maxTokens,
                // Extraction needs no chain of thought; thinking is on by default and slower.
                'thinking' => ['type' => 'disabled'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => array_map([self::class, 'block'], $content)],
                ],
            ]);

        if ($response->failed() || $response->json('error')) {
            $message = $response->json('error.message') ?? 'HTTP '.$response->status();
            throw new RuntimeException('DeepSeek request failed: '.$message);
        }

        $text = $response->json('choices.0.message.content');
        if (is_array($text)) { // some providers return content parts
            $text = collect($text)->pluck('text')->implode("\n");
        }

        return self::extractJson((string) $text);
    }

    private static function block(array $b): array
    {
        if (($b['type'] ?? '') === 'image') {
            return ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$b['mime'].';base64,'.$b['data']]];
        }

        return ['type' => 'text', 'text' => (string) ($b['text'] ?? '')];
    }

    /** Models sometimes wrap JSON in prose, code fences or <think> blocks. */
    public static function extractJson(string $text): array
    {
        $text = preg_replace('#<think>.*?</think>#s', '', $text);
        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text)));
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false) {
            throw new RuntimeException('The model did not return JSON.');
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        if (! is_array($data)) {
            throw new RuntimeException('The model returned invalid JSON.');
        }

        return $data;
    }
}
