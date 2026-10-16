<?php

namespace App\Services;

use App\Exceptions\AiServiceException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the Python AI service (scripts/main.py).
 */
class AiGateway
{
    /**
     * One structured call: the model decides whether to answer, create or update
     * components, and the service returns complete files for each.
     *
     * With $onReply, the reply text is streamed: the callback receives each new
     * piece of it as the model writes it, before the files are ready.
     *
     * @param array{
     *     message: string,
     *     history?: list<array{role: string, content: string, feedback?: ?string}>,
     *     components?: list<array{name: string, route: string, php: string, blade: string}>,
     *     focus_component?: ?string,
     *     selection?: ?string,
     *     errors?: list<string>,
     * } $payload
     * @param callable(string): void|null $onReply
     * @return array{
     *     reply: string,
     *     actions: list<array{op: string, component: string, summary: string, php: string, blade: string}>,
     *     warnings: list<string>,
     *     model: string,
     *     usage: array<string, int>,
     *     timings: array<string, int>,
     *     calls: int,
     * }
     */
    public function generate(array $payload, ?callable $onReply = null): array
    {
        $startedAt = microtime(true);

        try {
            $data = $onReply ? $this->stream($payload, $onReply) : $this->decode($this->client()->post('/generate', $payload));
        } catch (ConnectionException $e) {
            Log::error('[AI] Service unreachable', ['error' => $e->getMessage()]);
            throw new AiServiceException('The AI service is not reachable. Please try again in a moment.', previous: $e);
        }

        Log::info('[AI] Generation finished', [
            'seconds' => round(microtime(true) - $startedAt, 1),
            'model' => $data['model'] ?? null,
            'actions' => array_column($data['actions'] ?? [], 'component'),
            'usage' => $data['usage'] ?? [],
            'timings' => $data['timings'] ?? [],
            'calls' => $data['calls'] ?? 1,
            'repair' => !empty($payload['errors']),
        ]);

        return $data;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim(config('skylarr.ai.url'), '/'))
            ->when(config('skylarr.ai.token'), fn ($request, $token) => $request->withToken($token))
            ->timeout(config('skylarr.ai.timeout'))
            ->acceptJson();
    }

    private function decode(Response $response): array
    {
        if ($response->failed()) {
            $detail = $response->json('detail');
            Log::error('[AI] Request failed', ['status' => $response->status(), 'detail' => $detail ?? $response->body()]);

            throw new AiServiceException(is_string($detail) ? $detail : "The AI service returned HTTP {$response->status()}.");
        }

        return $response->json();
    }

    /**
     * Read the newline-delimited JSON events of /generate/stream.
     */
    private function stream(array $payload, callable $onReply): array
    {
        $response = $this->client()->withOptions(['stream' => true])->post('/generate/stream', $payload);
        if ($response->failed()) {
            return $this->decode($response);
        }

        // Read line by line: a large read() blocks on PHP's dechunked HTTP stream
        // until the buffer fills, which would hold back the streamed reply.
        $body = $response->toPsrResponse()->getBody();

        while (!$body->eof()) {
            $event = json_decode(trim(Utils::readLine($body)), true);

            match ($event['type'] ?? null) {
                'reply' => $onReply($event['text']),
                'done' => $done = $event['response'],
                'error' => throw new AiServiceException($event['detail'] ?? 'The AI service failed.'),
                default => null,
            };
            if (isset($done)) {
                return $done;
            }
        }

        throw new AiServiceException('The AI service closed the connection before finishing.');
    }
}
