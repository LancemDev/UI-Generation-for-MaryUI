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
        $baseUrl = config('services.aigateway.url', env('PY_BACKEND_URL', 'http://127.0.0.1:8001'));
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

    /**
     * Generate code using the Python FastAPI service.
     *
     * @param string $prompt
     * @return array{success: bool, code?: string, component_name?: string, message?: string}
     */
    public function generateCode(string $prompt): array
    {
        $baseUrl = config('services.aigateway.url', env('PY_BACKEND_URL', 'http://127.0.0.1:8000'));
        
        try {
            $response = Http::timeout(30)->post(rtrim($baseUrl, '/') . '/generate/code', [
                'prompt' => $prompt,
                'model' => config('services.openai.model', 'gpt-4'),
                'temperature' => 0.1,
                'max_tokens' => 2048,
            ]);

            if ($response->failed()) {
                return [
                    'success' => false,
                    'message' => 'AI backend request failed: ' . $response->body()
                ];
            }

            $data = $response->json();
            
            if (isset($data['code']) && isset($data['component_name'])) {
                return [
                    'success' => true,
                    'code' => $data['code'],
                    'component_name' => $data['component_name']
                ];
            }

            return [
                'success' => false,
                'message' => 'Invalid response format from AI backend'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error generating code: ' . $e->getMessage()
            ];
        }
    }
}


