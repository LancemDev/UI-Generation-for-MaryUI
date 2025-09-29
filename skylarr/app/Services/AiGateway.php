<?php

namespace App\Services;

use Generator;
use Illuminate\Support\Facades\Http;

class AiGateway
{
    /**
     * Stream assistant response from the Python FastAPI service.
     *
     * @param array<int, array{role:string, content:string}> $messages
     * @return Generator<string>
     */
    public function streamChat(array $messages): Generator
    {
        $baseUrl = config('services.aigateway.url', env('PY_BACKEND_URL', 'http://127.0.0.1:8000'));
        $response = Http::withHeaders([
            'Accept' => 'text/event-stream',
        ])->withOptions([
            'stream' => true,
        ])->post(rtrim($baseUrl, '/') . '/chat/stream', [
            'messages' => $messages,
            'model' => config('services.openai.model'),
            'temperature' => 0.2,
            'max_tokens' => 1024,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('AI backend request failed: ' . $response->body());
        }

        $body = $response->body();
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $body);
        rewind($handle);
        while (!feof($handle)) {
            $chunk = fread($handle, 1024);
            if ($chunk !== false && $chunk !== '') {
                yield $chunk;
            }
        }
        fclose($handle);
    }
}


