<?php

namespace App\Services;

use App\Exceptions\AiServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the Python AI service (scripts/main.py).
 */
class AiGateway
{
    /**
     * One structured call: the model decides whether to answer, create or update
     * components, and returns complete files for each.
     *
     * @param array{
     *     message: string,
     *     history?: list<array{role: string, content: string, feedback?: ?string}>,
     *     components?: list<array{name: string, route: string, php: string, blade: string}>,
     *     focus_component?: ?string,
     *     errors?: list<string>,
     * } $payload
     * @return array{
     *     reply: string,
     *     actions: list<array{op: string, component: string, summary: string, php: string, blade: string}>,
     *     warnings: list<string>,
     *     model: string,
     *     usage: array<string, int>,
     * }
     */
    public function generate(array $payload): array
    {
        $startedAt = microtime(true);

        try {
            $response = Http::baseUrl(rtrim(config('skylarr.ai.url'), '/'))
                ->when(config('skylarr.ai.token'), fn ($request, $token) => $request->withToken($token))
                ->timeout(config('skylarr.ai.timeout'))
                ->acceptJson()
                ->post('/generate', $payload);
        } catch (ConnectionException $e) {
            Log::error('[AI] Service unreachable', ['error' => $e->getMessage()]);
            throw new AiServiceException('The AI service is not reachable. Please try again in a moment.', previous: $e);
        }

        if ($response->failed()) {
            $detail = $response->json('detail');
            Log::error('[AI] Request failed', ['status' => $response->status(), 'detail' => $detail ?? $response->body()]);

            throw new AiServiceException(is_string($detail) ? $detail : "The AI service returned HTTP {$response->status()}.");
        }

        $data = $response->json();

        Log::info('[AI] Generation finished', [
            'seconds' => round(microtime(true) - $startedAt, 1),
            'model' => $data['model'] ?? null,
            'actions' => array_column($data['actions'] ?? [], 'component'),
            'usage' => $data['usage'] ?? [],
            'repair' => !empty($payload['errors']),
        ]);

        return $data;
    }
}
