<?php
declare(strict_types=1);

namespace App\Modules\Assistant;

/**
 * Minimal client for OpenAI-compatible chat APIs (Open WebUI, Ollama /v1, LM Studio, vLLM...).
 * The API key stays on the server; the browser never talks to the model directly.
 */
class AiClient
{
    private string $base;
    private string $key;
    private int $timeout;

    public function __construct(?array $cfg = null)
    {
        $cfg ??= config('ai');
        $this->base = rtrim((string) $cfg['base_url'], '/');
        $this->key = (string) $cfg['api_key'];
        $this->timeout = max(5, (int) $cfg['timeout']);
    }

    /** @param array<int, array{role: string, content: string}> $messages */
    public function chat(array $messages, string $model, float $temperature = 0.1): string
    {
        $data = $this->request('POST', '/chat/completions', [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'stream'      => false,
        ]);
        $content = $data['choices'][0]['message']['content'] ?? ($data['message']['content'] ?? null);
        if (!is_string($content)) {
            throw new \RuntimeException('Resposta inesperada da IA (sem "choices[0].message.content").');
        }
        // Reasoning models (deepseek-r1, qwq...) prepend their chain of thought
        return trim((string) preg_replace('#<think>.*?</think>#s', '', $content));
    }

    /** @return string[] model ids */
    public function models(): array
    {
        $data = $this->request('GET', '/models');
        $list = $data['data'] ?? $data['models'] ?? [];
        return array_values(array_filter(array_map(static fn($m) => is_array($m) ? ($m['id'] ?? $m['name'] ?? null) : $m, $list)));
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        if (!preg_match('#^https?://#i', $this->base)) {
            throw new \RuntimeException('AI_BASE_URL inválido (tem de começar por http:// ou https://).');
        }
        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($this->key !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->key;
        }
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => (bool) config('ai.verify_tls', true),
            CURLOPT_SSL_VERIFYHOST => config('ai.verify_tls', true) ? 2 : 0,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException("Não foi possível contactar a IA em {$this->base}: $err");
        }
        $data = json_decode((string) $raw, true);
        if ($status >= 400) {
            $msg = is_array($data) ? ($data['detail'] ?? $data['error']['message'] ?? $data['error'] ?? null) : null;
            $msg = is_string($msg) ? $msg : mb_substr(trim(strip_tags((string) $raw)), 0, 300);
            $hint = $status === 401 || $status === 403 ? ' — verifique o AI_API_KEY.' : ($status === 404 ? ' — verifique o AI_BASE_URL e o AI_MODEL.' : '');
            throw new \RuntimeException("A IA respondeu HTTP $status: $msg$hint");
        }
        if (!is_array($data)) {
            throw new \RuntimeException('A IA devolveu uma resposta que não é JSON (verifique o AI_BASE_URL; no Open WebUI termina em /api).');
        }
        return $data;
    }
}
