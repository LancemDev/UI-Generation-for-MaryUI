<?php

namespace Tests\Feature;

use App\Exceptions\AiServiceException;
use App\Services\AiGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiGatewayTest extends TestCase
{
    public function test_stream_delivers_reply_parts_then_the_response(): void
    {
        $done = ['reply' => 'Hello there', 'actions' => [], 'warnings' => [], 'model' => 'm', 'usage' => [], 'timings' => [], 'calls' => 1];
        Http::fake(['*/generate/stream' => Http::response(implode("\n", [
            json_encode(['type' => 'reply', 'text' => 'Hello ']),
            json_encode(['type' => 'reply', 'text' => 'there']),
            json_encode(['type' => 'done', 'response' => $done]),
        ]) . "\n")]);

        $parts = [];
        $response = app(AiGateway::class)->generate(['message' => 'hi'], function (string $text) use (&$parts) {
            $parts[] = $text;
        });

        $this->assertSame(['Hello ', 'there'], $parts);
        $this->assertSame($done, $response);
    }

    public function test_stream_errors_become_service_exceptions(): void
    {
        Http::fake(['*/generate/stream' => Http::response(json_encode(['type' => 'error', 'status' => 502, 'detail' => 'upstream down']) . "\n")]);

        $this->expectException(AiServiceException::class);
        $this->expectExceptionMessage('upstream down');

        app(AiGateway::class)->generate(['message' => 'hi'], fn () => null);
    }

    public function test_stream_cut_short_is_an_error(): void
    {
        Http::fake(['*/generate/stream' => Http::response(json_encode(['type' => 'reply', 'text' => 'Hel']))]);

        $this->expectException(AiServiceException::class);
        $this->expectExceptionMessage('closed the connection');

        app(AiGateway::class)->generate(['message' => 'hi'], fn () => null);
    }
}
