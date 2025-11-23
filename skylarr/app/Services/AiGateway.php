<?php

namespace App\Services;

use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * @param array<int, array{role:string, content:string}> $conversationHistory Conversation history for context
     * @return array{success: bool, code?: string, component_name?: string, message?: string}
     */
    public function generateCode(string $prompt, array $conversationHistory = []): array
    {
        $baseUrl = config('services.aigateway.url', env('PY_BACKEND_URL', 'http://127.0.0.1:8001'));
        
        Log::info('[AI_GATEWAY] Starting code generation', [
            'url' => $baseUrl,
            'prompt_length' => strlen($prompt),
            'history_count' => count($conversationHistory)
        ]);
        
        try {
            $response = Http::timeout(60)->post(rtrim($baseUrl, '/') . '/generate/code', [
                'prompt' => $prompt,
                'messages' => $conversationHistory, // Pass conversation history for context
                'model' => config('services.openai.model', 'gpt-4'),
                'temperature' => 0.1,
                'max_tokens' => 4096, // Increased for complete code with views
            ]);

            Log::info('[AI_GATEWAY] HTTP request completed', [
                'status' => $response->status(),
                'success' => $response->successful()
            ]);

            if ($response->failed()) {
                Log::error('[AI_GATEWAY] HTTP request failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                
                return [
                    'success' => false,
                    'message' => 'AI backend request failed: ' . $response->body()
                ];
            }

            $data = $response->json();
            
            Log::info('[AI_GATEWAY] Response parsed', [
                'has_code' => isset($data['code']),
                'has_component_name' => isset($data['component_name'])
            ]);
            
            if (isset($data['code']) && isset($data['component_name'])) {
                Log::info('[AI_GATEWAY] Code generated successfully', [
                    'component_name' => $data['component_name'],
                    'code_length' => strlen($data['code'])
                ]);
                
                return [
                    'success' => true,
                    'code' => $data['code'],
                    'component_name' => $data['component_name']
                ];
            }

            Log::error('[AI_GATEWAY] Invalid response format', ['data' => $data]);

            return [
                'success' => false,
                'message' => 'Invalid response format from AI backend'
            ];

        } catch (\Exception $e) {
            Log::error('[AI_GATEWAY] Exception during code generation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Error generating code: ' . $e->getMessage()
            ];
        }
    }
}


