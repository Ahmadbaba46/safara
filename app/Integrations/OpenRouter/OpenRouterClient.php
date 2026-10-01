<?php

namespace App\Integrations\OpenRouter;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal OpenRouter chat-completions client that asks for JSON back.
 *
 * Content blocks are provider-neutral:
 *   ['type' => 'text', 'text' => '...']
 *   ['type' => 'image', 'mime' => 'image/jpeg', 'data' => '<base64>']
 *
 * Pass a list of models and OpenRouter falls back down the list when one is
 * rate-limited or unavailable, which matters a lot for ":free" models.
 */
class OpenRouterClient
{
    public function __construct(private array $config) {}

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @param  string[]  $models  first is preferred, the rest are fallbacks
     */
    public function json(array $content, string $system, array $models, int $maxTokens = 1024): array
    {
        if (empty($this->config['key'])) {
            throw new RuntimeException('Set OPENROUTER_API_KEY to use the OpenRouter drivers.');
        }
        $models = array_values(array_filter($models));
        if (! $models) {
            throw new RuntimeException('No OpenRouter model is configured.');
        }

        $response = Http::withToken($this->config['key'])
            ->withHeaders(['HTTP-Referer' => (string) config('app.url'), 'X-Title' => (string) config('safara.brand')])
            ->acceptJson()->timeout(90)->retry(2, 1500, throw: false)
            ->post(rtrim($this->config['url'], '/').'/chat/completions', [
                'model' => $models[0],
                // OpenRouter accepts a fallback list; it is capped, so send at most 3.
                'models' => array_slice($models, 0, 3),
                'max_tokens' => $maxTokens,
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => array_map([self::class, 'block'], $content)],
                ],
            ]);

        if ($response->failed() || $response->json('error')) {
            $message = $response->json('error.message') ?? 'HTTP '.$response->status();
            throw new RuntimeException('OpenRouter request failed: '.$message);
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

    /** Models often wrap JSON in prose, code fences or <think> blocks. */
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
